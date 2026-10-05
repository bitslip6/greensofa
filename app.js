'use strict';

function buildWebSocketUrl() {
  const secure = window.location.protocol === 'https:';
  const scheme = secure ? 'wss' : 'ws';
  const rawHostname = window.location.hostname || '127.0.0.1';
  const hostname = rawHostname.includes(':') && !rawHostname.startsWith('[')
    ? `[${rawHostname}]`
    : rawHostname;
  const port = secure ? '' : ':9502';
  return `${scheme}://${hostname}${port}/lobby`;
}

const CONFIG = Object.freeze({
  websocketUrl: buildWebSocketUrl(),
  room: 'general',
  registrationTimeoutMs: 10000,
  reconnectBaseDelayMs: 1000,
  reconnectMaxDelayMs: 15000,
  maxReconnectAttempts: 8,
  maxLogEntries: 100,
  maxMessages: 200,
  identityTokenStorageKey: 'checkerboardChase.identityToken.v1',
});

const state = {
  socket: null,
  socketGeneration: 0,
  username: '',
  pendingUsername: '',
  identityToken: '',
  registered: false,
  registering: false,
  manuallyClosed: false,
  reconnectAttempts: 0,
  reconnectTimer: null,
  registrationTimer: null,
  lastConnectionEvent: 'Client starting',
  players: [],
  games: [],
  matches: [],
  gameState: { type: 'game_state', match: null },
};

const elements = {};

window.addEventListener('DOMContentLoaded', init);

function init() {
  Object.assign(elements, {
    gameSelect: document.querySelector('#gameSelect'),
    createGameButton: document.querySelector('#createGameButton'),
    leaveGameButton: document.querySelector('#leaveGameButton'),
    matchList: document.querySelector('#matchList'),
    gameStatus: document.querySelector('#gameStatus'),
    gameFrame: document.querySelector('#gameFrame'),
    connectionStatus: document.querySelector('#connectionStatus'),
    connectionStatusText: document.querySelector('#connectionStatusText'),
    lobby: document.querySelector('#lobby'),
    playerList: document.querySelector('#playerList'),
    playerCount: document.querySelector('#playerCount'),
    messageList: document.querySelector('#messageList'),
    messageForm: document.querySelector('#messageForm'),
    messageInput: document.querySelector('#messageInput'),
    sendButton: document.querySelector('#sendButton'),
    usernameModal: document.querySelector('#usernameModal'),
    usernameForm: document.querySelector('#usernameForm'),
    usernameInput: document.querySelector('#usernameInput'),
    usernameField: document.querySelector('#usernameField'),
    usernameError: document.querySelector('#usernameError'),
    usernameSpinner: document.querySelector('#usernameSpinner'),
    joinButton: document.querySelector('#joinButton'),
    joinButtonText: document.querySelector('#joinButtonText'),
    modalStatus: document.querySelector('#modalStatus'),
    modalStatusDot: document.querySelector('#modalStatusDot'),
    debugLog: document.querySelector('#debugLog'),
    debugEndpoint: document.querySelector('#debugEndpoint'),
    diagnosticEndpoint: document.querySelector('#diagnosticEndpoint'),
    diagnosticPage: document.querySelector('#diagnosticPage'),
    diagnosticSocketState: document.querySelector('#diagnosticSocketState'),
    diagnosticNetworkState: document.querySelector('#diagnosticNetworkState'),
    diagnosticLastEvent: document.querySelector('#diagnosticLastEvent'),
    diagnosticSocketBadge: document.querySelector('#diagnosticSocketBadge'),
    modalDebugLog: document.querySelector('#modalDebugLog'),
    copyDiagnosticsButton: document.querySelector('#copyDiagnosticsButton'),
    clearLogsButton: document.querySelector('#clearLogsButton'),
    toastRegion: document.querySelector('#toastRegion'),
  });

  elements.createGameButton.addEventListener('click', () => sendGameAction({ type: 'game_create', game: elements.gameSelect.value }));
  elements.leaveGameButton.addEventListener('click', () => sendGameAction({ type: 'game_leave' }));
  elements.gameSelect.addEventListener('change', renderGames);
  elements.gameFrame.addEventListener('load', postGameState);
  window.addEventListener('message', handleGameFrameMessage);
  elements.usernameForm.addEventListener('submit', handleRegistrationSubmit);
  elements.usernameInput.addEventListener('input', clearUsernameError);
  elements.messageForm.addEventListener('submit', handleMessageSubmit);
  elements.clearLogsButton.addEventListener('click', (event) => {
    event.preventDefault();
    elements.debugLog.replaceChildren();
    elements.modalDebugLog.replaceChildren();
    debugLog('info', 'Debug log cleared.');
  });
  elements.copyDiagnosticsButton.addEventListener('click', copyConnectionDiagnostics);
  window.addEventListener('online', handleBrowserOnline);
  window.addEventListener('offline', handleBrowserOffline);
  window.addEventListener('beforeunload', closeSocket);

  elements.debugEndpoint.textContent = CONFIG.websocketUrl;
  elements.diagnosticEndpoint.textContent = CONFIG.websocketUrl;
  elements.diagnosticPage.textContent = `${window.location.origin}${window.location.pathname}`;
  updateConnectionDiagnostics('NOT CREATED', 'Client initialized');
  debugLog('info', `Lobby client initialized. Browser is ${navigator.onLine ? 'online' : 'offline'}.`);
  debugLog('info', `WebSocket endpoint derived from page host: ${CONFIG.websocketUrl}`);
  try {
    state.identityToken = getOrCreateIdentityToken();
  } catch (error) {
    debugLog('error', 'Could not create a secure browser identity token.', error);
    setUsernameError('This browser cannot create a secure player identity.');
    setRegistrationControls(false, 'Browser unsupported');
    return;
  }
  connect();
  window.requestAnimationFrame(() => elements.usernameInput.focus());
}

