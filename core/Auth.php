<?php

declare(strict_types=1);

/**
 * Auth — Pure-PHP JWT (HS256) implementation.
 *
 * generateToken($payload)            → access token string
 * validateToken($token)              → decoded payload array or throws
 * generateRefreshToken($userId)      → refresh token string (stored in DB)
 * refreshAccessToken($refreshToken)  → new access token or throws
 * revokeRefreshToken($token)         → bool
 * getAuthenticatedUser()             → user array from current request
 */
class Auth
{
    private static ?Auth $instance = null;
    private Database $db;
    private Logger $logger;
    private string $secret;
    private int $expiry;
    private int $refreshExpiry;

    private function __construct()
    {
        $this->db           = Database::getInstance();
        $this->logger       = Logger::getInstance();
        $this->secret       = Config::get('jwt.secret');
        $this->expiry       = (int) Config::get('jwt.expiry', 3600);
        $this->refreshExpiry = (int) Config::get('jwt.refresh_expiry', 604800);
    }

    public static function getInstance(): static
    {
        if (self::$instance === null) {
            self::$instance = new static();
        }
        return self::$instance;
    }

    // ── Token generation ─────────────────────────────────────────────────────
    /**
     * Generate an access JWT.
     * @param  array $payload Extra claims to embed (e.g. ['user_id' => 1, 'role' => 'admin'])
     */
    public function generateToken(array $payload): string
    {
        $now = time();
        $claims = array_merge($payload, [
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $this->expiry,
            'jti' => $this->generateJti(),
        ]);

        $header    = $this->base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body      = $this->base64UrlEncode(json_encode($claims));
        $signature = $this->sign($header . '.' . $body);

        return $header . '.' . $body . '.' . $signature;
    }

    // ── Token validation ─────────────────────────────────────────────────────
    /**
     * Validate and decode a JWT.
     * @throws RuntimeException on invalid / expired token
     * @return array Decoded payload
     */
    public function validateToken(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('Invalid token format', 401);
        }

        [$encodedHeader, $encodedBody, $providedSig] = $parts;

        // Verify signature
        $expectedSig = $this->sign($encodedHeader . '.' . $encodedBody);
        if (!hash_equals($expectedSig, $providedSig)) {
            throw new RuntimeException('Invalid token signature', 401);
        }

        $payload = json_decode($this->base64UrlDecode($encodedBody), true);
        if (!is_array($payload)) {
            throw new RuntimeException('Invalid token payload', 401);
        }

        $now = time();

        if (isset($payload['nbf']) && $now < $payload['nbf']) {
            throw new RuntimeException('Token not yet valid', 401);
        }

        if (isset($payload['exp']) && $now > $payload['exp']) {
            throw new RuntimeException('Token has expired', 401);
        }

        return $payload;
    }

    // ── Refresh tokens ───────────────────────────────────────────────────────
    /**
     * Generate a refresh token, persist it in the DB.
     */
    public function generateRefreshToken(int $userId): string
    {
        $token     = bin2hex(random_bytes(40));
        $expiresAt = date('Y-m-d H:i:s', time() + $this->refreshExpiry);

        $this->db->insert('refresh_tokens', [
            'user_id'    => $userId,
            'token'      => hash('sha256', $token),
            'expires_at' => $expiresAt,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $token;
    }

    /**
     * Exchange a valid refresh token for a new access token.
     * @throws RuntimeException on invalid / expired refresh token
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        $hashed = hash('sha256', $refreshToken);
        $row    = $this->db->getRow(
            'SELECT rt.*, u.id AS user_id, u.role, u.status
             FROM refresh_tokens rt
             JOIN users u ON u.id = rt.user_id
             WHERE rt.token = :token AND rt.revoked = 0',
            [':token' => $hashed]
        );

        if (!$row) {
            throw new RuntimeException('Invalid refresh token', 401);
        }

        if (strtotime($row['expires_at']) < time()) {
            throw new RuntimeException('Refresh token expired', 401);
        }

        if ($row['status'] !== 'active') {
            throw new RuntimeException('Account is not active', 403);
        }

        $accessToken = $this->generateToken([
            'user_id' => (int) $row['user_id'],
            'role'    => $row['role'],
        ]);

        return ['access_token' => $accessToken, 'token_type' => 'Bearer'];
    }

    /** Revoke a refresh token (logout). */
    public function revokeRefreshToken(string $refreshToken): bool
    {
        $hashed  = hash('sha256', $refreshToken);
        $updated = $this->db->update(
            'refresh_tokens',
            ['revoked' => 1],
            'token = :token',
            [':token' => $hashed]
        );
        return $updated > 0;
    }

    // ── Request helper ───────────────────────────────────────────────────────
    /**
     * Extract and validate the Bearer token from the current request.
     * Returns decoded payload array or throws on failure.
     */
    public function getAuthenticatedUser(): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

        // Fallback to getallheaders() for Apache
        if (empty($header) && function_exists('getallheaders')) {
            $allHeaders = getallheaders();
            $header = $allHeaders['Authorization'] ?? '';
        }

        if (empty($header)) {
            throw new RuntimeException('Authorization header missing', 401);
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            throw new RuntimeException('Invalid Authorization header format', 401);
        }

        return $this->validateToken($m[1]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────
    private function sign(string $data): string
    {
        return $this->base64UrlEncode(
            hash_hmac('sha256', $data, $this->secret, true)
        );
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }

    private function generateJti(): string
    {
        return bin2hex(random_bytes(8));
    }

    private function __clone() {}
}
