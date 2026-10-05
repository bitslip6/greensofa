// Strict mode catches mistakes such as assigning to an undeclared variable.
'use strict';

/*
 * HOW THE GAME LOOP WORKS
 * -----------------------
 * This is a turn-based game, so there is no animation loop or timer.
 * We wait for events instead:
 *
 *   1. The lobby sends us the current game state.
 *   2. We draw that state using the buttons already in the HTML.
 *   3. The player clicks an empty square during their turn.
 *   4. We ask the lobby to send that move to the PHP server.
 *   5. The server validates the move and sends back the updated state.
 *   6. We redraw the board, then wait for the next event.
 *
 * The server owns the board, turns, and winner. This page only displays
 * server state and sends move requests. It never decides who won and never
 * places a mark before the server accepts it.
 *
 * The parent lobby page owns the WebSocket and chat. This iframe talks to
 * that page through postMessage, without opening another connection.
 */


// These elements already exist because the HTML loads this script with defer.
// querySelector finds the single status paragraph by its id.
// querySelectorAll finds the nine existing buttons inside the board.
// We keep references to them so rendering does not need to search again.
const statusMessage = document.querySelector('#status');
const squareButtons = document.querySelectorAll('#board button');


// Our latest copy of the state supplied by the lobby.
// match is null until the player creates or joins a match.
// symbol will be either 'X' or 'O' once the player has a seat.
// connected describes the parent lobby's registered connection, not a socket
// opened by this iframe.
//
// A match contains:
//   board:    nine entries, each null (empty), 'X', or 'O'.
//   turn:     the symbol whose move the server will accept next.
//   status:   'waiting', 'playing', 'finished', or 'abandoned'.
//   winner:   'X', 'O', or null. Null means a draw only when status is finished.
//   revision: the number of accepted moves, used to reject outdated requests.
let gameState = {
  match: null,
  symbol: null,
  connected: false,
};


// Prevent a second click while we are waiting for the first move's response.
let movePending = false;


// SETUP: Register handlers once. They run later when browser events occur.
function startGame() {
  // All nine buttons use the same handler. Their data-cell attributes
  // tell us which square was clicked.
  for (const button of squareButtons) {
    // Pass the function itself, without parentheses. The browser will call
    // it on a click; calling handleSquareClick() here would run it immediately.
    button.addEventListener('click', handleSquareClick);
  }

  // Listen before announcing readiness so we cannot miss the lobby's reply.
  window.addEventListener('message', handleLobbyMessage);

  // Ask the lobby for its current state. This also works after an iframe reload.
  const readyMessage = {
    channel: 'lobby-game',
    type: 'ready',
  };

  // parent is the window containing this iframe. The target origin restricts
  // delivery to our own protocol, hostname, and port, rather than any website.
  // postMessage is asynchronous: the response arrives in a later event.
  parent.postMessage(readyMessage, location.origin);
}


// STEP 1: Receive the latest state from the parent lobby page.
function handleLobbyMessage(event) {
  // A page can receive messages from more than one window. Check both:
  // source must be our exact parent window; origin must be our own website.
  // These checks belong before reading or acting on the message contents.
  if (event.source !== parent) {
    return;
  }

  if (event.origin !== location.origin) {
    return;
  }

  // data is the JavaScript object sent with postMessage, not a JSON string.
  const message = event.data;

  // The channel separates game messages from unrelated parent/iframe traffic.
  if (!message || message.channel !== 'lobby-game') {
    return;
  }

  // This handler only understands complete state snapshots.
  if (message.type !== 'game_state') {
    return;
  }

  // Replace the previous snapshot, rather than merging individual squares.
  // This keeps reloads and reconnects consistent with the server's full board.
  gameState = message;

  // A state message means we can stop waiting for our move's response.
  // On a rejected move, the lobby resends the unchanged state. On a lost
  // connection, it sends connected: false. Both cases unlock this flag,
  // while the rendering rules below decide whether clicks are allowed.
  movePending = false;

  renderGame();
}


