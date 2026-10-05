<?php
declare(strict_types=1);

namespace Checkerboard\Lobby;

use PDO;
use RuntimeException;

final class Database
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        if ($path !== ':memory:') {
            $mode = (string) $this->pdo->query('PRAGMA journal_mode = WAL')->fetchColumn();
            if (strtolower($mode) !== 'wal') {
                throw new RuntimeException('SQLite could not enable WAL journal mode.');
            }
            $this->pdo->exec('PRAGMA synchronous = NORMAL');
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function migrate(string $directory): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version TEXT PRIMARY KEY, applied_at TEXT NOT NULL)');
        $files = glob(rtrim($directory, '/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $version = basename($file);
            $statement = $this->pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
            $statement->execute(['version' => $version]);
            if ($statement->fetchColumn()) {
                continue;
            }
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException('Unable to read a database migration.');
            }
            $this->pdo->beginTransaction();
            try {
                $this->pdo->exec($sql);
                $insert = $this->pdo->prepare('INSERT INTO schema_migrations(version, applied_at) VALUES(:version, :applied_at)');
                $insert->execute(['version' => $version, 'applied_at' => gmdate('Y-m-d\TH:i:s\Z')]);
                $this->pdo->commit();
            } catch (\Throwable $error) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $error;
            }
        }
    }
}
