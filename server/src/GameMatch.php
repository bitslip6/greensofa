<?php
declare(strict_types=1);

namespace Checkerboard\Lobby;

/**
 * The server's state for one Tic Tac Toe match.
 *
 * GameRooms manages joining/leaving and validates moves. This object groups
 * the match's data under named properties instead of untyped array keys.
 */
final class GameMatch
{
    public const WAITING = 'waiting';
    public const PLAYING = 'playing';
    public const FINISHED = 'finished';
    public const ABANDONED = 'abandoned';

    public readonly string $id;
    public readonly string $game;
    public GameBoard $board;

    // A nullable property (?GamePlayer) is either a player object or null.
    // Null means that nobody currently occupies that seat.
    public ?GamePlayer $playerX;
    public ?GamePlayer $playerO = null;

    public string $turn = 'X';
    public string $status = self::WAITING;
    public ?string $winner = null;

    // Every accepted move increases the revision by one. Clients send the
    // revision they saw so the server can reject an outdated move request.
    public int $revision = 0;

    // Unix timestamp of the last successful game action, used for expiry.
    public int $updatedAt;

    public function __construct(string $id, string $game, GamePlayer $creator, int $now)
    {
        $this->id = $id;
        $this->game = $game;
        $this->board = new GameBoard();
        $this->playerX = $creator;
        $this->updatedAt = $now;
    }

    /** Return this player's seat, or null if the player is not in this match. */
    public function symbolForPlayer(string $playerId): ?string
    {
        if ($this->playerX !== null && $this->playerX->id === $playerId) {
            return 'X';
        }

        if ($this->playerO !== null && $this->playerO->id === $playerId) {
            return 'O';
        }

        return null;
    }
}
