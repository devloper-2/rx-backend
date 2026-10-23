<?php

declare(strict_types=1);

require_once ROOT_PATH . '/app/controllers/BaseController.php';

/**
 * DoctorController — the signed-in doctor's own profile.
 *
 * GET /doctor/profile          → profile()
 * PUT /doctor/profile          → updateProfile()
 * PUT /doctor/change-password  → changePassword()
 */
class DoctorController extends BaseController
{
    public function profile(): void
    {
        Response::success(['user' => self::publicDoctor($this->requireDoctor())], 'Profile fetched');
    }

    public function updateProfile(): void
    {
        $doctor = $this->requireDoctor();

        $data = $this->validate([
            'name'            => 'required|string|min:2|max:100',
            'email'           => 'nullable|email|max:191',
            'degree'          => 'nullable|string|max:255',
            'specialization'  => 'nullable|string|max:255',
            'registration_no' => 'nullable|string|max:100',
            'experience_yrs'  => 'nullable|integer',
        ]);

        $text = fn (string $key) => ($v = self::clean((string) ($data[$key] ?? ''))) === '' ? null : $v;

        $name = self::clean((string) $data['name']);
        if (mb_strlen($name) < 2) {
            Response::validationError(['name' => ['Please enter your full name.']]);
        }

        $email = $text('email');
        $email = $email === null ? null : strtolower($email);
        $regNo = $text('registration_no');

        $years = $data['experience_yrs'] ?? null;
        if ($years !== null && $years !== '') {
            if ((int) $years < 0 || (int) $years > 70) {
                Response::validationError(['experience_yrs' => ['Experience must be between 0 and 70 years.']]);
            }
            $years = (int) $years;
        } else {
            $years = null;
        }

        foreach (['email' => $email, 'registration_no' => $regNo] as $column => $value) {
            if ($value === null) continue;
            $clash = $this->db->getRow("SELECT id FROM doctors WHERE {$column} = :v AND id <> :id", [':v' => $value, ':id' => $doctor['id']]);
            if ($clash) {
                $label = $column === 'email' ? 'e-mail address' : 'registration number';
                Response::validationError([$column => ["This {$label} is already used by another account."]]);
            }
        }

        $this->db->update('doctors', [
            'name'            => $name,
            'email'           => $email,
            'degree'          => $text('degree'),
            'specialization'  => $text('specialization'),
            'registration_no' => $regNo,
            'experience_yrs'  => $years,
        ], 'id = :id', [':id' => $doctor['id']]);

        $fresh = $this->db->getRow('SELECT * FROM doctors WHERE id = :id', [':id' => $doctor['id']]);
        Response::success(['user' => self::publicDoctor($fresh)], 'Profile updated');
    }

    public function changePassword(): void
    {
        $doctor = $this->requireDoctor();

        $data = $this->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|max:64',
        ]);

        if (!CommonHelper::verifyPassword($data['current_password'], $doctor['password_hash'])) {
            Response::validationError(['current_password' => ['Your current password is incorrect.']]);
        }
        if (!preg_match('/[A-Za-z]/', $data['password']) || !preg_match('/\d/', $data['password'])) {
            Response::validationError(['password' => ['Use at least one letter and one number.']]);
        }
        if ($data['password'] === $data['current_password']) {
            Response::validationError(['password' => ['Choose a password different from the current one.']]);
        }

        $this->db->update('doctors', ['password_hash' => CommonHelper::hashPassword($data['password'])], 'id = :id', [':id' => $doctor['id']]);

        // Sign out everywhere else, but keep this device signed in with a fresh session.
        $this->auth->revokeAllForDoctor((int) $doctor['id']);

        Response::success([
            'access_token'  => $this->auth->generateToken(['doctor_id' => (int) $doctor['id'], 'role' => 'doctor']),
            'refresh_token' => $this->auth->generateRefreshToken((int) $doctor['id']),
            'token_type'    => 'Bearer',
            'expires_in'    => Config::get('jwt.expiry'),
        ], 'Password changed');
    }
}
