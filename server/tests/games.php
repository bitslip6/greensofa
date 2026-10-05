<?php
declare(strict_types=1);

use Checkerboard\Lobby\GameBoard;
use Checkerboard\Lobby\GameMatch;
use Checkerboard\Lobby\GamePlayer;
use Checkerboard\Lobby\GameRooms;

$runner->test('typed match initializes named properties and finds seats', function (): void {
    $creator = new GamePlayer('private-player-id', 'Alice');
    $match = new GameMatch('match-id', GameRooms::GAME, $creator, 100);

    assertSame($creator, $match->playerX);
    assertSame(null, $match->playerO);
    assertSame(GameMatch::WAITING, $match->status);
    assertSame('X', $match->turn);
    assertSame(0, $match->revision);
    assertSame(100, $match->updatedAt);
    assertSame(array_fill(0, 9, null), $match->board->cells);
    assertSame('X', $match->symbolForPlayer('private-player-id'));
    assertSame(null, $match->symbolForPlayer('outsider'));

    $match->playerO = new GamePlayer('other-player-id', 'Bob');
    assertSame('O', $match->symbolForPlayer('other-player-id'));
    $match->playerX = null;
    assertSame(null, $match->symbolForPlayer('private-player-id'));
});

$runner->test('board recognizes all eight winning lines for both symbols', function (): void {
    $winningLines = [[0, 1, 2], [3, 4, 5], [6, 7, 8], [0, 3, 6], [1, 4, 7], [2, 5, 8], [0, 4, 8], [2, 4, 6]];

    foreach (['X', 'O'] as $symbol) {
        foreach ($winningLines as $line) {
            $board = new GameBoard();
            assertSame(null, $board->findWinner());
            assertSame(false, $board->isFull());

            $board->cells[$line[0]] = $symbol;
            $board->cells[$line[1]] = $symbol;
            assertSame(null, $board->findWinner());

            $board->cells[$line[2]] = $symbol;
            assertSame($symbol, $board->findWinner());
        }
    }

    $board = new GameBoard();
    $board->cells = ['X', 'O', 'X', 'X', 'O', 'O', 'O', 'X', 'X'];
    assertSame(null, $board->findWinner());
    assertSame(true, $board->isFull());
});

$runner->test('public snapshots do not expose or mutate internal objects', function (): void {
    $games = new GameRooms();
    $games->apply('private-id-alice', 'Alice', ['type' => 'game_create', 'game' => GameRooms::GAME], 100);
    $snapshot = $games->snapshot('private-id-alice');
    $id = $snapshot['match']['id'];
    assertTrue(!str_contains(json_encode($snapshot), 'private-id-alice'));
    assertTrue(!array_key_exists('updatedAt', $snapshot['match']));

    $snapshot['match']['players']['X']['name'] = 'Changed outside the registry';
    $snapshot['match']['board'][0] = 'O';
    $fresh = $games->snapshot('private-id-alice');
    assertSame('Alice', $fresh['match']['players']['X']['name']);
    assertSame(null, $fresh['match']['board'][0]);

    $games->apply('private-id-bob', 'Bob', ['type' => 'game_join', 'game' => GameRooms::GAME, 'matchId' => $id], 100);
    $games->apply('private-id-alice', 'Alice', ['type' => 'game_move', 'matchId' => $id, 'revision' => 0, 'cell' => 0], 101);
    assertSame('X', $games->snapshot('private-id-bob')['match']['board'][0]);
});

$runner->test('winning on the ninth move is not mistaken for a draw', function (): void {
    $games = new GameRooms();
    $games->apply('a', 'Alice', ['type' => 'game_create', 'game' => GameRooms::GAME], 100);
    $id = $games->snapshot('a')['match']['id'];
    $games->apply('b', 'Bob', ['type' => 'game_join', 'game' => GameRooms::GAME, 'matchId' => $id], 100);

    foreach ([0, 1, 2, 3, 4, 5, 7, 6, 8] as $revision => $cell) {
        $player = $revision % 2 === 0 ? 'a' : 'b';
        $games->apply($player, $player, ['type' => 'game_move', 'matchId' => $id, 'revision' => $revision, 'cell' => $cell], 100);
    }

    assertSame(9, $games->snapshot('a')['match']['revision']);
    assertSame(GameMatch::FINISHED, $games->snapshot('a')['match']['status']);
    assertSame('X', $games->snapshot('a')['match']['winner']);
});

$runner->test('game protocol accepts game commands', function () use ($protocol): void {
    foreach (['game_create', 'game_join', 'game_leave', 'game_move'] as $type) {
        assertSame($type, $protocol->decode(json_encode(['type' => $type]))['type']);
    }
});

