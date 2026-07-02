<?php

declare(strict_types=1);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

/**
 * Response — Standardized JSON API responses.
 *
 * Envelope:
 * {
 *   "status":  true | false,
 *   "code":    200,
 *   "message": "...",
 *   "data":    {...} | [...] | null,
 *   "errors":  {...} | null,
 *   "meta":    { "timestamp": "...", "version": "v1", "elapsed_ms": 12.3 }
 * }
 */
class Response
{
    // ── HTTP Status codes ────────────────────────────────────────────────────
    const HTTP_OK                    = 200;
    const HTTP_CREATED               = 201;
    const HTTP_ACCEPTED              = 202;
    const HTTP_NO_CONTENT            = 204;
    const HTTP_MOVED_PERMANENTLY     = 301;
    const HTTP_BAD_REQUEST           = 400;
    const HTTP_UNAUTHORIZED          = 401;
    const HTTP_FORBIDDEN             = 403;
    const HTTP_NOT_FOUND             = 404;
    const HTTP_METHOD_NOT_ALLOWED    = 405;
    const HTTP_CONFLICT              = 409;
    const HTTP_UNPROCESSABLE_ENTITY  = 422;
    const HTTP_TOO_MANY_REQUESTS     = 429;
    const HTTP_INTERNAL_SERVER_ERROR = 500;
    const HTTP_SERVICE_UNAVAILABLE   = 503;

    private static array $messages = [
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        204 => 'No Content',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        409 => 'Conflict',
        422 => 'Unprocessable Entity',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        503 => 'Service Unavailable',
    ];

    // ── Main send ────────────────────────────────────────────────────────────
    /**
     * @param  int    $code     HTTP status code
     * @param  array  $data     Response payload
     * @param  string $message  Human-readable message
     * @param  bool   $status   Success flag
     * @param  array  $errors   Validation / error details
     * @param  array  $extra    Any extra top-level keys (e.g. pagination)
     */
    public static function send(
        int    $code    = 200,
        array  $data    = [],
        string $message = '',
        bool   $status  = true,
        array  $errors  = [],
        array  $extra   = []
    ): never {
        http_response_code($code);

        if (empty($message)) {
            $message = self::$messages[$code] ?? 'Unknown';
        }

        $body = [
            'status'  => $status,
            'code'    => $code,
            'message' => $message,
            'data'    => empty($data) ? null : $data,
            'errors'  => empty($errors) ? null : $errors,
            'meta' => [
                'timestamp'  => date('c'),
                'doctor_id' => self::getDoctorId(),
                'version'    => Config::get('app.version', 'v1'),
                'elapsed_ms' => defined('APP_START')
                    ? round((microtime(true) - APP_START) * 1000, 2)
                    : null,
                'request_id' => self::getRequestId(),
            ],
        ];

        // Merge extra keys (e.g. pagination)
        if (!empty($extra)) {
            $body = array_merge($body, $extra);
        }

        // Log response
        try {
            if (!$status) {
                Logger::getInstance()->error($message, [
                    'code'   => $code,
                    'errors' => $errors,
                ]);
            }
        } catch (Throwable) {
            // Never let logging break response
        }

        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    // ── Convenience helpers ──────────────────────────────────────────────────
    public static function success(array $data = [], string $message = 'Success', array $extra = []): never
    {
        self::send(200, $data, $message, true, [], $extra);
    }

    public static function created(array $data = [], string $message = 'Created successfully'): never
    {
        self::send(201, $data, $message, true);
    }

    public static function paginated(array $data, array $pagination, string $message = 'Data fetched successfully'): never
    {
        self::send(200, $data, $message, true, [], ['pagination' => $pagination]);
    }

    public static function error(string $message = 'Something went wrong', int $code = 500, array $errors = []): never
    {
        self::send($code, [], $message, false, $errors);
    }

    public static function validationError(array $errors, string $message = 'Validation failed'): never
    {
        self::send(422, [], $message, false, $errors);
    }

    public static function unauthorized(string $message = 'Unauthorized'): never
    {
        self::send(401, [], $message, false);
    }

    public static function forbidden(string $message = 'Forbidden'): never
    {
        self::send(403, [], $message, false);
    }

    public static function notFound(string $message = 'Resource not found'): never
    {
        self::send(404, [], $message, false);
    }

    public static function tooManyRequests(string $message = 'Too many requests'): never
    {
        self::send(429, [], $message, false);
    }


    private static function getRequestId(): string
    {
        static $id = null;

        if ($id === null) {
            $id = substr(md5(uniqid('', true)), 0, 12);
        }

        return $id;
    }

    private static function getDoctorId(): ?int
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

}
