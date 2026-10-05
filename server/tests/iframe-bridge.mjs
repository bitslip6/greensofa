// Exercise the parent/iframe boundary without requiring a browser installation.
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const posted = [];
const sent = [];
const frameWindow = { postMessage: (data, origin) => posted.push({ data, origin }) };
const origin = 'http://192.168.1.50:8080';
const parentContext = vm.createContext({
  window: { location: { origin, protocol: 'http:', hostname: '192.168.1.50' }, addEventListener() {} },
  navigator: { onLine: true },
  WebSocket: { OPEN: 1 },
  console: { info() {}, debug() {}, error() {} },
  frameWindow, sent,
});
vm.runInContext(await readFile(new URL('../../app.js', import.meta.url), 'utf8'), parentContext);
vm.runInContext(`
  elements.gameFrame = { contentWindow: frameWindow };
  state.socket = { readyState: 1, send: (payload) => sent.push(JSON.parse(payload)) };
  state.registered = true;
  state.identityToken = 'secret-never-sent-to-frame';
  state.gameState = { type: 'game_state', symbol: 'X', match: { id: 'match1', game: 'tic-tac-toe', revision: 2 } };
  postGameState();
`, parentContext);
assert.equal(posted[0].origin, origin);
assert.equal(posted[0].data.type, 'game_state');
assert.equal(posted[0].data.connected, true);
assert.ok(!JSON.stringify(posted).includes('secret-never-sent-to-frame'));
parentContext.event = { origin: 'https://evil.example', source: frameWindow, data: { channel: 'lobby-game', type: 'move', cell: 4, revision: 2 } };
vm.runInContext('handleGameFrameMessage(event)', parentContext);
parentContext.event = { ...parentContext.event, origin, source: {} };
vm.runInContext('handleGameFrameMessage(event)', parentContext);
assert.equal(sent.length, 0);
parentContext.event = { ...parentContext.event, source: frameWindow };
vm.runInContext('handleGameFrameMessage(event)', parentContext);
assert.deepEqual(JSON.parse(JSON.stringify(sent[0])), { type: 'game_move', matchId: 'match1', cell: 4, revision: 2 });
vm.runInContext('state.registered = false; handleGameFrameMessage(event); postGameState();', parentContext);
assert.equal(sent.length, 1);
assert.equal(posted.at(-1).data.connected, false);

function element() {
  return {
    disabled: false, textContent: '', attributes: {}, listeners: {}, children: [],
    setAttribute(name, value) { this.attributes[name] = value; },
    addEventListener(name, handler) { this.listeners[name] = handler; },
    append(child) { this.children.push(child); },
  };
}
// Model the pre-rendered buttons from the HTML, not JavaScript-created markup.
const html = await readFile(new URL('../../games/tic-tac-toe.html', import.meta.url), 'utf8');
const board = element();
const buttonMarkup = [...html.matchAll(/<button\b[^>]*>[\s\S]*?<\/button>/g)];
assert.equal(buttonMarkup.length, 9);
for (const [cell, [markup]] of buttonMarkup.entries()) {
  assert.ok(markup.includes(`data-cell="${cell}"`));
  assert.ok(markup.includes(`aria-label="Square ${cell + 1}, empty"`));
  assert.ok(/\bdisabled\b/.test(markup));
  const button = element();
  button.dataset = { cell: String(cell) };
  button.disabled = true;
  board.append(button);
}
const status = element();
const parent = { postMessage: (data, target) => posted.push({ data, origin: target }) };
const listeners = {};
const childContext = vm.createContext({
  parent, location: { origin },
  window: { addEventListener: (name, handler) => { listeners[name] = handler; } },
  document: {
    querySelector: (selector) => selector === '#status' ? status : null,
    querySelectorAll: (selector) => selector === '#board button' ? board.children : [],
  },
});
vm.runInContext(await readFile(new URL('../../games/tic-tac-toe.js', import.meta.url), 'utf8'), childContext);
assert.equal(board.children.length, 9);
assert.ok(board.children.every((button) => button.disabled));
const snapshot = { channel: 'lobby-game', type: 'game_state', symbol: 'X', connected: true, match: { status: 'playing', turn: 'X', board: Array(9).fill(null), revision: 0 } };
listeners.message({ origin: 'https://evil.example', source: parent, data: snapshot });
assert.ok(board.children.every((button) => button.disabled));
listeners.message({ origin, source: parent, data: snapshot });
assert.ok(board.children.every((button) => !button.disabled));
board.children[4].listeners.click({ currentTarget: board.children[4] });
assert.ok(board.children.every((button) => button.disabled));
assert.equal(status.textContent, 'Waiting for the server to confirm your move…');
const messagesAfterClick = posted.length;
board.children[5].listeners.click({ currentTarget: board.children[5] });
assert.equal(posted.length, messagesAfterClick);
assert.equal(posted.at(-1).data.cell, 4);
assert.equal(posted.at(-1).data.revision, 0);
const next = { ...snapshot, match: { ...snapshot.match, turn: 'O', revision: 1, board: [null, null, null, null, 'X', null, null, null, null] } };
listeners.message({ origin, source: parent, data: next });
assert.equal(board.children[4].textContent, 'X');
assert.ok(board.children.every((button) => button.disabled));
// A rejected move resends the unchanged board and allows another attempt.
listeners.message({ origin, source: parent, data: snapshot });
assert.ok(board.children.every((button) => !button.disabled));
assert.equal(status.textContent, 'You are X. Your turn.');

// An occupied square stays disabled even when it is our turn again.
listeners.message({ origin, source: parent, data: { ...next, match: { ...next.match, turn: 'X' } } });
assert.equal(board.children[4].disabled, true);
assert.equal(board.children[0].disabled, false);

for (const [matchStatus, winner, text] of [
  ['waiting', null, 'You are X. Waiting for an opponent…'],
  ['abandoned', null, 'Opponent left. Return to the lobby to start again.'],
  ['finished', 'X', 'X wins!'],
  ['finished', null, 'Draw!'],
]) {
  listeners.message({ origin, source: parent, data: { ...snapshot, match: { ...snapshot.match, status: matchStatus, winner } } });
  assert.equal(status.textContent, text);
  assert.ok(board.children.every((button) => button.disabled));
}

listeners.message({ origin, source: parent, data: { ...snapshot, connected: false } });
assert.equal(status.textContent, 'Waiting for lobby connection…');
assert.ok(board.children.every((button) => button.disabled));
listeners.message({ origin, source: parent, data: { ...snapshot, match: null } });
assert.equal(status.textContent, 'Join a match from the lobby to play.');
assert.ok(board.children.every((button) => button.textContent === '' && button.disabled));
console.log('PASS pre-rendered board, iframe boundary, move loop, and game status rendering');