function connect() {
  if (!navigator.onLine) {
    setConnectionStatus('offline', 'Browser offline');
    setModalConnection('offline', 'No internet connection. Waiting to reconnect…');
    updateConnectionDiagnostics('OFFLINE', 'Browser reports that the device is offline');
    return;
  }

  if (state.socket && [WebSocket.CONNECTING, WebSocket.OPEN].includes(state.socket.readyState)) {
    debugLog('info', 'Connection request ignored: a socket is already connecting/open.');
    return;
  }

  clearTimeout(state.reconnectTimer);
  state.manuallyClosed = false;
  const generation = ++state.socketGeneration;
  setConnectionStatus('connecting', state.reconnectAttempts ? `Reconnecting (${state.reconnectAttempts})…` : 'Connecting…');
  setModalConnection('connecting', state.reconnectAttempts ? 'Reconnecting to the lobby server…' : 'Connecting to the lobby server…');
  setRegistrationControls(false, state.registering ? 'Waiting for server…' : 'Connecting…');
  updateConnectionDiagnostics('CONNECTING', `Opening ${CONFIG.websocketUrl}`);
  debugLog('info', `Opening WebSocket: ${CONFIG.websocketUrl}`);

  try {
    state.socket = new WebSocket(CONFIG.websocketUrl);
  } catch (error) {
    debugLog('error', 'WebSocket constructor failed.', error);
    updateConnectionDiagnostics('ERROR', `WebSocket constructor failed: ${error.message || error}`);
    setConnectionStatus('offline', 'Connection failed');
    setModalConnection('offline', 'Could not connect to the lobby server.');
    scheduleReconnect();
    return;
  }

  state.socket.addEventListener('open', () => {
    if (generation !== state.socketGeneration) return;
    state.reconnectAttempts = 0;
    updateConnectionDiagnostics('OPEN', 'WebSocket connection opened successfully');
    debugLog('info', 'WebSocket connected successfully.');
    setConnectionStatus('online', state.registered ? `Online as ${state.username}` : 'Server connected');
    setModalConnection('online', 'Connected. Choose a player name!');
    setRegistrationControls(true, 'Enter the lobby');

    // On reconnection, reclaim the accepted username automatically.
    if (state.username && !state.registered) {
      sendRegistration(state.username, true);
    } else if (state.pendingUsername && state.registering) {
      sendRegistration(state.pendingUsername);
    }
  });

  state.socket.addEventListener('message', (event) => {
    if (generation !== state.socketGeneration) return;
    handleSocketMessage(event.data);
  });

  state.socket.addEventListener('error', (event) => {
    if (generation !== state.socketGeneration) return;
    // Browsers intentionally expose little detail for WebSocket errors.
    updateConnectionDiagnostics('ERROR', 'Browser reported a WebSocket error; waiting for close code');
    debugLog('error', 'WebSocket error event received. Check the endpoint, server listener, firewall, and mixed-content rules.', event);
    setConnectionStatus('offline', 'Connection error');
    setModalConnection('offline', 'Lobby connection error. Retrying soon…');
    showToast('Could not reach the lobby server.', 'error');
  });

  state.socket.addEventListener('close', (event) => {
    if (generation !== state.socketGeneration) return;
    clearTimeout(state.registrationTimer);
    const reason = event.reason || 'No reason supplied';
    updateConnectionDiagnostics('CLOSED', `Close code ${event.code}; clean=${event.wasClean}; reason=${reason}`);
    debugLog(event.wasClean ? 'info' : 'error', `WebSocket closed (code=${event.code}, clean=${event.wasClean}, reason=${reason}).`);
    state.registered = false;
    state.registering = Boolean(state.username || state.pendingUsername);
    disableChat();

    if (!state.manuallyClosed) {
      setConnectionStatus('offline', 'Disconnected');
      setModalConnection('offline', 'Disconnected from the lobby. Reconnecting…');
      showModal();
      scheduleReconnect();
    }
  });
}

