<?php

declare(strict_types=1);

/**
 * RateLimit — Per-endpoint, per-IP rate limiting stored in MySQL.
 *
 * Usage in route definition:
 *   'rate_limit' => ['max' => 5, 'window' => 3600]  // 5 requests per hour
 *
 * check($key, $max, $window)  → throws RuntimeException if limit exceeded
 */
class RateLimit
{
    private static ?RateLimit $instance = null;
    private Database $db;
    private Logger $logger;

    private function __construct()
    {
        $this->db     = Database::getInstance();
        $this->logger = Logger::getInstance();
    }

    public static function getInstance(): static
    {
        if (self::$instance === null) {
            self::$instance = new static();
        }
        return self::$instance;
    }

    // ── Main check ───────────────────────────────────────────────────────────
    /**
     * Check and increment the rate-limit counter.
     *
     * @param  string $endpoint  Route identifier e.g. "auth/login"
     * @param  int    $max       Maximum allowed requests in window
     * @param  int    $window    Window size in seconds
     * @throws RuntimeException  HTTP 429 when limit exceeded
     */
    public function check(string $endpoint, int $max, int $window): void
    {
        $ip        = $this->getClientIp();
        $doctorId = $this->getDoctorId() ?? null;
        $keyBase  = $doctorId ? "doctor:{$doctorId}" : "ip:{$ip}";
        $key      = md5($endpoint . '|' . $keyBase);
        $windowStart = date('Y-m-d H:i:s', time() - $window);

        // Purge old entries (housekeeping — 1% chance per request)
        if (random_int(1, 100) === 1) {
            $this->purgeOld();
        }

        // Count existing hits in this window
        $row = $this->db->getRow(
            'SELECT hits, window_start FROM rate_limits WHERE `key` = :key AND doctor_id <=> :doctor_id',
            [':key' => $key, ':doctor_id' => $doctorId]
        );

        if (!$row) {
            // First request — insert fresh counter
            $this->db->insert('rate_limits', [
                'doctor_id'    => $doctorId,
                'key'          => $key,
                'endpoints'    => $endpoint,
                'ip'           => $ip,
                'hits'         => 1,
                'window_start' => date('Y-m-d H:i:s'),
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);
            return;
        }

        // If the stored window has expired, reset it
        if (strtotime($row['window_start']) < (time() - $window)) {
            $this->db->update(
                'rate_limits',
                [
                    'hits'         => 1,
                    'window_start' => date('Y-m-d H:i:s'),
                    'updated_at'   => date('Y-m-d H:i:s'),
                ],
                '`key` = :key AND doctor_id <=> :doctor_id',
                [
                    ':key' => $key,
                    ':doctor_id' => $doctorId
                ]
            );
            return;
        }

        // Within the window — check limit
        $hits = (int) $row['hits'];
        if ($hits >= $max) {
            $resetAt  = strtotime($row['window_start']) + $window;
            $retryAfter = max(0, $resetAt - time());

           $this->logger->warning('Rate limit exceeded', [
                'doctor_id' => $doctorId,
                'ip'        => $ip,
                'endpoint'  => $endpoint,
                'hits'      => $hits,
                'max'       => $max,
            ]);

            header('Retry-After: ' . $retryAfter);
            header('X-RateLimit-Limit: ' . $max);
            header('X-RateLimit-Remaining: 0');
            header('X-RateLimit-Reset: ' . $resetAt);

            throw new RuntimeException(
                "Rate limit exceeded. Try again in {$retryAfter} seconds.",
                429
            );
        }

        // Increment counter
        $this->db->executeQuery(
            'UPDATE rate_limits SET hits = hits + 1, updated_at = :now WHERE `key` = :key AND doctor_id <=> :doctor_id',
            [':now' => date('Y-m-d H:i:s'), ':key' => $key, ':doctor_id' => $doctorId]
        );

        $remaining = $max - $hits - 1;
        header('X-RateLimit-Limit: ' . $max);
        header('X-RateLimit-Remaining: ' . max(0, $remaining));
        header('X-RateLimit-Reset: ' . (strtotime($row['window_start']) + $window));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

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

    private function getClientIp(): string
    {
        $candidates = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR',
        ];
        foreach ($candidates as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(explode(',', $_SERVER[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    private function purgeOld(): void
    {
        // Remove entries older than 25 hours to keep table clean
        $this->db->executeQuery(
            "DELETE FROM rate_limits WHERE updated_at < DATE_SUB(NOW(), INTERVAL 25 HOUR)"
        );
    }

    private function __clone() {}
}
