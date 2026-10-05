// Requires PHP/OpenSwoole. Runs isolated lobby and HTTP servers with a temporary database.
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createServer } from 'node:net';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../../', import.meta.url));
const directory = await mkdtemp(join(tmpdir(), 'lobby-game-test-'));
const clients = [];
const processes = [];
let logs = '';
async function freePort() {
  const server = createServer();
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  const port = server.address().port;
  await new Promise((resolve) => server.close(resolve));
  return port;
}
function start(args, env = {}) {
  const child = spawn('php', args, { cwd: root, env: { ...process.env, ...env }, stdio: ['ignore', 'pipe', 'pipe'] });
  child.stdout.on('data', (chunk) => { logs += chunk; });
  child.stderr.on('data', (chunk) => { logs += chunk; });
  processes.push(child);
  return child;
}
async function connect(port) {
  for (let attempt = 0; attempt < 50; attempt++) {
    const socket = new WebSocket(`ws://127.0.0.1:${port}/lobby`);
    const queue = [];
    socket.addEventListener('message', (event) => {
      const message = JSON.parse(event.data);
      if (message.type === 'ping') socket.send(JSON.stringify({ type: 'pong' }));
      else queue.push(message);
    });
    const opened = await new Promise((resolve) => {
      socket.addEventListener('open', () => resolve(true), { once: true });
      socket.addEventListener('error', () => resolve(false), { once: true });
    });
    if (opened) {
      clients.push(socket);
      return {
        socket,
        send: (message) => socket.send(JSON.stringify(message)),
        async wait(predicate) {
          for (let i = 0; i < 250; i++) {
            const index = queue.findIndex(predicate);
            if (index !== -1) return queue.splice(index, 1)[0];
            await new Promise((resolve) => setTimeout(resolve, 20));
          }
          throw new Error(`Timed out waiting for a message. Queue: ${JSON.stringify(queue)}`);
        },
      };
    }
    socket.close();
    await new Promise((resolve) => setTimeout(resolve, 40));
  }
  throw new Error('Lobby did not start');
}
async function register(client, username, token) {
  client.send({ type: 'register', username, token });
  assert.equal((await client.wait((m) => m.type === 'register')).registered, true);
}

try {
  const port = await freePort();
  const httpPort = await freePort();
  start(['server/bin/lobby-server.php'], {
    LOBBY_DEV_MODE: '1', LOBBY_HOST: '127.0.0.1', LOBBY_PORT: String(port),
    LOBBY_DATABASE_PATH: join(directory, 'test.sqlite3'), LOBBY_TOKEN_PEPPER: 'isolated-test-pepper-12345678901234567890',
  });
  start(['-S', `127.0.0.1:${httpPort}`, 'server/bin/dev-web-router.php']);
  const a = await connect(port);
  const b = await connect(port);
  a.send({ type: 'game_create', game: 'tic-tac-toe' });
  assert.equal((await a.wait((m) => m.type === 'error')).code, 'NOT_REGISTERED');
  await register(a, 'Alice', 'a'.repeat(43));
  await register(b, 'Bob', 'b'.repeat(43));
  assert.equal((await a.wait((m) => m.type === 'games')).games[0].id, 'tic-tac-toe');
  a.send({ type: 'game_create', game: 'tic-tac-toe' });
  const waiting = await a.wait((m) => m.type === 'game_state' && m.match?.status === 'waiting');
  const id = waiting.match.id;
  assert.equal(waiting.symbol, 'X');
  assert.equal((await b.wait((m) => m.type === 'games' && m.matches.length === 1)).matches[0].id, id);
  b.send({ type: 'game_join', game: 'tic-tac-toe', matchId: id });
  assert.equal((await b.wait((m) => m.type === 'game_state' && m.match?.status === 'playing')).symbol, 'O');
  b.send({ type: 'game_move', matchId: id, revision: 0, cell: 4 });
  assert.equal((await b.wait((m) => m.type === 'error')).code, 'NOT_YOUR_TURN');
  a.send({ type: 'game_move', matchId: id, revision: 0, cell: 0 });
  assert.equal((await b.wait((m) => m.type === 'game_state' && m.match?.revision === 1)).match.board[0], 'X');
  a.send({ type: 'message', destination: 'general', content: 'Chat while playing' });
  assert.equal((await b.wait((m) => m.type === 'message')).content, 'Chat while playing');
  a.socket.close();
  await once(a.socket, 'close');
  const reconnected = await connect(port);
  await register(reconnected, 'Alice', 'a'.repeat(43));
  const restored = await reconnected.wait((m) => m.type === 'game_state' && m.match?.id === id);
  assert.equal(restored.match.board[0], 'X');
  assert.equal(restored.symbol, 'X');
  for (const [client, cell, revision] of [[b, 3, 1], [reconnected, 1, 2], [b, 4, 3], [reconnected, 2, 4]]) {
    client.send({ type: 'game_move', matchId: id, revision, cell });
    await client.wait((m) => m.type === 'game_state' && m.match?.revision === revision + 1);
  }
  const finished = await b.wait((m) => m.type === 'game_state' && m.match?.status === 'finished');
  assert.equal(finished.match.winner, 'X');
  reconnected.send({ type: 'game_leave' });
  await b.wait((m) => m.type === 'game_state' && m.match?.status === 'abandoned');
  b.send({ type: 'game_leave' });
  // Initial null snapshots also exist, so verify that a new match can be created after leaving.
  b.send({ type: 'game_create', game: 'tic-tac-toe' });
  await b.wait((m) => m.type === 'game_state' && m.match?.status === 'waiting');

  for (const path of ['/', '/app.js', '/games/lobby.css', '/games/tic-tac-toe.html', '/games/tic-tac-toe.js']) {
    assert.equal((await fetch(`http://127.0.0.1:${httpPort}${path}`)).status, 200);
  }
  for (const path of ['/server/var/lobby.sqlite3', '/server/.env.example', '/package.json', '/server/bin/lobby-server.php']) {
    assert.equal((await fetch(`http://127.0.0.1:${httpPort}${path}`)).status, 404);
  }
  console.log('PASS game lifecycle, validation, live chat, reconnect, and safe HTTP/iframe assets');
} catch (error) {
  console.error(logs);
  throw error;
} finally {
  for (const socket of clients) socket.close();
  await Promise.all(processes.map(async (child) => {
    if (child.exitCode !== null) return;
    const exited = once(child, 'exit');
    child.kill('SIGTERM');
    await exited;
  }));
  await rm(directory, { recursive: true, force: true });
}