function sendRegistration(username, isReconnect = false) {
  if (!isSocketOpen()) {
    state.pendingUsername = username;
    state.registering = true;
    setUsernameError('The server is not connected yet. We will retry when it reconnects.');
    debugLog('error', `Registration for “${username}” deferred: WebSocket is not open.`);
    return;
  }

  state.pendingUsername = username;
  state.registering = true;
  setRegistrationControls(false, isReconnect ? 'Rejoining…' : 'Checking name…', true);
  setModalConnection('connecting', isReconnect ? `Reclaiming ${username}…` : `Checking whether ${username} is available…`);
  sendPayload({ type: 'register', username, token: state.identityToken });

  clearTimeout(state.registrationTimer);
  state.registrationTimer = window.setTimeout(() => {
    if (!state.registering) return;
    state.registering = false;
    setRegistrationControls(isSocketOpen(), 'Try again');
    setModalConnection(isSocketOpen() ? 'online' : 'offline', isSocketOpen() ? 'Connected, but registration timed out.' : 'Disconnected from the lobby server.');
    setUsernameError('The server did not answer the registration request. Please try again.');
    debugLog('error', `Registration timed out after ${CONFIG.registrationTimeoutMs}ms.`);
  }, CONFIG.registrationTimeoutMs);
}

function handleRegistrationSubmit(event) {
  event.preventDefault();
  const username = elements.usernameInput.value.trim();
  clearUsernameError();

  const validationError = validateUsername(username);
  if (validationError) {
    setUsernameError(validationError);
    return;
  }
  if (!isSocketOpen()) {
    setUsernameError('Not connected to the lobby server. Please wait for reconnection.');
    debugLog('error', 'Registration submit blocked: WebSocket is not open.');
    return;
  }

  sendRegistration(username);
}

function validateUsername(username) {
  if (username.length < 2 || username.length > 20) return 'Player names must be between 2 and 20 characters.';
  if (!/^[A-Za-z0-9_-]+$/.test(username)) return 'Use only letters, numbers, underscores, and hyphens.';
  return '';
}

function handleSocketMessage(rawData) {
  debugLog('in', 'Received', rawData);
  let data;
  try {
    data = JSON.parse(rawData);
  } catch (error) {
    debugLog('error', 'Could not parse incoming message as JSON.', error);
    showToast('The server sent an unreadable response.', 'error');
    return;
  }

  if (!data || typeof data !== 'object') {
    debugLog('error', 'Ignored incoming payload: expected a JSON object.', data);
    return;
  }

  switch (data.type) {
    case 'register':
    case 'registered':
    case 'registration':
      handleRegistrationResponse(data);
      break;
    case 'players':
    case 'player_list':
    case 'playerList':
      handlePlayerList(data);
      break;
    case 'games':
      state.games = data.games;
      state.matches = data.matches;
      renderGames();
      break;
    case 'game_state':
      state.gameState = data;
      renderGames();
      postGameState();
      break;
    case 'history':
      for (const message of data.messages || []) handleIncomingMessage(message);
      break;
    case 'message':
      handleIncomingMessage(data);
      break;
    case 'error':
      handleServerError(data);
      break;
    case 'player_joined':
    case 'join':
      handlePlayerJoined(data);
      break;
    case 'player_left':
    case 'leave':
      handlePlayerLeft(data);
      break;
    case 'ping':
      sendPayload({ type: 'pong' });
      break;
    default:
      debugLog('info', `No handler for server message type “${String(data.type)}”.`, data);
  }
}

