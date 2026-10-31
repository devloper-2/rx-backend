<?php

declare(strict_types=1);

/**
 * Config — Central configuration for local & production environments.
 *
 * Values come from environment variables (set by Apache/hosting, the shell,
 * or a local `.env` file) with sensible per-environment fallbacks below so
 * that local XAMPP dev keeps working with zero setup.
 *
 * Precedence: real environment variable > `.env` file > hardcoded default.
 *
 * Which environment is active is controlled by APP_ENV (local|production).
 * No APP_ENV set at all → 'local' (XAMPP dev, unchanged from before).
 */
class Config
{
    private static bool $envLoaded = false;

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $settings = null;

    // ── .env loading ─────────────────────────────────────────────────────────
    /**
     * Loads ROOT_PATH/.env (if present) into getenv()/$_ENV without
     * overriding variables already set by the real environment (Apache,
     * shell, CI). Safe to call multiple times.
     */
    private static function loadEnv(): void
    {
        if (self::$envLoaded) {
            return;
        }
        self::$envLoaded = true;

        $path = ROOT_PATH . '/.env';
        if (!is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);

            // Strip matching surrounding quotes
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last  = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            // Don't let the .env file clobber a real environment variable
            if (getenv($key) === false) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
        }
    }

    /** Reads an env var, trimming and falling back to $default. */
    private static function envVar(string $key, mixed $default = null): mixed
    {
        self::loadEnv();
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }
        return $value;
    }

    private static function envBool(string $key, bool $default): bool
    {
        $value = self::envVar($key);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    private static function envInt(string $key, int $default): int
    {
        $value = self::envVar($key);
        return $value === null ? $default : (int) $value;
    }

    // ── Settings ────────────────────────────────────────────────────────────
    private static function buildSettings(): array
    {
        $env = self::envVar('APP_ENV', 'local');

        // Shared defaults; 'local' mirrors the original XAMPP dev values so
        // that nothing changes for local dev when no .env file is present.
        $baseUrl = self::envVar('APP_BASE_URL', $env === 'production'
            ? 'https://prescriptionrx.in'
            : 'http://localhost/prescription-rx/rx-backend');

        $isProduction = $env === 'production';

        return [
            $env => [
                'app' => [
                    'debug'    => self::envBool('APP_DEBUG', !$isProduction),
                    'timezone' => self::envVar('APP_TIMEZONE', 'UTC'),
                    'base_url' => rtrim((string) $baseUrl, '/'),
                    'version'  => self::envVar('APP_VERSION', 'v1'),
                    // SMS/e-mail delivery is not wired up yet. While true, OTP
                    // responses include `dev_otp` so the app can be tested end
                    // to end. MUST be false in production — defaults to false
                    // unless APP_ENV=local (or EXPOSE_DEV_OTP is explicitly set).
                    'expose_dev_otp' => self::envBool('EXPOSE_DEV_OTP', !$isProduction),
                ],
                'db' => [
                    'host'    => self::envVar('DB_HOST', 'localhost'),
                    'port'    => self::envInt('DB_PORT', 3306),
                    'name'    => self::envVar('DB_NAME', 'php_rest_api'),
                    'user'    => self::envVar('DB_USER', 'root'),
                    'pass'    => self::envVar('DB_PASS', ''),
                    'charset' => self::envVar('DB_CHARSET', 'utf8mb4'),
                ],
                'jwt' => [
                    'secret'         => self::envVar('JWT_SECRET', $isProduction
                        ? ''
                        : 'LOCAL_SECRET_CHANGE_THIS_32CHARS!!'),
                    'algo'           => self::envVar('JWT_ALGO', 'HS256'),
                    'expiry'         => self::envInt('JWT_EXPIRY', 3600),       // 1 hour
                    'refresh_expiry' => self::envInt('JWT_REFRESH_EXPIRY', 604800), // 7 days
                ],
                'upload' => [
                    'max_size'     => self::envInt('UPLOAD_MAX_SIZE', 5242880), // 5 MB
                    'allowed_mime' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'],
                    'allowed_ext'  => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'],
                    'upload_dir'   => ROOT_PATH . '/uploads/',
                    'base_url'     => rtrim((string) $baseUrl, '/') . '/uploads/',
                ],
                'log' => [
                    'dir'   => ROOT_PATH . '/logs/',
                    'level' => self::envVar('LOG_LEVEL', $isProduction ? 'error' : 'debug'),
                ],
                'rate_limit' => [
                    'storage' => self::envVar('RATE_LIMIT_STORAGE', 'db'),
                ],
                // SMS / e-mail provider keys (not wired up yet — reserved for
                // when real OTP delivery is added). Read from env so secrets
                // never live in source.
                'sms' => [
                    'provider'   => self::envVar('SMS_PROVIDER', ''),
                    'api_key'    => self::envVar('SMS_API_KEY', ''),
                    'api_secret' => self::envVar('SMS_API_SECRET', ''),
                ],
                'mail' => [
                    'host'     => self::envVar('MAIL_HOST', ''),
                    'port'     => self::envInt('MAIL_PORT', 587),
                    'user'     => self::envVar('MAIL_USER', ''),
                    'pass'     => self::envVar('MAIL_PASS', ''),
                    'from'     => self::envVar('MAIL_FROM', ''),
                ],
            ],
        ];
    }

    private static function env2(): string
    {
        return (string) self::envVar('APP_ENV', 'local');
    }

    // ── Dot-notation getter ──────────────────────────────────────────────────
    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$settings === null) {
            self::$settings = self::buildSettings();
        }

        $env  = self::env2();
        $data = self::$settings[$env] ?? [];

        foreach (explode('.', $key) as $segment) {
            if (!is_array($data) || !array_key_exists($segment, $data)) {
                return $default;
            }
            $data = $data[$segment];
        }
        return $data;
    }

    public static function isDevelopment(): bool
    {
        return self::env2() !== 'production';
    }

    public static function env(): string
    {
        return self::env2();
    }
}