// STEPS 3 AND 4: Send a move request, but do not change the board ourselves.
function handleSquareClick(event) {
  // currentTarget is the button we attached the handler to. Unlike target,
  // it stays that button even if the button later contains a nested element.
  const button = event.currentTarget;
  const match = gameState.match;

  // Disabled buttons normally cannot be clicked. Keep this guard as well
  // so a manually invoked handler cannot send a move from a disabled square.
  if (button.disabled || match === null) {
    return;
  }

  // HTML data-cell="4" becomes the string button.dataset.cell.
  // Convert it to the integer the server expects (0 through 8).
  const cell = Number(button.dataset.cell);

  // Redraw immediately to disable all squares, but leave their marks unchanged.
  // This prevents double-clicks from submitting two moves while we wait.
  movePending = true;
  renderGame();

  const moveMessage = {
    channel: 'lobby-game',
    type: 'move',
    cell: cell,

    // The revision identifies the board we saw when clicking. The server
    // rejects a move if the board has changed since that revision.
    revision: match.revision,
  };

  // The parent adds the current match ID and forwards the request through
  // its WebSocket. We do not send a player name or choose our own X/O symbol;
  // the server identifies the player from their registered connection.
  parent.postMessage(moveMessage, location.origin);

  // Now wait. handleLobbyMessage will run when the lobby replies.
}


// STEPS 2 AND 6: Redraw only when something changes, not on every frame.
function renderGame() {
  renderStatusMessage();
  renderBoard();
}


// Pick one status message. Each return stops this function after a match,
// so a later condition cannot overwrite a more important message.
function renderStatusMessage() {
  const match = gameState.match;

  // Connection status takes priority, even if we still have an old board.

  if (!gameState.connected) {
    statusMessage.textContent = 'Waiting for lobby connection…';
    return;
  }

  if (match === null) {
    statusMessage.textContent = 'Join a match from the lobby to play.';
    return;
  }

  // The creator is X. The server starts play only after O joins.
  if (match.status === 'waiting') {
    statusMessage.textContent = 'You are X. Waiting for an opponent…';
    return;
  }

  // Leaving ends the match; it does not open that seat for a replacement.
  if (match.status === 'abandoned') {
    statusMessage.textContent = 'Opponent left. Return to the lobby to start again.';
    return;
  }

  // Use the server's result. There is deliberately no local win-checking code.
  if (match.status === 'finished') {
    if (match.winner === null) {
      statusMessage.textContent = 'Draw!';
    } else {
      statusMessage.textContent = match.winner + ' wins!';
    }

    return;
  }

  if (movePending) {
    statusMessage.textContent = 'Waiting for the server to confirm your move…';
    return;
  }

  // turn is shared match state; symbol is this particular player's seat.
  // Comparing them tells us whether the player should act or wait.
  if (match.turn === gameState.symbol) {
    statusMessage.textContent = 'You are ' + gameState.symbol + '. Your turn.';
  } else {
    statusMessage.textContent = 'You are ' + gameState.symbol + '. Opponent’s turn.';
  }
}


// Update all nine existing buttons from the same snapshot. Rendering changes
// only the page; it does not modify match.board or send network messages.
function renderBoard() {
  const match = gameState.match;

  for (const button of squareButtons) {
    const cell = Number(button.dataset.cell);
    // An empty string clears the button when there is no match or no mark.
    // Read the board using the button's data-cell, not its position in the DOM.
    let mark = '';

    if (match !== null && match.board[cell] !== null) {
      mark = match.board[cell];
    }

    // Update the existing button's text and its screen-reader label.
    button.textContent = mark;

    let squareDescription = 'empty';

    if (mark !== '') {
      squareDescription = mark;
    }

    // Human-readable square numbers start at 1; server indexes start at 0.
    // Updating aria-label lets screen readers announce the square's contents.
    button.setAttribute('aria-label', 'Square ' + (cell + 1) + ', ' + squareDescription);

    // Start disabled. Only enable a square after every check below passes.
    // continue skips the rest of this loop iteration and moves to the next
    // button. Unlike return, it does not stop rendering the remaining squares.
    // These checks guide the UI; the server independently validates every move.
    button.disabled = true;

    // No connection or seat means there is nowhere to submit a valid move.
    if (!gameState.connected || match === null) {
      continue;
    }

    // Waiting, finished, and abandoned boards are display-only.
    if (match.status !== 'playing') {
      continue;
    }

    // Only the player whose turn it is may choose a square.
    if (match.turn !== gameState.symbol) {
      continue;
    }

    // Do not accept another click until the lobby answers the previous one.
    if (movePending) {
      continue;
    }

    // A played square cannot be overwritten, even on our own turn.
    if (mark !== '') {
      continue;
    }

    // Every condition passed: this is an empty square we can click now.
    button.disabled = false;
  }
}


// Begin listening. The initial HTML already shows an empty, disabled board.
startGame();
