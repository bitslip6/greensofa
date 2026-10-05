# Checkerboard Chase Lobby PoC v2

A dependency-free HTML/CSS/ES6 player lobby with an OpenSwoole 26.2 and SQLite WebSocket server.

- Browser client: `index.html`, `styles.css`, and `app.js`
- Server setup and operation: [`server/README.md`](server/README.md)
- Complete lobby wire protocol: [`schema-messages.md`](schema-messages.md)
- Deterministic mesh state/input schemas: [`schema-mesh-messages.md`](schema-mesh-messages.md)
- Browser-safe deterministic state modules: [`mesh/core`](mesh/core)

## Run locally

From the repository root, start the lobby in one terminal (PHP/OpenSwoole and PDO SQLite required):

```bash
export LOBBY_DEV_MODE=1 LOBBY_HOST=0.0.0.0 LOBBY_PORT=9502
export LOBBY_TOKEN_PEPPER="${LOBBY_TOKEN_PEPPER:-local-development-pepper-change-me-123456789}"
php server/bin/lobby-server.php
```

In another terminal, serve only the public frontend files:

```bash
php -S 0.0.0.0:8080 server/bin/dev-web-router.php
```

Open `http://<server-LAN-IP>:8080` (or <http://localhost:8080>). Restrict ports 8080/9502 to your trusted LAN: development WebSockets are unencrypted. Do not serve the entire repository with an unrestricted static server; `server/var` contains private database files.

## Play the example game

1. Register two players using separate browsers/devices, or normal and private browser windows. Tabs in the same browser share an identity and replace each other's lobby connection.
2. Choose **Tic Tac Toe**, then **Create match**. The creator becomes X and the game opens in an iframe.
3. The other player clicks **Join …'s match** and becomes O. Click empty squares to play; the server validates moves and broadcasts the board.
4. Public **#general** chat remains visible and works throughout the game. Click **Leave match** before creating/joining another match.

The parent page owns the only WebSocket connection. The same-origin iframe receives game state and sends move intents via origin/source-checked `postMessage`; it does not receive the browser identity token. The dummy board is in `games/tic-tac-toe.html` and `games/tic-tac-toe.js`.

Matches are in memory in the existing single worker. Reconnecting with the same identity restores your seat. Leaving abandons the match for the opponent; disconnected players retain their seat until the match has been idle for 30 minutes. Server/worker restarts clear all matches, but not existing SQLite identities/chat. Rematches, spectators, private game chat, and durable game history are intentionally not included.

To add another game later, extend the server catalog/rules in `server/src/GameRooms.php`, the `GAME_PAGES` allowlist in `app.js`, and the public-file allowlist in `server/bin/dev-web-router.php`.

## WebSocket protocol

The client derives the WebSocket host from the page URL:

- `http://192.168.1.50:8080` connects to `ws://192.168.1.50:9502/lobby`
- `https://game.bitfire.co` connects to `wss://game.bitfire.co/lobby`

This lets LAN clients use the same server IP they used to load the page.

Outgoing registration:

```json
{
  "type": "register",
  "username": "StarChaser",
  "token": "browser-generated-base64url-secret"
}
```

The browser generates this token with `crypto.getRandomValues`, stores it under `checkerboardChase.identityToken.v1` in local storage, and redacts it from application debug logs.

Expected registration response:

```json
{ "type": "register", "available": true, "username": "StarChaser" }
```

A rejected name should return `"available": false`, optionally with a `message` or `error` field.

Outgoing chat message:

```json
{ "type": "message", "destination": "general", "content": "Hello!" }
```

Incoming messages may identify their sender with `sender`, `username`, `player`, or `from`. Player lists should use:

```json
{ "type": "players", "players": ["StarChaser", "TileRunner"] }
```

Player entries may also be objects containing `name` or `username`.

The page and browser console include detailed connection/protocol logs. The client reconnects automatically with exponential backoff after unexpected disconnects.

## Run the deterministic state tests

Node.js 20 or newer is required. Install the development-only property-testing dependency and run the unit suite:

```bash
npm install
npm test
```

The modules under `mesh/core` have no runtime package dependencies.

## Run the server tests

```bash
php server/tests/run.php
# Isolated real WebSocket + HTTP smoke test (Node.js 22+ and PHP/OpenSwoole)
node server/tests/game-e2e.mjs
# Parent/iframe bridge and board rendering checks (no browser dependency)
node server/tests/iframe-bridge.mjs
```

PDO SQLite is required for the persistence tests. See [`server/README.md`](server/README.md) for FreeBSD configuration, WSS certificates, migrations, and operations.
