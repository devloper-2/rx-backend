<?php

/**
 * Migration Entry Point
 * ---------------------
 * Usage (from project root):
 *   php migrations/run.php           → run pending migrations
 *   php migrations/run.php rollback  → rollback last batch
 *   php migrations/run.php status    → show migration history
 *
 * ⚠️  NEVER expose this file on a public web URL in production.
 *     Add to .htaccess: RewriteRule ^migrations/ - [F,L]
 */

define('ROOT_PATH', dirname(__DIR__));

require_once __DIR__ . '/Runner.php';

$command = $argv[1] ?? 'run';

$runner = new MigrationRunner();

switch ($command) {
    case 'run':
    case 'migrate':
        $runner->run();
        break;

    case 'rollback':
        $runner->rollback();
        break;

    case 'status':
        $runner->status();
        break;

    default:
        echo "Unknown command: {$command}" . PHP_EOL;
        echo "Available: run | rollback | status" . PHP_EOL;
        exit(1);
}
