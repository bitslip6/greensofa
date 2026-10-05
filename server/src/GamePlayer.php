<?php
declare(strict_types=1);

namespace Checkerboard\Lobby;

/**
 * One registered player occupying a seat in a match.
 *
 * Named, typed properties let an editor suggest $player->id and $player->name.
 * The internal ID identifies the player even when their socket reconnects.
 */
final class GamePlayer
{
    // public lets other server objects read these properties.
    // readonly means they can be initialized once, but not changed afterward.
    // Neither property is automatically sent to the browser: GameRooms builds
    // a separate public message containing the name but not the internal ID.
    public readonly string $id;
    public readonly string $name;

    public function __construct(string $id, string $name)
    {
        // $this refers to the object being created. The parameters on the right
        // come from the registered connection; the properties on the left
        // retain those values for the lifetime of this player object.
        $this->id = $id;
        $this->name = $name;
    }
}
