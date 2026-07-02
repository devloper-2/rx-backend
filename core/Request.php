<?php

declare(strict_types=1);

/**
 * Request — Parses and provides clean access to the current HTTP request.
 */
class Request
{
    private static ?Request $instance = null;

    private string $method;
    private string $uri;
    private array  $query;
    private array  $body;
    private array  $headers;
    private array  $files;

    private function __construct()
    {
        $this->method  = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->uri     = $this->parseUri();
        $this->query   = $_GET ?? [];
        $this->body    = $this->parseBody();
        $this->headers = $this->parseHeaders();
        $this->files   = $_FILES ?? [];
    }

    public static function getInstance(): static
    {
        if (self::$instance === null) {
            self::$instance = new static();
        }
        return self::$instance;
    }

    // ── Parsing ──────────────────────────────────────────────────────────────
    private function parseUri(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        
        // Strip query string
        if (($pos = strpos($uri, '?')) !== false) {
            $uri = substr($uri, 0, $pos);
        }
        
        // Strip base path (for subdirectory installations)
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        $basePath   = dirname($scriptName);
        if ($basePath !== '/' && strpos($uri, $basePath) === 0) {
            $uri = substr($uri, strlen($basePath));
        }
        
        return '/' . trim(rawurldecode($uri), '/');
    }

    private function parseBody(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        // JSON body
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }

        // Form data (POST, PUT, PATCH via _method or direct)
        if (in_array($this->method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            $raw = file_get_contents('php://input');
            if (!empty($raw) && !str_contains($contentType, 'multipart/form-data')) {
                parse_str($raw, $parsed);
                return is_array($parsed) ? $parsed : [];
            }
            return $_POST ?? [];
        }

        return [];
    }

    private function parseHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $val) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[strtolower($name)] = $val;
            }
        }
        // Special keys
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = $_SERVER['CONTENT_LENGTH'];
        }
        return $headers;
    }

    // ── Accessors ────────────────────────────────────────────────────────────
    public function method(): string   { return $this->method; }
    public function uri(): string      { return $this->uri; }
    public function all(): array       { return array_merge($this->query, $this->body); }
    public function body(): array      { return $this->body; }
    public function query(): array     { return $this->query; }
    public function files(): array     { return $this->files; }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function header(string $key, mixed $default = null): mixed
    {
        return $this->headers[strtolower($key)] ?? $default;
    }

    public function file(string $key): ?array
    {
        return isset($this->files[$key]) ? $this->files[$key] : null;
    }

    public function hasFile(string $key): bool
    {
        return isset($this->files[$key]) && $this->files[$key]['error'] !== UPLOAD_ERR_NO_FILE;
    }

    public function isJson(): bool
    {
        return str_contains($this->header('content-type', ''), 'application/json');
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization', '');
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return $m[1];
        }
        return null;
    }

    /** Sanitized + typed summary for logging (masks sensitive keys). */
    public function toLogArray(): array
    {
        $sensitive = ['password', 'password_confirmation', 'token', 'secret', 'card_number', 'cvv'];
        //$data      = $this->all();
        $data = CommonHelper::sanitizeArray($this->all());

        foreach ($sensitive as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = '***';
            }
        }

        return [
            'method'  => $this->method,
            'uri'     => $this->uri,
            'ip' => $this->getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'params'  => $data,
            'files'   => array_keys($this->files),
        ];
    }


    public function doctorId(): ?int
    {
        try {
            $token = $this->bearerToken();
            if ($token) {
                $auth = Auth::getInstance();
                $user = $auth->validateToken($token);
                return $user['doctor_id'] ?? null;
            }
        } catch (Throwable $e) {
            return null;
        }

        return null;
    }


    private function getClientIp(): string
    {
        $keys = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'REMOTE_ADDR'
        ];

        foreach ($keys as $key) {
            if (!empty($_SERVER[$key])) {
                return trim(explode(',', $_SERVER[$key])[0]);
            }
        }

        return '0.0.0.0';
    }

    public function requestId(): string
    {
        static $id = null;

        if ($id === null) {
            $id = substr(md5(uniqid('', true)), 0, 12);
        }

        return $id;
    }


    public function isAuthenticated(): bool
    {
        return $this->doctorId() !== null;
    }

    private function __clone() {}
}
