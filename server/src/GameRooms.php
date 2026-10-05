<?php
declare(strict_types=1);

namespace Checkerboard\Lobby;

/**
 * Manages all live matches inside the lobby's single PHP worker.
 *
 * Responsibilities:
 *   - Create matches and assign seats (X creates, O joins).
 *   - Check requests before changing any game state.
 *   - Apply accepted moves and determine the result.
 *   - Produce public messages for the browser.
 *   - Remove matches that have been idle too long.
 *
 * Matches live in memory, not SQLite. They survive a browser reconnect but
 * disappear when the server restarts. A player ID comes from the registered
 * connection; we never trust a player ID supplied in a browser message.
 */
final class GameRooms
{
    public const GAME = 'tic-tac-toe';

    private const MAX_MATCHES = 100;
    private const IDLE_TIMEOUT_SECONDS = 30 * 60;

    /**
     * A lookup table: each match ID points to a typed GameMatch object.
     * The array is just the collection; the match's attributes are properties.
     *
     * @var array<string, GameMatch>
     */
    private array $matches = [];

    /**
     * Entry point for an authenticated player's game command.
     *
     * JSON arrives as an array at the network boundary. Its fields still need
     * validation because a browser can send incorrect types or missing values.
     * Inside the registry, players, matches, and boards are typed objects.
     *
     * @param array<string, mixed> $message Decoded client JSON.
     * @param int $now Current Unix timestamp, supplied by the caller for testability.
     */
    public function apply(string $playerId, string $username, array $message, int $now): void
    {
        $type = $message['type'] ?? null;

        // Dispatch one command to one named method. Returning here ensures
        // a request cannot accidentally fall through into another action.
        if ($type === 'game_leave') {
            $this->leaveMatch($playerId, $now);
            return;
        }

        if ($type === 'game_create') {
            $this->createMatch($playerId, $username, $message, $now);
            return;
        }

        if ($type === 'game_join') {
            $this->joinMatch($playerId, $username, $message, $now);
            return;
        }

        if ($type === 'game_move') {
            $this->makeMove($playerId, $message, $now);
            return;
        }

        // Normally Protocol::decode rejects unknown commands before this point.
        $this->fail('UNKNOWN_TYPE', 'The message type is not supported.');
    }

    /** @param array<string, mixed> $message */
    private function createMatch(string $playerId, string $username, array $message, int $now): void
    {
        $this->requireAvailablePlayer($playerId);
        $this->requireSupportedGame($message);

        if (count($this->matches) >= self::MAX_MATCHES) {
            $this->fail('GAME_LIMIT', 'Too many matches. Try again later.');
        }

        // Eight random bytes become a 16-character hexadecimal match ID.
        $matchId = bin2hex(random_bytes(8));
        $creator = new GamePlayer($playerId, $username);
        $match = new GameMatch($matchId, self::GAME, $creator, $now);

        // The constructor gives the creator X and initializes an empty board.
        // Saving the object makes the waiting match available to other players.
        $this->matches[$matchId] = $match;
    }

    /** @param array<string, mixed> $message */
    private function joinMatch(string $playerId, string $username, array $message, int $now): void
    {
        $this->requireAvailablePlayer($playerId);
        $this->requireSupportedGame($message);

        $match = $this->requireMatch($message);

        if ($match->status !== GameMatch::WAITING) {
            $this->fail('GAME_FULL', 'This match is no longer available.');
        }

        // Only a waiting match can be joined. Its X seat is already occupied,
        // so the joining player gets O and the first turn remains X's.
        $match->playerO = new GamePlayer($playerId, $username);
        $match->status = GameMatch::PLAYING;
        $match->updatedAt = $now;

        // PHP object variables refer to the same object. Updating $match also
        // updates the object in $this->matches; no array reference (&) is needed.
    }

    private function leaveMatch(string $playerId, int $now): void
    {
        $match = $this->findMatchForPlayer($playerId);

        if ($match === null) {
            // Leaving twice is harmless. The player has already left.
            return;
        }

        $symbol = $match->symbolForPlayer($playerId);

        if ($symbol === 'X') {
            $match->playerX = null;
        } else {
            $match->playerO = null;
        }

        if ($match->playerX === null && $match->playerO === null) {
            // Nobody needs this match anymore. Remove it from the registry.
            unset($this->matches[$match->id]);
            return;
        }

        // The remaining player can still see the board, but cannot keep playing.
        // They must leave this abandoned match before starting another one.
        $match->status = GameMatch::ABANDONED;
        $match->updatedAt = $now;
    }

    /** @param array<string, mixed> $message */
    private function makeMove(string $playerId, array $message, int $now): void
    {
        $match = $this->requireMatch($message);
        $playerSymbol = $match->symbolForPlayer($playerId);

        // Validate everything before changing the board. A failed request
        // throws a ProtocolException, leaving the match unchanged.
        if ($playerSymbol === null) {
            $this->fail('NOT_IN_GAME', 'You are not a player in this match.');
        }

        if ($match->status !== GameMatch::PLAYING) {
            $this->fail('GAME_NOT_PLAYING', 'This match is not in progress.');
        }

        $requestedRevision = $message['revision'] ?? null;

        // Strict comparison also rejects a string such as "2" instead of 2.
        if ($requestedRevision !== $match->revision) {
            $this->fail('STALE_MOVE', 'The board has changed. Try again.');
        }

        if ($playerSymbol !== $match->turn) {
            $this->fail('NOT_YOUR_TURN', 'Wait for your turn.');
        }

        $cell = $message['cell'] ?? null;

        if (!is_int($cell)) {
            $this->fail('INVALID_MOVE', 'Choose an empty square.');
        }

        // Check bounds before reading the array so an invalid index is never used.
        if ($cell < 0 || $cell > 8) {
            $this->fail('INVALID_MOVE', 'Choose an empty square.');
        }

        if ($match->board->cells[$cell] !== null) {
            $this->fail('INVALID_MOVE', 'Choose an empty square.');
        }

        // All checks passed. This is the point where the move becomes accepted.
        $match->board->cells[$cell] = $playerSymbol;
        $match->revision++;
        $match->updatedAt = $now;

        $this->updateResultAfterMove($match);
    }

