<?php

declare(strict_types=1);

/**
 * Receptionist accounts. The doctor creates them (no OTP: the doctor hands over the password) and can
 * switch them off at any time. A receptionist can use patients, appointments and billing only.
 */
class StaffController extends BaseController
{
    private const MAX_STAFF = 10;

    /** GET staff */
    public function index(): void
    {
        $d = (int) $this->requireDoctor()['id'];
        $rows = $this->db->getRows('SELECT * FROM clinic_staff WHERE doctor_id = :d AND deleted_at IS NULL ORDER BY name', [':d' => $d]);
        Response::success(['items' => array_map([self::class, 'shape'], $rows)], 'Staff fetched');
    }

    /** POST staff {name, countrycode, phone, password} */
    public function create(): void
    {
        $d = (int) $this->requireDoctor()['id'];
        $this->validate([
            'name'        => 'required|string|min:2|max:100',
            'countrycode' => 'required|string',
            'phone'       => 'required|string',
            'password'    => 'required|string|min:8|max:64',
        ]);
        $name = self::clean((string) $this->request->input('name'));
        if (mb_strlen($name) < 2) Response::validationError(['name' => ['Please enter a name.']]);
        $mobile = AuthController::normalizeMobile((string) $this->request->input('countrycode'), (string) $this->request->input('phone'));
        AuthController::assertPasswordStrength((string) $this->request->input('password'));

        if ($this->db->getRow('SELECT id FROM doctors WHERE mobile = :m', [':m' => $mobile])
            || $this->db->getRow('SELECT id FROM clinic_staff WHERE mobile = :m AND deleted_at IS NULL', [':m' => $mobile])) {
            Response::error('This mobile number is already registered.', 409, ['phone' => ['This mobile number is already registered.']]);
        }
        $n = (int) $this->db->getRow('SELECT COUNT(*) AS n FROM clinic_staff WHERE doctor_id = :d AND deleted_at IS NULL', [':d' => $d])['n'];
        if ($n >= self::MAX_STAFF) Response::error('You can add up to ' . self::MAX_STAFF . ' staff members.', 422);

        $id = $this->db->insert('clinic_staff', [
            'doctor_id' => $d, 'name' => $name, 'mobile' => $mobile, 'role' => 'receptionist', 'is_active' => 1,
            'password_hash' => CommonHelper::hashPassword((string) $this->request->input('password')),
        ]);
        Response::created(['staff' => self::shape($this->own($id, $d))], 'Receptionist added');
    }

    /** PUT staff/{id} {name?, is_active?, password?} */
    public function update(array $params): void
    {
        $d  = (int) $this->requireDoctor()['id'];
        $s  = $this->own((int) $params['id'], $d);
        $up = [];
        if ($this->request->input('name') !== null) {
            $name = self::clean((string) $this->request->input('name'));
            if (mb_strlen($name) < 2 || mb_strlen($name) > 100) Response::validationError(['name' => ['Please enter a name (2–100 characters).']]);
            $up['name'] = $name;
        }
        if ($this->request->input('is_active') !== null) {
            $up['is_active'] = filter_var($this->request->input('is_active'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        $pw = $this->request->input('password');
        if ($pw !== null && $pw !== '') {
            if (!is_string($pw) || strlen($pw) < 8 || strlen($pw) > 64) Response::validationError(['password' => ['Use 8–64 characters.']]);
            AuthController::assertPasswordStrength($pw);
            $up['password_hash'] = CommonHelper::hashPassword($pw);
        }
        if (!$up) Response::validationError(['name' => ['Nothing to change.']]);
        $this->db->update('clinic_staff', $up, 'id = :i', [':i' => $s['id']]);
        if (isset($up['password_hash']) || ($up['is_active'] ?? 1) === 0) {
            $this->auth->revokeAllForStaff((int) $s['id']);    // signed out everywhere; with a new password they sign in again
        }
        Response::success(['staff' => self::shape($this->own((int) $s['id'], $d))], 'Saved');
    }

    /** DELETE staff/{id} */
    public function delete(array $params): void
    {
        $d = (int) $this->requireDoctor()['id'];
        $s = $this->own((int) $params['id'], $d);
        $this->db->update('clinic_staff', ['deleted_at' => gmdate('Y-m-d H:i:s'), 'is_active' => 0], 'id = :i', [':i' => $s['id']]);
        $this->auth->revokeAllForStaff((int) $s['id']);
        Response::success([], 'Removed');
    }

    /** PUT staff/password {current_password, password} — a receptionist changes their own password. */
    public function changeOwnPassword(): void
    {
        $this->requireClinic();
        if (!$this->isStaff()) Response::forbidden('Doctors change their password from their profile.');
        $this->validate(['current_password' => 'required|string', 'password' => 'required|string|min:8|max:64']);
        if (!CommonHelper::verifyPassword((string) $this->request->input('current_password'), $this->staff['password_hash'])) {
            Response::validationError(['current_password' => ['Your current password is not correct.']]);
        }
        AuthController::assertPasswordStrength((string) $this->request->input('password'));
        $this->db->update('clinic_staff', ['password_hash' => CommonHelper::hashPassword((string) $this->request->input('password'))], 'id = :i', [':i' => $this->staff['id']]);
        $this->auth->revokeAllForStaff((int) $this->staff['id']);
        $fresh = $this->db->getRow('SELECT * FROM clinic_staff WHERE id = :i', [':i' => $this->staff['id']]);
        Response::success([
            'access_token'  => $this->auth->generateToken(['doctor_id' => (int) $fresh['doctor_id'], 'staff_id' => (int) $fresh['id'], 'role' => 'receptionist']),
            'refresh_token' => $this->auth->generateStaffRefreshToken((int) $fresh['id']),
            'token_type'    => 'Bearer', 'expires_in' => Config::get('jwt.expiry'),
        ], 'Password changed');
    }

    private function own(int $id, int $d): array
    {
        $s = $this->db->getRow('SELECT * FROM clinic_staff WHERE id = :i AND doctor_id = :d AND deleted_at IS NULL', [':i' => $id, ':d' => $d]);
        if (!$s) Response::error('Not found', 404);
        return $s;
    }

    private static function shape(array $s): array
    {
        return [
            'id' => (int) $s['id'], 'name' => $s['name'], 'mobile' => $s['mobile'], 'role' => $s['role'],
            'is_active' => (int) $s['is_active'] === 1, 'last_login_at' => $s['last_login_at'], 'created_at' => $s['created_at'],
        ];
    }
}