function handleRegistrationResponse(data) {
  clearTimeout(state.registrationTimer);
  // Accept common acknowledgment fields while keeping `available` authoritative when present.
  const accepted = data.available === true || data.success === true || data.accepted === true || data.registered === true || data.status === 'ok';
  const rejected = data.available === false || data.success === false || data.accepted === false || data.registered === false || data.status === 'error';

  if (!accepted && !rejected) {
    debugLog('error', 'Registration response did not contain an availability result.', data);
    state.registering = false;
    setRegistrationControls(true, 'Try again');
    setUsernameError('The server returned an unexpected registration response.');
    return;
  }

  if (accepted) {
    state.username = String(data.username || data.name || state.pendingUsername).trim();
    state.pendingUsername = '';
    state.registered = true;
    state.registering = false;
    elements.usernameInput.value = state.username;
    elements.lobby.setAttribute('aria-busy', 'false');
    hideModal();
    enableChat();
    setConnectionStatus('online', `Online as ${state.username}`);
    addSystemMessage(`You joined #${CONFIG.room} as ${state.username}.`);
    debugLog('info', `Registration accepted for “${state.username}”.`);
    elements.messageInput.focus();
    return;
  }

  state.registering = false;
  state.pendingUsername = '';
  const errorMessage = data.message || data.error || 'That player name is already taken. Try another one.';
  setRegistrationControls(true, 'Choose another name');
  setModalConnection('online', 'Connected. Please choose another name.');
  setUsernameError(String(errorMessage));
  elements.usernameInput.select();
  debugLog('error', 'Registration rejected by server.', data);
}

function handlePlayerList(data) {
  const list = Array.isArray(data.players) ? data.players : Array.isArray(data.content) ? data.content : Array.isArray(data.list) ? data.list : null;
  if (!list) {
    debugLog('error', 'Player list payload did not include a players array.', data);
    return;
  }

  state.players = list.map((player) => {
    if (typeof player === 'string') return { name: player };
    return { ...player, name: String(player.name || player.username || player.player || 'Unknown player') };
  });
  renderPlayers();
  debugLog('info', `Rendered ${state.players.length} player(s).`);
}

function handleIncomingMessage(data) {
  const destination = String(data.destination || data.room || CONFIG.room);
  if (destination !== CONFIG.room) {
    debugLog('info', `Ignored message for unsupported destination “${destination}”.`);
    return;
  }

  const content = data.content ?? data.message;
  if (typeof content !== 'string') {
    debugLog('error', 'Message payload did not include string content.', data);
    return;
  }

  const sender = String(data.sender || data.username || data.player || data.from || 'Player');
  addChatMessage(sender, content, data.timestamp || data.time);
}

function handleServerError(data) {
  if (data.requestType === 'game') postGameState();
  const message = String(data.message || data.error || 'The lobby server reported an error.');
  debugLog('error', `Server error: ${message}`, data);
  showToast(message, 'error');
  addSystemMessage(message, true);

  if (state.registering) {
    clearTimeout(state.registrationTimer);
    state.registering = false;
    setRegistrationControls(isSocketOpen(), 'Try again');
    setUsernameError(message);
  }
}

function handlePlayerJoined(data) {
  const name = String(data.name || data.username || data.player || 'A player');
  if (!state.players.some((player) => player.name === name)) {
    state.players.push({ name });
    renderPlayers();
  }
  addSystemMessage(`${name} joined the lobby.`);
}

function handlePlayerLeft(data) {
  const name = String(data.name || data.username || data.player || 'A player');
  state.players = state.players.filter((player) => player.name !== name);
  renderPlayers();
  addSystemMessage(`${name} left the lobby.`);
}

