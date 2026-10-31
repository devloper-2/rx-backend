<?php

declare(strict_types=1);

require_once ROOT_PATH . '/app/controllers/BaseController.php';

/**
 * AuthController — Doctor authentication (mobile + password, verified by OTP).
 *
 * POST /auth/register-new    → register()        create account, send OTP
 * POST /auth/verify-otp      → verifyOtp()       confirm mobile, returns tokens
 * POST /auth/resend-otp      → resendOtp()
 * POST /auth/login-new       → login()           mobile/email + password
 * POST /auth/forgot-password → forgotPassword()  send reset OTP
 * POST /auth/reset-password  → resetPassword()   OTP + new password
 * POST /auth/refresh         → refresh()
 * POST /auth/logout          → logout()
 * GET  /auth/me              → me()              [JWT]
 *
 * OTP delivery (SMS / e-mail) is not available yet. When
 * Config app.expose_dev_otp is on, the code is returned as `dev_otp`.
 */
class AuthController extends BaseController
{
    private const OTP_TTL_SECONDS   = 300;
    private const OTP_RESEND_WAIT   = 30;
    private const OTP_MAX_ATTEMPTS  = 5;

    // ── POST /auth/register-new ──────────────────────────────────────────────
    public function register(): void
    {
        $data = $this->validate([
            'name'        => 'required|string|min:2|max:100',
            'password'    => 'required|string|min:8|max:64',
            'phone'       => 'required|string',
            'countrycode' => 'required|string',
        ]);

        $name = self::clean($data['name']);
        if (mb_strlen($name) < 2) {
            Response::validationError(['name' => ['Please enter your full name.']]);
        }
        $this->assertPasswordStrength($data['password']);
        $mobile = $this->normalizeMobile($data['countrycode'], $data['phone']);

        $existing = $this->db->getRow('SELECT id, is_active, deleted_at FROM doctors WHERE mobile = :m', [':m' => $mobile]);

        if ($existing) {
            $pending = $this->db->getRow(
                "SELECT id FROM verification WHERE doctor_id = :id AND purpose = 'register'",
                [':id' => $existing['id']]
            );

            if ($existing['deleted_at'] !== null || ((int) $existing['is_active'] !== 1 && !$pending)) {
                Response::error('This number cannot be registered. Please contact support.', 403);
            }
            if ((int) $existing['is_active'] === 1) {
                Response::error('This mobile number is already registered. Please sign in.', 409);
            }

            // Registered earlier but never verified: let the person start over.
            $doctorId = (int) $existing['id'];
            $this->db->update('doctors', [
                'name'          => $name,
                'password_hash' => CommonHelper::hashPassword($data['password']),
            ], 'id = :id', [':id' => $doctorId]);
        } else {
            $doctorId = $this->db->insert('doctors', [
                'name'          => $name,
                'mobile'        => $mobile,
                'password_hash' => CommonHelper::hashPassword($data['password']),
                'plan'          => 'trial',
                'plan_expires_at' => date('Y-m-d H:i:s', strtotime('+30 days')),
                'is_active'     => 0, // activated once the mobile number is verified
            ]);
        }

        $otp = $this->issueOtp($doctorId, $mobile, 'register', false);

        Response::created($this->otpPayload($mobile, $otp), 'Account created. Enter the code we sent to verify your number.');
    }

    // ── POST /auth/verify-otp ────────────────────────────────────────────────
    public function verifyOtp(): void
    {
        $data = $this->validate([
            'mobile' => 'required|string',
            'otp'    => 'required|string',
        ]);

        $doctor = $this->findByMobile((string) $data['mobile']);
        if (!$doctor) {
            Response::error('No account found for this number.', 404);
        }

        $this->checkOtp((int) $doctor['id'], 'register', (string) $data['otp']);

        $this->db->update('doctors', ['is_active' => 1], 'id = :id', [':id' => $doctor['id']]);
        $this->db->delete('verification', "doctor_id = :id AND purpose = 'register'", [':id' => $doctor['id']]);

        $this->logger->info('Doctor verified', ['doctor_id' => $doctor['id']]);

        Response::success($this->sessionPayload((int) $doctor['id']), 'Number verified. Welcome aboard!');
    }

