<?php

declare(strict_types=1);

/**
 * Migration Runner — Tracks and runs DB migrations.
 *
 * How it works:
 *   1. Creates a `migrations` table if it doesn't exist.
 *   2. Scans migrations/versions/ for files named NNN_*.php
 *   3. Runs only those not yet recorded in the migrations table.
 *   4. Records each successful migration.
 *
 * Run from CLI:  php migrations/run.php
 * Run from web:  (not recommended in production — protect run.php)
 */
class MigrationRunner
{
    private PDO    $pdo;
    private string $versionsDir;

    public function __construct()
    {
        $this->versionsDir = __DIR__ . '/versions/';
        $this->pdo         = $this->connect();
        $this->ensureMigrationsTable();
    }

    // ── Public API ───────────────────────────────────────────────────────────
    public function run(): void
    {
        $files   = $this->getPendingFiles();
        $count   = 0;

        if (empty($files)) {
            $this->log('✅  No pending migrations.');
            return;
        }

        foreach ($files as $file) {
            $name = basename($file, '.php');
            $this->log("▶  Running: {$name}");

            try {
                $this->pdo->beginTransaction();

                $migration = require $file;

                if (!is_array($migration) || !isset($migration['up'])) {
                    throw new RuntimeException("Migration file '{$name}' must return ['up' => fn(PDO), 'down' => fn(PDO)]");
                }

                ($migration['up'])($this->pdo);

                $stmt = $this->pdo->prepare(
                    'INSERT INTO migrations (name, batch, ran_at) VALUES (:name, :batch, :ran_at)'
                );
                $stmt->execute([
                    ':name'   => $name,
                    ':batch'  => $this->getNextBatch(),
                    ':ran_at' => date('Y-m-d H:i:s'),
                ]);

                $this->pdo->commit();
                $this->log("   ✅  Done: {$name}");
                $count++;

            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $this->log("   ❌  Failed: {$name} — " . $e->getMessage());
                break; // Stop on first failure
            }
        }

        $this->log("\n🏁  {$count} migration(s) ran successfully.");
    }

    public function rollback(): void
    {
        $batch = $this->getLatestBatch();
        if ($batch === 0) {
            $this->log('Nothing to rollback.');
            return;
        }

        $stmt = $this->pdo->prepare('SELECT name FROM migrations WHERE batch = :batch ORDER BY id DESC');
        $stmt->execute([':batch' => $batch]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $file = $this->versionsDir . $row['name'] . '.php';
            if (!file_exists($file)) {
                $this->log("⚠️  File not found: {$row['name']}");
                continue;
            }

            $migration = require $file;
            if (isset($migration['down'])) {
                try {
                    $this->pdo->beginTransaction();
                    ($migration['down'])($this->pdo);
                    $this->pdo->prepare('DELETE FROM migrations WHERE name = :name')
                              ->execute([':name' => $row['name']]);
                    $this->pdo->commit();
                    $this->log("↩️  Rolled back: {$row['name']}");
                } catch (Throwable $e) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    $this->log("❌  Rollback failed: {$row['name']} — " . $e->getMessage());
                }
            }
        }
    }

    public function status(): void
    {
        $stmt = $this->pdo->query('SELECT name, batch, ran_at FROM migrations ORDER BY id ASC');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            $this->log('No migrations have been run yet.');
            return;
        }

        $this->log(str_pad('Migration', 50) . str_pad('Batch', 8) . 'Ran At');
        $this->log(str_repeat('-', 80));
        foreach ($rows as $row) {
            $this->log(str_pad($row['name'], 50) . str_pad((string)$row['batch'], 8) . $row['ran_at']);
        }
    }

    // ── Internals ────────────────────────────────────────────────────────────
    private function connect(): PDO
    {
        require_once dirname(__DIR__) . '/config/Config.php';

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            Config::get('db.host'),
            Config::get('db.port', 3306),
            Config::get('db.name')
        );

        return new PDO($dsn, Config::get('db.user'), Config::get('db.pass'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    private function ensureMigrationsTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS `migrations` (
                `id`      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `name`    VARCHAR(255) NOT NULL UNIQUE,
                `batch`   INT UNSIGNED NOT NULL DEFAULT 1,
                `ran_at`  DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    private function getPendingFiles(): array
    {
        $files = glob($this->versionsDir . '*.php');
        if (!$files) return [];

        sort($files);

        $stmt = $this->pdo->query("SELECT name FROM migrations");
        $ran  = array_column($stmt->fetchAll(), 'name');

        return array_filter($files, function (string $file) use ($ran): bool {
            return !in_array(basename($file, '.php'), $ran, true);
        });
    }

    private function getNextBatch(): int
    {
        $stmt = $this->pdo->query('SELECT COALESCE(MAX(batch), 0) AS b FROM migrations');
        return (int) $stmt->fetch()['b'] + 1;
    }

    private function getLatestBatch(): int
    {
        $stmt = $this->pdo->query('SELECT COALESCE(MAX(batch), 0) AS b FROM migrations');
        return (int) $stmt->fetch()['b'];
    }

    private function log(string $message): void
    {
        echo $message . PHP_EOL;
    }
}
