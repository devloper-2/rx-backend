<?php

declare(strict_types=1);

require_once ROOT_PATH . '/app/controllers/BaseController.php';

class UserController extends BaseController
{
    // ── GET PROFILE ─────────────────────────────────────────
    public function detail(): void
    {
        $auth = $this->requireAuth();
        $doctorId = $auth['doctor_id'];

        $doctor = $this->db->getRow(
            "SELECT id, name, email, mobile, avatar, created_at 
             FROM doctors 
             WHERE id = :id AND deleted_at IS NULL",
            [':id' => $doctorId]
        );

        if (!$doctor) {
            Response::notFound('Doctor not found');
        }

        if (!empty($doctor['avatar'])) {
            $doctor['avatar_url'] = (new FileUploadHelper())->url($doctor['avatar']);
        }

        Response::success(['doctor' => $doctor]);
    }

    // ── UPDATE PROFILE ──────────────────────────────────────
    public function update(): void
    {
        $auth = $this->requireAuth();
        $doctorId = $auth['doctor_id'];

        $data = $this->validate([
            'name'   => 'required|min:2|max:100',
            'mobile' => 'nullable|mobile|unique:doctors,mobile',
        ]);

        $this->db->update('doctors', [
            'name'       => $data['name'],
            'mobile'     => $data['mobile'] ?? null,
            'updated_at' => CommonHelper::now(),
        ], 'id = :id', [':id' => $doctorId]);

        Response::success([], 'Profile updated successfully');
    }

    // ── DELETE ACCOUNT (SOFT DELETE) ────────────────────────
    public function delete(): void
    {
        $auth = $this->requireAuth();
        $doctorId = $auth['doctor_id'];

        $this->db->softDelete('doctors', 'id = :id', [':id' => $doctorId]);

        // revoke tokens
        $this->db->update(
            'auth_tokens',
            ['revoked_at' => CommonHelper::now()],
            'doctor_id = :id',
            [':id' => $doctorId]
        );

        Response::success([], 'Account deleted successfully');
    }

    // ── UPLOAD AVATAR ───────────────────────────────────────
    public function uploadAvatar(): void
    {
        $auth = $this->requireAuth();
        $doctorId = $auth['doctor_id'];

        if (!$this->request->hasFile('avatar')) {
            Response::error('No file provided', 400);
        }

        $uploader = new FileUploadHelper();

        try {
            $existing = $this->db->getRow(
                "SELECT avatar FROM doctors WHERE id = :id",
                [':id' => $doctorId]
            );

            if (!empty($existing['avatar'])) {
                $uploader->delete($existing['avatar']);
            }

            $result = $uploader->upload(
                $this->request->file('avatar'),
                'doctor',
                $doctorId,
                ['type' => 'avatar']
            );

            $this->db->update('doctors', [
                'avatar'     => $result['relative_path'],
                'updated_at' => CommonHelper::now(),
            ], 'id = :id', [':id' => $doctorId]);

            Response::success([
                'avatar' => $result['url']
            ], 'Avatar uploaded');

        } catch (RuntimeException $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    // ── CHANGE PASSWORD ─────────────────────────────────────
    public function changePassword(): void
    {
        $auth = $this->requireAuth();
        $doctorId = $auth['doctor_id'];

        $data = $this->validate([
            'current_password' => 'required',
            'new_password'     => 'required|strong_password|confirmed',
        ]);

        $doctor = $this->db->getRow(
            "SELECT password FROM doctors WHERE id = :id",
            [':id' => $doctorId]
        );

        if (!$doctor || !CommonHelper::verifyPassword($data['current_password'], $doctor['password'])) {
            Response::error('Current password incorrect', 400);
        }

        $this->db->update('doctors', [
            'password'   => CommonHelper::hashPassword($data['new_password']),
            'updated_at' => CommonHelper::now(),
        ], 'id = :id', [':id' => $doctorId]);

        // revoke all tokens
        $this->db->update(
            'auth_tokens',
            ['revoked_at' => CommonHelper::now()],
            'doctor_id = :id',
            [':id' => $doctorId]
        );

        Response::success([], 'Password changed. Please login again.');
    }
}