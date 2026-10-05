<?php
declare(strict_types=1);

namespace Checkerboard\Lobby;

/** The board stores marks and knows how to recognize a win or a draw. */
final class GameBoard
{
    /**
     * This is a list, not an associative record, so an array suits it well.
     * Each square contains null (empty), 'X', or 'O'.
     *
     * The PHPDoc annotation helps an editor understand the list's contents.
     * PHP's array type alone does not specify the type of each element.
     *
     * Square indexes:
     *
     *   0 | 1 | 2
     *   ---------
     *   3 | 4 | 5
     *   ---------
     *   6 | 7 | 8
     *
     * @var list<string|null>
     */
    public array $cells;

    public function __construct()
    {
        // Create exactly nine empty squares, with indexes 0 through 8.
        $this->cells = array_fill(0, 9, null);
    }

    /** Return 'X' or 'O' when someone wins, otherwise return null. */
    public function findWinner(): ?string
    {
        // Check the three horizontal rows: (0,1,2), (3,4,5), (6,7,8).
        // The first square of each row is three positions after the last.
        for ($rowStart = 0; $rowStart < 9; $rowStart += 3) {
            $winner = $this->winnerOnLine($rowStart, $rowStart + 1, $rowStart + 2);

            if ($winner !== null) {
                return $winner;
            }
        }

        // Check the three vertical columns: (0,3,6), (1,4,7), (2,5,8).
        // Moving down one row adds three to a square's index.
        for ($column = 0; $column < 3; $column++) {
            $winner = $this->winnerOnLine($column, $column + 3, $column + 6);

            if ($winner !== null) {
                return $winner;
            }
        }

        // Check the diagonal from the top-left to the bottom-right.
        $winner = $this->winnerOnLine(0, 4, 8);

        if ($winner !== null) {
            return $winner;
        }

        // Check the diagonal from the top-right to the bottom-left.
        $winner = $this->winnerOnLine(2, 4, 6);

        if ($winner !== null) {
            return $winner;
        }

        // No winning line was found. The game may continue or be a draw.
        return null;
    }

    public function isFull(): bool
    {
        foreach ($this->cells as $mark) {
            if ($mark === null) {
                // One empty square is enough to prove the board is not full.
                return false;
            }
        }

        return true;
    }

    /** Check whether these three squares contain the same non-empty mark. */
    private function winnerOnLine(int $firstCell, int $secondCell, int $thirdCell): ?string
    {
        $firstMark = $this->cells[$firstCell];
        $secondMark = $this->cells[$secondCell];
        $thirdMark = $this->cells[$thirdCell];

        // Three empty squares are not a winning line.
        if ($firstMark === null) {
            return null;
        }

        // Strict comparison (===) requires the same value and the same type.
        if ($firstMark === $secondMark && $secondMark === $thirdMark) {
            return $firstMark;
        }

        return null;
    }
}