    // ── POST /auth/resend-otp ────────────────────────────────────────────────
    public function resendOtp(): void
    {
        $data = $this->validate([
            'mobile'  => 'required|string',
            'purpose' => 'required|in:register,reset',
        ]);

        $doctor = $data['purpose'] === 'register'
            ? $this->findByMobile((string) $data['mobile'])
            : $this->findByIdentifier((string) $data['mobile']);

        if ($data['purpose'] === 'register') {
            $pending = $doctor ? $this->db->getRow(
                "SELECT id FROM verification WHERE doctor_id = :id AND purpose = 'register'",
                [':id' => $doctor['id']]
            ) : null;
            if (!$doctor || !$pending) {
                Response::error('Nothing to verify for this number. Please register again.', 404);
            }
            $otp = $this->issueOtp((int) $doctor['id'], $doctor['mobile'], 'register', true);
            Response::success($this->otpPayload($doctor['mobile'], $otp), 'A new code has been sent.');
        }

        $this->sendResetOtp($doctor, (string) $data['mobile'], true);
    }

    // ── POST /auth/login-new ─────────────────────────────────────────────────
    public function login(): void
    {
        $data = $this->validate([
            'identifier' => 'required|string',
            'password'   => 'required|string',
        ]);

        $doctor = $this->findByIdentifier((string) $data['identifier']);

        if (!$doctor) {
            // Not a doctor: try the clinic's receptionists.
            $staff = $this->findStaff((string) $data['identifier']);
            if ($staff && CommonHelper::verifyPassword($data['password'], $staff['password_hash']) && (int) $staff['is_active'] === 1) {
                $this->db->update('clinic_staff', ['last_login_at' => gmdate('Y-m-d H:i:s')], 'id = :i', [':i' => $staff['id']]);
                $this->logger->info('Receptionist logged in', ['staff_id' => $staff['id']]);
                Response::success($this->staffSession($staff), 'Login successful');
            }
            if ($staff && (int) $staff['is_active'] !== 1 && CommonHelper::verifyPassword($data['password'], $staff['password_hash'])) {
                Response::error('This account has been switched off. Please ask the doctor.', 403);
            }
        }

        if (!$doctor || !CommonHelper::verifyPassword($data['password'], $doctor['password_hash'])) {
            // Same message for both cases: don't reveal which numbers are registered.
            Response::error('Incorrect mobile number or password.', 401);
        }

        if ($doctor['deleted_at'] !== null) {
            Response::error('This account is no longer available. Please contact support.', 403);
        }

        if ((int) $doctor['is_active'] !== 1) {
            $pending = $this->db->getRow(
                "SELECT id FROM verification WHERE doctor_id = :id AND purpose = 'register'",
                [':id' => $doctor['id']]
            );
            if (!$pending) {
                Response::error('This account is disabled. Please contact support.', 403);
            }
            // Signed up but never confirmed the number: send a fresh code.
            $otp = $this->issueOtp((int) $doctor['id'], $doctor['mobile'], 'register', false, true);
            Response::send(403, array_merge($this->otpPayload($doctor['mobile'], $otp), ['needs_verification' => true]),
                'Please verify your mobile number to continue.', false);
        }

        $this->logger->info('Doctor logged in', ['doctor_id' => $doctor['id']]);

        Response::success($this->sessionPayload((int) $doctor['id']), 'Login successful');
    }

    // ── POST /auth/forgot-password ───────────────────────────────────────────
    public function forgotPassword(): void
    {
        $data   = $this->validate(['mobile' => 'required|string']);
        $doctor = $this->findByIdentifier((string) $data['mobile']);
        $this->sendResetOtp($doctor, (string) $data['mobile'], false);
    }

