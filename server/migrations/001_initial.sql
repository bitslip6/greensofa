CREATE TABLE IF NOT EXISTS players (
    id TEXT PRIMARY KEY,
    username TEXT NOT NULL,
    username_normalized TEXT NOT NULL,
    token_digest TEXT NOT NULL,
    created_at TEXT NOT NULL,
    last_seen_at TEXT NOT NULL,
    reserved_until TEXT NOT NULL,
    released_at TEXT NULL,
    CHECK (length(username) BETWEEN 2 AND 20),
    CHECK (username_normalized = lower(username_normalized))
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_players_active_username
    ON players(username_normalized) WHERE released_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_players_reservation_expiry
    ON players(reserved_until) WHERE released_at IS NULL;

CREATE TABLE IF NOT EXISTS sessions (
    id TEXT PRIMARY KEY,
    player_id TEXT NULL REFERENCES players(id),
    fd INTEGER NULL,
    remote_address TEXT NULL,
    connected_at TEXT NOT NULL,
    registered_at TEXT NULL,
    disconnected_at TEXT NULL,
    close_code INTEGER NULL,
    close_reason TEXT NULL,
    registration_completed INTEGER NOT NULL DEFAULT 0 CHECK (registration_completed IN (0, 1))
);

CREATE INDEX IF NOT EXISTS idx_sessions_player_time ON sessions(player_id, connected_at DESC);
CREATE INDEX IF NOT EXISTS idx_sessions_connected_at ON sessions(connected_at DESC);

CREATE TABLE IF NOT EXISTS messages (
    id TEXT PRIMARY KEY,
    room TEXT NOT NULL,
    player_id TEXT NOT NULL REFERENCES players(id),
    sender_username TEXT NOT NULL,
    content TEXT NOT NULL,
    request_id TEXT NULL,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_messages_room_history ON messages(room, created_at DESC, id DESC);
CREATE INDEX IF NOT EXISTS idx_messages_player ON messages(player_id, created_at DESC);

CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    occurred_at TEXT NOT NULL,
    severity TEXT NOT NULL CHECK (severity IN ('debug', 'info', 'warning', 'error')),
    code TEXT NOT NULL,
    session_id TEXT NULL REFERENCES sessions(id),
    player_id TEXT NULL REFERENCES players(id),
    context_json TEXT NOT NULL DEFAULT '{}'
);

CREATE INDEX IF NOT EXISTS idx_events_time ON events(occurred_at DESC);
CREATE INDEX IF NOT EXISTS idx_events_code_time ON events(code, occurred_at DESC);
CREATE INDEX IF NOT EXISTS idx_events_player_time ON events(player_id, occurred_at DESC);
