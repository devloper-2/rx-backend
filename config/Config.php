<?php

declare(strict_types=1);

/**
 * Config — Central configuration for local & production environments.
 * Switch ENV constant to toggle all settings at once.
 */
class Config
{
    // ── Set to 'production' when deploying ──────────────────────────────────
    const ENV = 'local';

    private static array $settings = [

        // ── LOCAL / DEVELOPMENT ─────────────────────────────────────────────
        'local' => [
            'app' => [
                'debug'    => true,
                'timezone' => 'UTC',
                'base_url' => 'http://localhost/php-rest-api',
                'version'  => 'v1',
            ],
            'db' => [
                'host'    => 'localhost',
                'port'    => 3306,
                'name'    => 'php_rest_api',
                'user'    => 'root',
                'pass'    => '',
                'charset' => 'utf8mb4',
            ],
            'jwt' => [
                'secret'          => 'LOCAL_SECRET_CHANGE_THIS_32CHARS!!',
                'algo'            => 'HS256',
                'expiry'          => 3600,        // 1 hour (seconds)
                'refresh_expiry'  => 604800,      // 7 days
            ],
            'upload' => [
                'max_size'      => 5242880,   // 5 MB
                'allowed_mime'  => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'],
                'allowed_ext'   => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'],
                'upload_dir'    => ROOT_PATH . '/uploads/',
                'base_url'      => 'http://localhost/php-rest-api/uploads/',
            ],
            'log' => [
                'dir'   => ROOT_PATH . '/logs/',
                'level' => 'debug',   // debug | info | warning | error
            ],
            'rate_limit' => [
                'storage' => 'db',   // db | file
            ],
        ],

        // ── PRODUCTION ──────────────────────────────────────────────────────
        'production' => [
            'app' => [
                'debug'    => false,
                'timezone' => 'UTC',
                'base_url' => 'https://yourdomain.com',
                'version'  => 'v1',
            ],
            'db' => [
                'host'    => 'localhost',
                'port'    => 3306,
                'name'    => 'your_prod_db',
                'user'    => 'your_prod_user',
                'pass'    => 'your_prod_password',
                'charset' => 'utf8mb4',
            ],
            'jwt' => [
                'secret'          => 'PRODUCTION_SECRET_REPLACE_WITH_STRONG_KEY!!',
                'algo'            => 'HS256',
                'expiry'          => 3600,
                'refresh_expiry'  => 604800,
            ],
            'upload' => [
                'max_size'      => 5242880,
                'allowed_mime'  => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'],
                'allowed_ext'   => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'],
                'upload_dir'    => ROOT_PATH . '/uploads/',
                'base_url'      => 'https://yourdomain.com/uploads/',
            ],
            'log' => [
                'dir'   => ROOT_PATH . '/logs/',
                'level' => 'error',
            ],
            'rate_limit' => [
                'storage' => 'db',
            ],
        ],
    ];

    // ── Dot-notation getter ──────────────────────────────────────────────────
    public static function get(string $key, mixed $default = null): mixed
    {
        $env  = self::ENV;
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
        return self::ENV === 'local';
    }

    public static function env(): string
    {
        return self::ENV;
    }
}