// The parent owns the only socket. Iframes receive public state, never identity tokens.
const GAME_PAGES = Object.freeze({ 'tic-tac-toe': 'games/tic-tac-toe.html' });

function sendGameAction(payload) {
  if (state.registered && isSocketOpen() && navigator.onLine) sendPayload(payload);
}

function renderGames() {
  const connected = state.registered && isSocketOpen() && navigator.onLine;
  const match = state.gameState.match;
  const selected = elements.gameSelect.value;
  elements.gameSelect.replaceChildren();
  for (const game of state.games) {
    if (!GAME_PAGES[game.id]) continue;
    const option = document.createElement('option');
    option.value = game.id;
    option.textContent = game.name;
    elements.gameSelect.append(option);
  }
  if (state.games.some((game) => game.id === selected)) elements.gameSelect.value = selected;
  elements.gameSelect.disabled = !connected || Boolean(match);
  elements.createGameButton.disabled = !connected || Boolean(match) || !elements.gameSelect.value;
  elements.leaveGameButton.disabled = !connected || !match;
  elements.gameStatus.textContent = !connected ? 'Connect and register to play.'
    : match ? `Match ${match.id} — you are ${state.gameState.symbol}. ${match.status === 'waiting' ? 'Waiting for an opponent.' : match.status === 'abandoned' ? 'Opponent left. Leave to start another match.' : match.status === 'finished' ? 'Game over. Leave to start another match.' : 'Game in progress.'}`
      : 'Create a match or join one below.';
  elements.matchList.replaceChildren();
  for (const available of state.matches.filter((item) => item.game === elements.gameSelect.value)) {
    const item = document.createElement('li');
    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = `Join ${available.host}'s match`;
    button.disabled = !connected || Boolean(match);
    button.addEventListener('click', () => sendGameAction({ type: 'game_join', game: available.game, matchId: available.id }));
    item.append(button);
    elements.matchList.append(item);
  }
  const page = match && GAME_PAGES[match.game];
  elements.gameFrame.hidden = !page;
  if (page && elements.gameFrame.getAttribute('src') !== page) elements.gameFrame.src = page;
}

function postGameState() {
  elements.gameFrame.contentWindow?.postMessage({
    channel: 'lobby-game', ...state.gameState,
    connected: state.registered && isSocketOpen() && navigator.onLine,
  }, window.location.origin);
}

function handleGameFrameMessage(event) {
  if (event.origin !== window.location.origin || event.source !== elements.gameFrame.contentWindow) return;
  const data = event.data;
  if (!data || data.channel !== 'lobby-game') return;
  if (data.type === 'ready') {
    postGameState();
  } else if (data.type === 'move' && state.gameState.match && Number.isInteger(data.cell)) {
    sendGameAction({ type: 'game_move', matchId: state.gameState.match.id, cell: data.cell, revision: data.revision });
  }
}

function handleMessageSubmit(event) {
  event.preventDefault();
  const content = elements.messageInput.value.trim();
  if (!content) return;
  if (!state.registered || !isSocketOpen()) {
    showToast('You are not connected to the lobby.', 'error');
    debugLog('error', 'Message send blocked: client is not registered and connected.');
    return;
  }

  const sent = sendPayload({ type: 'message', destination: CONFIG.room, content });
  if (sent) {
    elements.messageInput.value = '';
    elements.messageInput.focus();
    // Messages are rendered when echoed by the server to prevent duplicates.
  }
}

function sendPayload(payload) {
  const safePayload = redactPayloadForLog(payload);
  if (!isSocketOpen()) {
    debugLog('error', 'Cannot send payload: WebSocket is not open.', safePayload);
    return false;
  }
  try {
    const serialized = JSON.stringify(payload);
    state.socket.send(serialized);
    debugLog('out', 'Sent', safePayload);
    return true;
  } catch (error) {
    debugLog('error', 'Failed to serialize or send payload.', error);
    showToast('Your message could not be sent.', 'error');
    return false;
  }
}

function redactPayloadForLog(payload) {
  if (!payload || typeof payload !== 'object') return payload;
  if (!Object.prototype.hasOwnProperty.call(payload, 'token')) return payload;
  return { ...payload, token: '[REDACTED]' };
}

