<?php

declare(strict_types=1);

/**
 * Logger — File-based structured logger.
 *
 * Log files:
 *   logs/YYYY-MM-DD.log        — general app log
 *   logs/requests/YYYY-MM-DD.log — every request + response
 *   logs/errors/YYYY-MM-DD.log   — errors only
 *
 * Levels: debug < info < warning < error
 */
class Logger
{
    private static ?Logger $instance = null;
    private string $logDir;
    private string $configLevel;

    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    private function __construct()
    {
        $this->logDir      = rtrim(Config::get('log.dir', ROOT_PATH . '/logs/'), '/') . '/';
        $this->configLevel = Config::get('log.level', 'debug');

        foreach (['', 'requests', 'errors'] as $sub) {
            $dir = $this->logDir . $sub;
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            // Prevent direct web access
            $ht = $dir . '/.htaccess';
            if (!file_exists($ht)) {
                file_put_contents($ht, "Deny from all\n");
            }
        }
    }

    public static function getInstance(): static
    {
        if (self::$instance === null) {
            self::$instance = new static();
        }
        return self::$instance;
    }

    // ── Level methods ────────────────────────────────────────────────────────
    public function debug(string $message, array $context = []): void
    {
        $this->write('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        if (str_contains(strtolower($message), 'token') || str_contains(strtolower($message), 'auth')) {
            $this->writeToFile('errors/auth-' . date('Y-m-d'), $this->format('error', $message, $context));
        }
        $this->write('error', $message, $context);
        $this->writeToFile('errors/' . date('Y-m-d'), $this->format('error', $message, $context));
    }

    // ── Request / Response logging ───────────────────────────────────────────
    public function logRequest(array $requestData): void
    {
        $entry = $this->format('request', 'Incoming Request', $requestData);
        $this->writeToFile('requests/' . date('Y-m-d'), $entry);
        $this->write('info', 'Incoming Request', $requestData);
    }

    public function logResponse(array $responseData): void
    {
        $elapsed = round((microtime(true) - APP_START) * 1000, 2);
        $entry   = $this->format('response', 'Outgoing Response', array_merge($responseData, ['elapsed_ms' => $elapsed]));
        $this->writeToFile('requests/' . date('Y-m-d'), $entry);
    }

    // ── Core write ───────────────────────────────────────────────────────────
    private function write(string $level, string $message, array $context = []): void
    {
        if (!$this->shouldLog($level)) {
            return;
        }
        $line = $this->format($level, $message, $context);
        $this->writeToFile(date('Y-m-d'), $line);
    }

    private function format(string $level, string $message, array $context): string
    {
        $entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level'     => strtoupper($level),
            'message'   => $message,
            'context'   => $context,
            'doctor_id' => $this->getDoctorId(),
            'endpoint'  => $_SERVER['REQUEST_URI'] ?? '',
            'method'    => $_SERVER['REQUEST_METHOD'] ?? '',
            'request_id'=> $this->getRequestId(),
        ];
        return json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }


    private function getDoctorId(): ?int
    {
        try {
            if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
                $auth = Auth::getInstance();
                $user = $auth->getAuthenticatedUser();
                return $user['doctor_id'] ?? null;
            }
        } catch (Throwable $e) {
            return null;
        }

        return null;
    }

    private function writeToFile(string $filename, string $line): void
    {
        $path = $this->logDir . $filename . '.log';
        @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    private function shouldLog(string $level): bool
    {
        $minLevel = self::LEVELS[$this->configLevel] ?? 0;
        $thisLevel = self::LEVELS[$level] ?? 0;
        return $thisLevel >= $minLevel;
    }

    private function getRequestId(): string
    {
        static $id = null;
        if ($id === null) {
            $id = substr(md5(uniqid((string) mt_rand(), true)), 0, 12);
        }
        return $id;
    }

    private function __clone() {}
}