    /** Decide whether the accepted move won, drew, or handed the turn over. */
    private function updateResultAfterMove(GameMatch $match): void
    {
        $winner = $match->board->findWinner();

        // Check a win BEFORE a full board: the ninth move can also be a win.
        if ($winner !== null) {
            $match->winner = $winner;
            $match->status = GameMatch::FINISHED;
            return;
        }

        if ($match->board->isFull()) {
            // No winner and no empty squares means a draw. winner stays null.
            $match->status = GameMatch::FINISHED;
            return;
        }

        // The game is still running. Give the other symbol the next turn.
        if ($match->turn === 'X') {
            $match->turn = 'O';
        } else {
            $match->turn = 'X';
        }
    }

    /**
     * Build the game selector and the list of matches that can still be joined.
     * This method returns JSON-ready data, not the registry's internal objects.
     *
     * @return array<string, mixed>
     */
    public function catalog(): array
    {
        $waitingMatches = [];

        foreach ($this->matches as $match) {
            if ($match->status !== GameMatch::WAITING) {
                continue;
            }

            $host = $match->playerX;

            if ($host === null) {
                continue;
            }

            $waitingMatches[] = [
                'id' => $match->id,
                'game' => $match->game,
                'host' => $host->name,
            ];
        }

        return [
            'type' => 'games',
            'games' => [
                ['id' => self::GAME, 'name' => 'Tic Tac Toe'],
            ],
            'matches' => $waitingMatches,
        ];
    }

    /**
     * Build a personalized browser message showing this player's current match.
     * Explicitly select public fields: never serialize an internal object as-is,
     * because GamePlayer contains a private-to-the-server identity ID.
     *
     * @return array<string, mixed>
     */
    public function snapshot(string $playerId): array
    {
        $match = $this->findMatchForPlayer($playerId);

        if ($match === null) {
            return [
                'type' => 'game_state',
                'match' => null,
            ];
        }

        $players = [];

        if ($match->playerX !== null) {
            $players['X'] = ['name' => $match->playerX->name];
        }

        if ($match->playerO !== null) {
            $players['O'] = ['name' => $match->playerO->name];
        }

        // Arrays are intentional here: Protocol::encode turns this public
        // message into the existing JSON shape expected by the JavaScript.
        // Building a new message does not mutate the match or its players.
        return [
            'type' => 'game_state',
            'match' => [
                'id' => $match->id,
                'game' => $match->game,
                'players' => $players,
                'board' => $match->board->cells,
                'turn' => $match->turn,
                'status' => $match->status,
                'winner' => $match->winner,
                'revision' => $match->revision,
            ],
            'symbol' => $match->symbolForPlayer($playerId),
        ];
    }

    /**
     * Remove matches idle for 30 minutes. Return true if anything was removed,
     * so the caller knows whether to broadcast updated catalogs and snapshots.
     * Disconnecting alone does not remove a seat; an identity can reclaim it
     * on reconnect while the match still exists.
     */
    public function expire(int $now): bool
    {
        $oldestAllowedUpdate = $now - self::IDLE_TIMEOUT_SECONDS;
        $removedAnyMatch = false;

        foreach ($this->matches as $matchId => $match) {
            if ($match->updatedAt <= $oldestAllowedUpdate) {
                unset($this->matches[$matchId]);
                $removedAnyMatch = true;
            }
        }

        return $removedAnyMatch;
    }

    /** Find the object containing this player, or null when they have no seat. */
    private function findMatchForPlayer(string $playerId): ?GameMatch
    {
        foreach ($this->matches as $match) {
            if ($match->symbolForPlayer($playerId) !== null) {
                return $match;
            }
        }

        return null;
    }

    private function requireAvailablePlayer(string $playerId): void
    {
        if ($this->findMatchForPlayer($playerId) !== null) {
            $this->fail('ALREADY_IN_GAME', 'Leave your current match first.');
        }
    }

    /** @param array<string, mixed> $message */
    private function requireSupportedGame(array $message): void
    {
        $requestedGame = $message['game'] ?? null;

        if ($requestedGame !== self::GAME) {
            $this->fail('UNKNOWN_GAME', 'That game is not available.');
        }
    }

    /** @param array<string, mixed> $message */
    private function requireMatch(array $message): GameMatch
    {
        $matchId = $message['matchId'] ?? null;

        if (!is_string($matchId)) {
            $this->fail('UNKNOWN_MATCH', 'This match does not exist.');
        }

        if (!isset($this->matches[$matchId])) {
            $this->fail('UNKNOWN_MATCH', 'This match does not exist.');
        }

        return $this->matches[$matchId];
    }

    /**
     * Throwing stops the current action immediately. LobbyServer catches this
     * exception and sends a nonfatal error to the browser instead of crashing.
     * The never return type tells PHP and the editor this method cannot return.
     */
    private function fail(string $code, string $message): never
    {
        throw new ProtocolException($code, $message, false, 'game');
    }
}