function getOrCreateIdentityToken() {
  let storedToken = '';
  try {
    storedToken = window.localStorage.getItem(CONFIG.identityTokenStorageKey) || '';
  } catch (error) {
    debugLog('error', 'Local storage is unavailable; identity will last only for this page session.', error);
  }

  if (/^[A-Za-z0-9_-]{32,128}$/.test(storedToken)) {
    debugLog('info', 'Loaded the existing browser identity token.');
    return storedToken;
  }
  if (!window.crypto?.getRandomValues) {
    throw new Error('crypto.getRandomValues is unavailable.');
  }

  const bytes = window.crypto.getRandomValues(new Uint8Array(32));
  let binary = '';
  for (const byte of bytes) binary += String.fromCharCode(byte);
  const token = window.btoa(binary)
    .replaceAll('+', '-')
    .replaceAll('/', '_')
    .replace(/=+$/, '');

  try {
    window.localStorage.setItem(CONFIG.identityTokenStorageKey, token);
    debugLog('info', 'Created and saved a new browser identity token.');
  } catch (error) {
    debugLog('error', 'Could not save identity token; it will last only for this page session.', error);
  }
  return token;
}

function renderPlayers() {
  elements.playerList.replaceChildren();
  const players = [...state.players].sort((a, b) => {
    if (a.name === state.username) return -1;
    if (b.name === state.username) return 1;
    return a.name.localeCompare(b.name, undefined, { sensitivity: 'base' });
  });

  if (!players.length) {
    const item = document.createElement('li');
    item.className = 'empty-state';
    const icon = document.createElement('span');
    icon.className = 'empty-state__icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = '☆';
    const text = document.createElement('span');
    text.textContent = 'Waiting for players…';
    item.append(icon, text);
    elements.playerList.append(item);
  } else {
    players.forEach((player) => elements.playerList.append(createPlayerElement(player)));
  }

  elements.playerCount.textContent = String(players.length);
  elements.playerCount.setAttribute('aria-label', `${players.length} player${players.length === 1 ? '' : 's'}`);
}

function createPlayerElement(player) {
  const item = document.createElement('li');
  item.className = 'player-item';
  const avatar = document.createElement('span');
  avatar.className = 'player-avatar';
  avatar.style.background = avatarColor(player.name);
  avatar.textContent = player.name.charAt(0).toUpperCase() || '?';
  avatar.setAttribute('aria-hidden', 'true');
  const name = document.createElement('span');
  name.className = `player-name${player.name === state.username ? ' player-name--you' : ''}`;
  name.textContent = player.name;
  name.title = player.name;
  const online = document.createElement('span');
  online.className = 'player-online';
  online.setAttribute('aria-label', 'Online');
  item.append(avatar, name, online);
  return item;
}

function addChatMessage(sender, content, timestamp) {
  const wrapper = document.createElement('article');
  wrapper.className = 'chat-message';
  const avatar = document.createElement('span');
  avatar.className = 'chat-message__avatar';
  avatar.style.background = avatarColor(sender);
  avatar.textContent = sender.charAt(0).toUpperCase() || '?';
  avatar.setAttribute('aria-hidden', 'true');
  const meta = document.createElement('div');
  meta.className = 'chat-message__meta';
  const name = document.createElement('span');
  name.className = 'chat-message__name';
  name.textContent = sender;
  const time = document.createElement('time');
  time.className = 'chat-message__time';
  const date = timestamp ? new Date(timestamp) : new Date();
  time.dateTime = Number.isNaN(date.getTime()) ? new Date().toISOString() : date.toISOString();
  time.textContent = Number.isNaN(date.getTime()) ? '' : date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
  const message = document.createElement('p');
  message.className = 'chat-message__content';
  message.textContent = content;
  meta.append(name, time);
  wrapper.append(avatar, meta, message);
  elements.messageList.append(wrapper);
  trimMessages();
  scrollMessagesToBottom();
}

function addSystemMessage(message, isError = false) {
  const element = document.createElement('p');
  element.className = `system-message${isError ? ' system-message--error' : ''}`;
  element.textContent = message;
  elements.messageList.append(element);
  trimMessages();
  scrollMessagesToBottom();
}