$runner->test('matches validate membership, turns, stale and occupied moves; X wins', function (): void {
    $games = new GameRooms();
    assertProtocolCode('UNKNOWN_GAME', fn () => $games->apply('a', 'Alice', ['type' => 'game_create', 'game' => 'bad'], 100));
    $games->apply('a', 'Alice', ['type' => 'game_create', 'game' => GameRooms::GAME], 100);
    $id = $games->snapshot('a')['match']['id'];
    assertSame('X', $games->snapshot('a')['symbol']);
    assertTrue(!str_contains(json_encode($games->snapshot('a')), '"id":"a"'));
    assertSame('Alice', $games->catalog()['matches'][0]['host']);
    assertProtocolCode('ALREADY_IN_GAME', fn () => $games->apply('a', 'Alice', ['type' => 'game_create', 'game' => GameRooms::GAME], 100));
    $move = ['type' => 'game_move', 'matchId' => $id, 'revision' => 0, 'cell' => 0];
    assertProtocolCode('GAME_NOT_PLAYING', fn () => $games->apply('a', 'Alice', $move, 100));
    assertProtocolCode('UNKNOWN_MATCH', fn () => $games->apply('b', 'Bob', ['type' => 'game_join', 'game' => GameRooms::GAME, 'matchId' => []], 100));
    $games->apply('b', 'Bob', ['type' => 'game_join', 'game' => GameRooms::GAME, 'matchId' => $id], 100);
    assertSame('O', $games->snapshot('b')['symbol']);
    assertSame([], $games->catalog()['matches']);
    assertProtocolCode('GAME_FULL', fn () => $games->apply('c', 'Cat', ['type' => 'game_join', 'game' => GameRooms::GAME, 'matchId' => $id], 100));
    assertProtocolCode('NOT_IN_GAME', fn () => $games->apply('c', 'Cat', $move, 100));
    assertProtocolCode('NOT_YOUR_TURN', fn () => $games->apply('b', 'Bob', $move, 100));
    assertProtocolCode('INVALID_MOVE', fn () => $games->apply('a', 'Alice', array_replace($move, ['cell' => '0']), 100));
    assertProtocolCode('INVALID_MOVE', fn () => $games->apply('a', 'Alice', array_replace($move, ['cell' => 9]), 100));
    $games->apply('a', 'Alice', $move, 100);
    assertProtocolCode('STALE_MOVE', fn () => $games->apply('b', 'Bob', $move, 100));
    assertProtocolCode('INVALID_MOVE', fn () => $games->apply('b', 'Bob', array_replace($move, ['revision' => 1]), 100));
    foreach ([['b', 3], ['a', 1], ['b', 4], ['a', 2]] as [$player, $cell]) {
        $games->apply($player, $player, ['type' => 'game_move', 'matchId' => $id, 'revision' => $games->snapshot($player)['match']['revision'], 'cell' => $cell], 101);
    }
    assertSame('finished', $games->snapshot('a')['match']['status']);
    assertSame('X', $games->snapshot('a')['match']['winner']);
    assertProtocolCode('GAME_NOT_PLAYING', fn () => $games->apply('b', 'Bob', array_replace($move, ['revision' => 5]), 102));
});

$runner->test('draw, leave, seat recovery and idle expiry', function (): void {
    $games = new GameRooms();
    $games->apply('a', 'Alice', ['type' => 'game_create', 'game' => GameRooms::GAME], 100);
    $id = $games->snapshot('a')['match']['id'];
    $games->apply('b', 'Bob', ['type' => 'game_join', 'game' => GameRooms::GAME, 'matchId' => $id], 100);
    foreach ([0, 1, 2, 4, 3, 5, 7, 6, 8] as $revision => $cell) {
        $player = $revision % 2 === 0 ? 'a' : 'b';
        $games->apply($player, $player, ['type' => 'game_move', 'matchId' => $id, 'revision' => $revision, 'cell' => $cell], 100);
    }
    assertSame('finished', $games->snapshot('a')['match']['status']);
    assertSame(null, $games->snapshot('a')['match']['winner']);
    assertSame($id, $games->snapshot('a')['match']['id']); // Identity-based reconnect, independent of fd.
    $games->apply('a', 'Alice', ['type' => 'game_leave'], 101);
    assertSame(null, $games->snapshot('a')['match']);
    assertSame('abandoned', $games->snapshot('b')['match']['status']);
    $games->apply('b', 'Bob', ['type' => 'game_leave'], 101);
    $games->apply('a', 'Alice', ['type' => 'game_create', 'game' => GameRooms::GAME], 200);
    assertSame(false, $games->expire(1999));
    assertSame(true, $games->expire(2000));
    assertSame(null, $games->snapshot('a')['match']);
    assertSame([], $games->catalog()['matches']);
});
