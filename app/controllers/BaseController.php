<?php

declare(strict_types=1);

abstract class BaseController
{
    protected Request  $request;
    protected Database $db;
    protected Auth     $auth;
    protected Logger   $logger;
    protected ?array   $authUser;

    public function __construct(Request $request, ?array $authUser = null)
    {
        $this->request  = $request;
        $this->db       = Database::getInstance();
        $this->auth     = Auth::getInstance();
        $this->logger   = Logger::getInstance();
        $this->authUser = $authUser;
    }

    // ── VALIDATION ─────────────────────────────────────────
    protected function validate(array $rules, ?array $data = null): array
    {
        $data      = $data ?? $this->request->all();
        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            Response::validationError($validator->errors());
        }

        return $data;
    }

    // ── INPUT ──────────────────────────────────────────────
    protected function input(string $key, mixed $default = null): mixed
    {
        return $this->request->input($key, $default);
    }

    protected function allInput(): array
    {
        return CommonHelper::sanitizeArray($this->request->all());
    }

    // ── AUTH ───────────────────────────────────────────────
    protected function requireAuth(): array
    {
        if (!$this->authUser) {
            Response::unauthorized('Authentication required.');
        }
        return $this->authUser;
    }

    // 👉 NEW: get logged-in doctor id directly
    protected function doctorId(): int
    {
        $user = $this->requireAuth();
        return (int) ($user['doctor_id'] ?? 0);
    }

    // ── PAGINATION ─────────────────────────────────────────
    protected function getPage(): int
    {
        return max(1, (int) $this->request->get('page', 1));
    }

    protected function getLimit(int $default = 10, int $max = 100): int
    {
        return min($max, max(1, (int) $this->request->get('limit', $default)));
    }
}