function trimMessages() {
  while (elements.messageList.children.length > CONFIG.maxMessages) {
    elements.messageList.children[1]?.remove(); // Preserve the welcome banner.
  }
}

function scrollMessagesToBottom() {
  elements.messageList.scrollTop = elements.messageList.scrollHeight;
}

function avatarColor(name) {
  const palette = ['#f05e9c', '#7152cf', '#42acd8', '#df9e32', '#40b68e', '#e47555'];
  let hash = 0;
  for (const character of name) hash = ((hash << 5) - hash + character.charCodeAt(0)) | 0;
  return palette[Math.abs(hash) % palette.length];
}

function updateConnectionDiagnostics(status, eventDescription) {
  const now = new Date().toLocaleTimeString([], { hour12: false });
  state.lastConnectionEvent = `${now} — ${eventDescription}`;
  const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
  const networkDetails = [navigator.onLine ? 'Online' : 'Offline'];
  if (connection?.effectiveType) networkDetails.push(connection.effectiveType);
  if (connection?.downlink) networkDetails.push(`${connection.downlink} Mbps`);

  elements.diagnosticSocketState.textContent = status;
  elements.diagnosticNetworkState.textContent = networkDetails.join(' · ');
  elements.diagnosticLastEvent.textContent = state.lastConnectionEvent;
  elements.diagnosticSocketBadge.textContent = status;
  const badgeStatus = status === 'OPEN' ? 'open' : ['ERROR', 'CLOSED', 'OFFLINE'].includes(status) ? 'error' : 'connecting';
  elements.diagnosticSocketBadge.className = `diagnostic-badge diagnostic-badge--${badgeStatus}`;
}

async function copyConnectionDiagnostics() {
  const recentEvents = [...elements.modalDebugLog.children].map((entry) => entry.textContent).join('\n');
  const report = [
    'Checkerboard Chase connection diagnostics',
    `Captured: ${new Date().toISOString()}`,
    `Page: ${window.location.href}`,
    `Page protocol: ${window.location.protocol}`,
    `Secure context: ${window.isSecureContext}`,
    `WebSocket: ${CONFIG.websocketUrl}`,
    `Socket state: ${elements.diagnosticSocketState.textContent}`,
    `Browser network: ${elements.diagnosticNetworkState.textContent}`,
    `Last event: ${state.lastConnectionEvent}`,
    `User agent: ${navigator.userAgent}`,
    '',
    'Recent events:',
    recentEvents || '(none)',
  ].join('\n');

  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(report);
    } else {
      const textarea = document.createElement('textarea');
      textarea.value = report;
      textarea.style.position = 'fixed';
      textarea.style.opacity = '0';
      document.body.append(textarea);
      textarea.select();
      if (!document.execCommand('copy')) throw new Error('Copy command was rejected.');
      textarea.remove();
    }
    showToast('Connection diagnostics copied.');
  } catch (error) {
    debugLog('error', 'Could not copy diagnostics.', error);
    showToast('Copy failed. Take a screenshot of the diagnostics.', 'error');
  }
}

function setConnectionStatus(status, text) {
  elements.connectionStatus.className = `connection-status connection-status--${status}`;
  elements.connectionStatusText.textContent = text;
}

function setModalConnection(status, text) {
  elements.modalStatusDot.className = `mini-dot mini-dot--${status}`;
  elements.modalStatus.textContent = text;
}

function setRegistrationControls(enabled, buttonText, loading = false) {
  elements.joinButton.disabled = !enabled;
  elements.usernameInput.disabled = loading;
  elements.joinButtonText.textContent = buttonText;
  elements.usernameSpinner.hidden = !loading;
}

function setUsernameError(message) {
  elements.usernameError.textContent = message;
  elements.usernameField.classList.add('input-shell--error');
  elements.usernameInput.setAttribute('aria-invalid', 'true');
}

function clearUsernameError() {
  elements.usernameError.textContent = '';
  elements.usernameField.classList.remove('input-shell--error');
  elements.usernameInput.removeAttribute('aria-invalid');
}

function enableChat() {
  elements.messageInput.disabled = false;
  elements.sendButton.disabled = false;
  renderGames();
  postGameState();
}

