<?php

declare(strict_types=1);

/**
 * BaseController — All controllers extend this class.
 *
 * Provides:
 *   $this->request    — Request instance
 *   $this->db         — Database instance
 *   $this->auth       — Auth instance
 *   $this->authUser   — Decoded JWT payload (null if route has no auth)
 *   $this->validate() — Shorthand validation that auto-returns 422 on failure
 *   $this->input()    — Validated & sanitized input
 */
abstract class BaseController
{
    protected Request    $request;
    protected Database   $db;
    protected Auth       $auth;
    protected Logger     $logger;
    protected ?array     $authUser;

    public function __construct(Request $request, ?array $authUser = null)
    {
        $this->request  = $request;
        $this->db       = Database::getInstance();
        $this->auth     = Auth::getInstance();
        $this->logger   = Logger::getInstance();
        $this->authUser = $authUser;
    }

    // ── Validation shorthand ─────────────────────────────────────────────────
    /**
     * Validate input against rules. Automatically sends 422 on failure.
     *
     * @param  array  $rules    ['field' => 'required|email|min:3']
     * @param  array|null $data Override data source (defaults to all request input)
     * @return array  Validated data
     */
    protected function validate(array $rules, ?array $data = null): array
    {
        $data      = $data ?? $this->request->all();
        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            Response::validationError($validator->errors());
        }

        return $data;
    }

    // ── Input helpers ────────────────────────────────────────────────────────
    /** Get sanitized input value */
    protected function input(string $key, mixed $default = null): mixed
    {
        return $this->request->input($key, $default);
    }

    /** Get all sanitized input */
    protected function allInput(): array
    {
        return CommonHelper::sanitizeArray($this->request->all());
    }

    /** Require authenticated user or abort 401 */
    protected function requireAuth(): array
    {
        if (!$this->authUser) {
            Response::unauthorized('Authentication required.');
        }
        return $this->authUser;
    }

    /** Require specific role */
    protected function requireRole(string ...$roles): array
    {
        $user = $this->requireAuth();
        if (!in_array($user['role'] ?? '', $roles, true)) {
            Response::forbidden('You do not have permission to perform this action.');
        }
        return $user;
    }

    // ── Pagination helpers ───────────────────────────────────────────────────
    protected function getPage(): int
    {
        return max(1, (int) $this->request->get('page', 1));
    }

    protected function getLimit(int $default = 10, int $max = 100): int
    {
        return min($max, max(1, (int) $this->request->get('limit', $default)));
    }
}