    // ── POST /auth/reset-password ────────────────────────────────────────────
    public function resetPassword(): void
    {
        $data = $this->validate([
            'mobile'   => 'required|string',
            'otp'      => 'required|string',
            'password' => 'required|string|min:8|max:64',
        ]);
        $this->assertPasswordStrength($data['password']);

        $doctor = $this->findByIdentifier((string) $data['mobile']);
        if (!$doctor || (int) $doctor['is_active'] !== 1 || $doctor['deleted_at'] !== null) {
            Response::error('That code is not valid. Please request a new one.', 400);
        }

        $this->checkOtp((int) $doctor['id'], 'reset', (string) $data['otp']);

        $this->db->update('doctors', ['password_hash' => CommonHelper::hashPassword($data['password'])], 'id = :id', [':id' => $doctor['id']]);
        $this->db->delete('verification', "doctor_id = :id AND purpose = 'reset'", [':id' => $doctor['id']]);
        $this->auth->revokeAllForDoctor((int) $doctor['id']);

        $this->logger->info('Password reset', ['doctor_id' => $doctor['id']]);

        Response::success([], 'Password updated. Please sign in with your new password.');
    }

    // ── POST /auth/refresh ───────────────────────────────────────────────────
    public function refresh(): void
    {
        $data = $this->validate(['refresh_token' => 'required|string']);

        try {
            $result = $this->auth->refreshAccessToken($data['refresh_token']);
            Response::success(array_merge($result, ['expires_in' => Config::get('jwt.expiry')]), 'Token refreshed');
        } catch (RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() ?: 401);
        }
    }

    // ── POST /auth/logout ────────────────────────────────────────────────────
    // Not JWT-protected on purpose: the refresh token itself proves ownership,
    // and a doctor whose access token already expired must still be able to sign out.
    public function logout(): void
    {
        $data = $this->validate(['refresh_token' => 'required|string']);
        $this->auth->revokeRefreshToken($data['refresh_token']);
        Response::success([], 'Signed out');
    }

    // ── GET /auth/me ─────────────────────────────────────────────────────────
    public function me(): void
    {
        if ($this->isStaff()) {
            $this->requireClinic();
            Response::success(['user' => self::publicStaff($this->staff)], 'Profile fetched');
        }
        $doctor = $this->requireDoctor();
        Response::success(['user' => self::publicDoctor($doctor)], 'Profile fetched');
    }

    // ── Receptionist sessions ────────────────────────────────────────────────
    public static function publicStaff(array $s): array
    {
        $db = Database::getInstance();
        $d  = $db->getRow('SELECT name FROM doctors WHERE id = :i', [':i' => $s['doctor_id']]);
        $c  = $db->getRow('SELECT name FROM clinics WHERE doctor_id = :i AND deleted_at IS NULL ORDER BY is_primary DESC, id LIMIT 1', [':i' => $s['doctor_id']]);
        return [
            'id' => (int) $s['id'], 'name' => $s['name'], 'mobile' => $s['mobile'], 'role' => $s['role'],
            'doctor_id' => (int) $s['doctor_id'], 'doctor_name' => $d['name'] ?? null, 'clinic_name' => $c['name'] ?? null,
            'email' => null, 'degree' => null, 'specialization' => null, 'registration_no' => null, 'experience_yrs' => null,
            'plan' => null, 'plan_expires_at' => null, 'plan_days_left' => null, 'profile_percent' => 100, 'created_at' => $s['created_at'],
        ];
    }

    private function staffSession(array $staff): array
    {
        return [
            'user'          => self::publicStaff($staff),
            'access_token'  => $this->auth->generateToken(['doctor_id' => (int) $staff['doctor_id'], 'staff_id' => (int) $staff['id'], 'role' => 'receptionist']),
            'refresh_token' => $this->auth->generateStaffRefreshToken((int) $staff['id']),
            'token_type'    => 'Bearer',
            'expires_in'    => Config::get('jwt.expiry'),
        ];
    }

    private function findStaff(string $identifier): ?array
    {
        $digits = ltrim(preg_replace('/\D/', '', trim($identifier)), '0');
        if ($digits === '' || str_contains($identifier, '@')) return null;
        if (str_starts_with(trim($identifier), '+')) {
            return $this->db->getRow('SELECT * FROM clinic_staff WHERE mobile = :m AND deleted_at IS NULL', [':m' => '+' . $digits]);
        }
        $rows = $this->db->getRows('SELECT * FROM clinic_staff WHERE deleted_at IS NULL AND (mobile = :full OR mobile LIKE :tail) LIMIT 2', [':full' => '+' . $digits, ':tail' => '+%' . $digits]);
        return count($rows) === 1 ? $rows[0] : null;
    }

    // ── Internals ────────────────────────────────────────────────────────────
    private function sendResetOtp(?array $doctor, string $input, bool $explicit): never
    {
        $generic = 'If this number is registered, a reset code has been sent.';

        if (!$doctor || (int) $doctor['is_active'] !== 1 || $doctor['deleted_at'] !== null) {
            Response::success(['mobile' => $this->canonicalMobile($input), 'expires_in' => self::OTP_TTL_SECONDS], $generic);
        }

        $otp = $this->issueOtp((int) $doctor['id'], $doctor['mobile'], 'reset', $explicit);
        Response::success($this->otpPayload($doctor['mobile'], $otp), $generic);
    }

    /** Create (replace) the OTP for a doctor+purpose. Returns the plain code. */
    private function issueOtp(int $doctorId, string $mobile, string $purpose, bool $enforceCooldown, bool $silentCooldown = false): string
    {
        $last = $this->db->getRow(
            'SELECT created_at, otp FROM verification WHERE doctor_id = :id AND purpose = :p',
            [':id' => $doctorId, ':p' => $purpose]
        );

        if ($last && (time() - strtotime($last['created_at'])) < self::OTP_RESEND_WAIT) {
            if ($silentCooldown && !empty($last['otp'])) {
                return (string) $last['otp']; // re-use the code that was just sent
            }
            if ($enforceCooldown) {
                $wait = self::OTP_RESEND_WAIT - (time() - strtotime($last['created_at']));
                Response::error("Please wait {$wait} seconds before requesting another code.", 429);
            }
        }

        $otp = CommonHelper::generateOtp(6);

        $this->db->delete('verification', 'doctor_id = :id AND purpose = :p', [':id' => $doctorId, ':p' => $purpose]);
        $this->db->insert('verification', [
            'doctor_id'   => $doctorId,
            'otp'         => $otp,
            'otp_expires' => date('Y-m-d H:i:s', time() + self::OTP_TTL_SECONDS),
            'attempts'    => 0,
            'type'        => 'mobile',
            'purpose'     => $purpose,
            'send_to'     => $mobile,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        // TODO: hand the code to an SMS provider here once one is configured.
        if (Config::get('app.expose_dev_otp', false)) {
            $this->logger->info('OTP issued (dev)', ['doctor_id' => $doctorId, 'purpose' => $purpose, 'otp' => $otp]);
        }

        return $otp;
    }

    /** Validate a submitted code; aborts with an error response when it is not acceptable. */
    private function checkOtp(int $doctorId, string $purpose, string $submitted): void
    {
        $row = $this->db->getRow(
            'SELECT * FROM verification WHERE doctor_id = :id AND purpose = :p',
            [':id' => $doctorId, ':p' => $purpose]
        );

        if (!$row || empty($row['otp'])) {
            Response::error('No active code. Please request a new one.', 400);
        }
        if (strtotime((string) $row['otp_expires']) < time()) {
            Response::error('This code has expired. Please request a new one.', 400);
        }
        if ((int) $row['attempts'] >= self::OTP_MAX_ATTEMPTS) {
            Response::error('Too many wrong attempts. Please request a new code.', 429);
        }

        if (!hash_equals((string) $row['otp'], trim($submitted))) {
            $this->db->executeQuery('UPDATE verification SET attempts = attempts + 1 WHERE id = :id', [':id' => $row['id']]);
            $left = self::OTP_MAX_ATTEMPTS - (int) $row['attempts'] - 1;
            Response::error($left > 0 ? "Incorrect code. {$left} attempt" . ($left === 1 ? '' : 's') . ' left.' : 'Incorrect code. Please request a new one.', 400);
        }
    }

    private function otpPayload(string $mobile, string $otp): array
    {
        $payload = [
            'mobile'     => $mobile,
            'expires_in' => self::OTP_TTL_SECONDS,
            'resend_in'  => self::OTP_RESEND_WAIT,
        ];
        if (Config::get('app.expose_dev_otp', false)) {
            $payload['dev_otp'] = $otp;
        }
        return $payload;
    }

    private function sessionPayload(int $doctorId): array
    {
        $doctor = $this->db->getRow('SELECT * FROM doctors WHERE id = :id', [':id' => $doctorId]);

        return [
            'user'          => self::publicDoctor($doctor),
            'access_token'  => $this->auth->generateToken(['doctor_id' => $doctorId, 'role' => 'doctor']),
            'refresh_token' => $this->auth->generateRefreshToken($doctorId),
            'token_type'    => 'Bearer',
            'expires_in'    => Config::get('jwt.expiry'),
        ];
    }

    public static function assertPasswordStrength(string $password): void
    {
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            Response::validationError(['password' => ['Use at least one letter and one number.']]);
        }
    }

    /** "+91" + "09012345678" → "+919012345678" (validated, ≤ 15 chars to fit the column). */
    public static function normalizeMobile(string $countryCode, string $phone): string
    {
        $cc    = preg_replace('/\D/', '', $countryCode);
        $local = ltrim(preg_replace('/\D/', '', $phone), '0');

        if ($cc === '' || strlen($cc) > 4) {
            Response::validationError(['countrycode' => ['Please choose a valid country code.']]);
        }
        if (strlen($local) < 6 || strlen($cc . $local) > 14) {
            Response::validationError(['phone' => ['Please enter a valid mobile number.']]);
        }
        if ($cc === '91' && !preg_match('/^[6-9]\d{9}$/', $local)) {
            Response::validationError(['phone' => ['Enter a valid 10-digit Indian mobile number.']]);
        }
        return '+' . $cc . $local;
    }

    private function canonicalMobile(string $input): string
    {
        $digits = preg_replace('/\D/', '', $input);
        return str_starts_with(trim($input), '+') ? '+' . $digits : $digits;
    }

    private function findByMobile(string $mobile): ?array
    {
        return $this->db->getRow('SELECT * FROM doctors WHERE mobile = :m', [':m' => $this->canonicalMobile($mobile)]);
    }

    /** Accepts an e-mail, a full "+<cc><number>" mobile, or a bare national number. */
    private function findByIdentifier(string $identifier): ?array
    {
        $identifier = trim($identifier);

        if (str_contains($identifier, '@')) {
            return $this->db->getRow('SELECT * FROM doctors WHERE email = :e', [':e' => strtolower($identifier)]);
        }

        $digits = ltrim(preg_replace('/\D/', '', $identifier), '0');
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($identifier, '+')) {
            return $this->db->getRow('SELECT * FROM doctors WHERE mobile = :m', [':m' => '+' . $digits]);
        }

        // National number typed without a country code: only unambiguous matches count.
        $rows = $this->db->getRows(
            'SELECT * FROM doctors WHERE mobile = :full OR mobile LIKE :tail LIMIT 2',
            [':full' => '+' . $digits, ':tail' => '+%' . $digits]
        );
        return count($rows) === 1 ? $rows[0] : null;
    }
}