function disableChat() {
  elements.messageInput.disabled = true;
  elements.sendButton.disabled = true;
  renderGames();
  postGameState();
}

function showModal() {
  elements.usernameModal.hidden = false;
}

function hideModal() {
  elements.usernameModal.hidden = true;
}

function isSocketOpen() {
  return state.socket?.readyState === WebSocket.OPEN;
}

function scheduleReconnect() {
  if (state.manuallyClosed || !navigator.onLine || state.reconnectTimer) return;
  if (state.reconnectAttempts >= CONFIG.maxReconnectAttempts) {
    setConnectionStatus('offline', 'Reconnect failed');
    updateConnectionDiagnostics('ERROR', `Stopped after ${CONFIG.maxReconnectAttempts} reconnect attempts`);
    setModalConnection('offline', 'Could not reconnect. Refresh the page to try again.');
    setRegistrationControls(false, 'Connection unavailable');
    debugLog('error', `Stopped reconnecting after ${CONFIG.maxReconnectAttempts} attempts.`);
    return;
  }

  const delay = Math.min(CONFIG.reconnectBaseDelayMs * (2 ** state.reconnectAttempts), CONFIG.reconnectMaxDelayMs);
  state.reconnectAttempts += 1;
  updateConnectionDiagnostics('RETRYING', `Reconnect ${state.reconnectAttempts}/${CONFIG.maxReconnectAttempts} scheduled in ${delay}ms`);
  debugLog('info', `Scheduling reconnect attempt ${state.reconnectAttempts}/${CONFIG.maxReconnectAttempts} in ${delay}ms.`);
  state.reconnectTimer = window.setTimeout(() => {
    state.reconnectTimer = null;
    connect();
  }, delay);
}

function handleBrowserOnline() {
  updateConnectionDiagnostics('RETRYING', 'Browser network changed to online');
  debugLog('info', 'Browser online event received.');
  state.reconnectAttempts = 0;
  connect();
}

function handleBrowserOffline() {
  debugLog('error', 'Browser offline event received.');
  setConnectionStatus('offline', 'Browser offline');
  setModalConnection('offline', 'No internet connection. Waiting to reconnect…');
  updateConnectionDiagnostics('OFFLINE', 'Browser network changed to offline');
  disableChat();
}

function closeSocket() {
  state.manuallyClosed = true;
  clearTimeout(state.reconnectTimer);
  clearTimeout(state.registrationTimer);
  state.socket?.close(1000, 'Page unloading');
}

function debugLog(level, message, detail) {
  const method = level === 'error' ? 'error' : level === 'in' || level === 'out' ? 'debug' : 'info';
  const prefix = `[Lobby ${level.toUpperCase()}]`;
  if (detail === undefined) console[method](prefix, message);
  else console[method](prefix, message, detail);

  if (!elements.debugLog) return;
  const entry = document.createElement('li');
  entry.className = `log--${level}`;
  const time = new Date().toLocaleTimeString([], { hour12: false });
  entry.textContent = `${time} ${level.toUpperCase()} — ${message}${formatLogDetail(detail)}`;
  elements.debugLog.append(entry);
  while (elements.debugLog.children.length > CONFIG.maxLogEntries) elements.debugLog.firstElementChild.remove();
  elements.debugLog.scrollTop = elements.debugLog.scrollHeight;

  if (elements.modalDebugLog) {
    const modalEntry = entry.cloneNode(true);
    elements.modalDebugLog.append(modalEntry);
    while (elements.modalDebugLog.children.length > 30) elements.modalDebugLog.firstElementChild.remove();
    elements.modalDebugLog.scrollTop = elements.modalDebugLog.scrollHeight;
  }
}

function formatLogDetail(detail) {
  if (detail === undefined || detail instanceof Event) return '';
  if (typeof detail === 'string') return ` ${detail}`;
  if (detail instanceof Error) return ` ${detail.name}: ${detail.message}`;
  try { return ` ${JSON.stringify(detail)}`; } catch { return ' [unserializable detail]'; }
}

function showToast(message, type = 'info') {
  const toast = document.createElement('div');
  toast.className = `toast${type === 'error' ? ' toast--error' : ''}`;
  toast.textContent = message;
  elements.toastRegion.append(toast);
  window.setTimeout(() => toast.remove(), 4500);
}
