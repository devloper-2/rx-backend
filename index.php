<?php

declare(strict_types=1);

/**
 * PHP REST API - Entry Point
 * Supports: domain.com/user/login → UserController::login()
 */

define('ROOT_PATH', __DIR__);
define('APP_START', microtime(true));

// ─── Autoloader ────────────────────────────────────────────────────────────
spl_autoload_register(function (string $class): void {
    $paths = [
        ROOT_PATH . '/core/'        . $class . '.php',
        ROOT_PATH . '/helpers/'     . $class . '.php',
        ROOT_PATH . '/app/controllers/' . $class . '.php',
        ROOT_PATH . '/config/'      . $class . '.php',
        ROOT_PATH . '/migrations/'  . $class . '.php',
    ];
    foreach ($paths as $file) {
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// ─── Bootstrap ─────────────────────────────────────────────────────────────
require_once ROOT_PATH . '/config/Config.php';

// Timezone
date_default_timezone_set(Config::get('app.timezone', 'UTC'));

// Error handling based on environment
if (Config::isDevelopment()) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}

// ─── Global exception handler ──────────────────────────────────────────────
set_exception_handler(function (Throwable $e): void {
    $logger = Logger::getInstance();
    $logger->error('Unhandled Exception', [
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
        'trace'   => $e->getTraceAsString(),
    ]);

    $debug = Config::isDevelopment();
    Response::send(500, [], 'Internal Server Error', false, $debug ? [
        'exception' => $e->getMessage(),
        'file'      => $e->getFile(),
        'line'      => $e->getLine(),
    ] : []);
});

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// ─── CORS Headers ──────────────────────────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ─── Load Routes & Dispatch ────────────────────────────────────────────────
$router = Router::getInstance();
require_once ROOT_PATH . '/routes/api.php';
$router->dispatch();
