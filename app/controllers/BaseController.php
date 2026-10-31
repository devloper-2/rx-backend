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

    /**
     * Require a signed-in, still-active doctor and return their row.
     * Re-checked against the database so a disabled/deleted account loses
     * access immediately instead of when its access token expires.
     */
    protected function requireDoctor(): array
    {
        $auth   = $this->requireAuth();
        if (($auth['role'] ?? 'doctor') !== 'doctor') {
            Response::forbidden('This is only available to the doctor.');
        }
        $doctor = $this->db->getRow(
            'SELECT * FROM doctors WHERE id = :id AND deleted_at IS NULL AND is_active = 1',
            [':id' => (int) ($auth['doctor_id'] ?? 0)]
        );
        if (!$doctor) {
            Response::unauthorized('Your session is no longer valid. Please sign in again.');
        }
        return $doctor;
    }

    /** The signed-in receptionist's row, set by requireClinic(); null for the doctor. */
    protected ?array $staff = null;

    protected function isStaff(): bool
    {
        return ($this->authUser['role'] ?? 'doctor') !== 'doctor';
    }

    /**
     * For endpoints a receptionist may use too: returns the clinic owner's (doctor's) row, so every query
     * stays scoped to that doctor. A receptionist is re-checked against the database on every call, so
     * switching them off (or deleting them) takes effect immediately.
     */
    protected function requireClinic(): array
    {
        $auth = $this->requireAuth();
        if (!$this->isStaff()) {
            return $this->requireDoctor();
        }
        $staff = $this->db->getRow(
            'SELECT s.* FROM clinic_staff s JOIN doctors d ON d.id = s.doctor_id AND d.deleted_at IS NULL AND d.is_active = 1
             WHERE s.id = :s AND s.doctor_id = :d AND s.deleted_at IS NULL AND s.is_active = 1',
            [':s' => (int) ($auth['staff_id'] ?? 0), ':d' => (int) ($auth['doctor_id'] ?? 0)]
        );
        if (!$staff) {
            Response::unauthorized('Your session is no longer valid. Please sign in again.');
        }
        $this->staff = $staff;
        return $this->db->getRow('SELECT * FROM doctors WHERE id = :id', [':id' => (int) $staff['doctor_id']]);
    }

    /** Trim, strip tags and collapse whitespace. Output encoding is the client's job. */
    protected static function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', strip_tags($value)));
    }

    /** Doctor row safe to send to the app (no password hash) plus derived fields. */
    protected static function publicDoctor(array $doctor): array
    {
        $fields   = ['email', 'degree', 'specialization', 'registration_no', 'experience_yrs'];
        $filled   = count(array_filter($fields, fn ($f) => $doctor[$f] !== null && $doctor[$f] !== ''));
        $expires  = $doctor['plan_expires_at'];

        return [
            'id'               => (int) $doctor['id'],
            'name'             => $doctor['name'],
            'email'            => $doctor['email'],
            'mobile'           => $doctor['mobile'],
            'degree'           => $doctor['degree'],
            'specialization'   => $doctor['specialization'],
            'registration_no'  => $doctor['registration_no'],
            'experience_yrs'   => $doctor['experience_yrs'] === null ? null : (int) $doctor['experience_yrs'],
            'plan'             => $doctor['plan'],
            'plan_expires_at'  => $expires,
            'plan_days_left'   => $expires ? (int) max(0, ceil((strtotime($expires) - time()) / 86400)) : null,
            'profile_percent'  => (int) round((1 + $filled) / (1 + count($fields)) * 100), // name is always present
            'created_at'       => $doctor['created_at'],
        ];
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
