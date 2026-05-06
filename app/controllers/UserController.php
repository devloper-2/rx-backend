<?php

declare(strict_types=1);

require_once ROOT_PATH . '/app/controllers/BaseController.php';

/**
 * UserController — User management endpoints.
 *
 * GET    /user/list          → list()    [auth + paginated]
 * GET    /user/detail/{id}   → detail()  [auth]
 * PUT    /user/update/{id}   → update()  [auth]
 * DELETE /user/delete/{id}   → delete()  [auth, admin only]
 * POST   /user/upload-avatar → uploadAvatar() [auth]
 * PUT    /user/change-password → changePassword() [auth]
 */
class UserController extends BaseController
{
    // ── GET /user/list ───────────────────────────────────────────────────────
    public function list(): void
    {
        $this->requireAuth();

        $page  = $this->getPage();
        $limit = $this->getLimit(10, 50);

        // Optional filters from query string
        $search = $this->request->get('search', '');
        $status = $this->request->get('status', '');
        $role   = $this->request->get('role', '');

        $where  = ['1 = 1'];
        $params = [];

        if (!empty($search)) {
            $where[]           = '(name LIKE :search OR email LIKE :search2)';
            $params[':search']  = '%' . $search . '%';
            $params[':search2'] = '%' . $search . '%';
        }
        if (!empty($status) && in_array($status, ['active', 'inactive', 'banned'], true)) {
            $where[]          = 'status = :status';
            $params[':status'] = $status;
        }
        if (!empty($role) && in_array($role, ['admin', 'user', 'moderator'], true)) {
            $where[]        = 'role = :role';
            $params[':role'] = $role;
        }

        $sql    = 'SELECT id, name, email, phone, role, status, created_at FROM users WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC';
        $result = $this->db->paginate($sql, $params, $page, $limit);

        Response::paginated($result['data'], $result['pagination'], 'Users fetched successfully');
    }

    // ── GET /user/detail/{id} ────────────────────────────────────────────────
    public function detail(array $urlParams = []): void
    {
        $this->requireAuth();

        $userId = (int) ($urlParams['id'] ?? 0);
        if ($userId <= 0) {
            Response::error('Invalid user ID.', 400);
        }

        $user = $this->db->getRow(
            'SELECT id, name, email, phone, role, status, avatar, last_login_at, created_at FROM users WHERE id = :id',
            [':id' => $userId]
        );

        if (!$user) {
            Response::notFound('User not found.');
        }

        if (!empty($user['avatar'])) {
            $user['avatar_url'] = (new FileUploadHelper())->url($user['avatar']);
        }

        Response::success(['user' => $user]);
    }

    // ── PUT /user/update/{id} ────────────────────────────────────────────────
    public function update(array $urlParams = []): void
    {
        $authUser = $this->requireAuth();

        $userId = (int) ($urlParams['id'] ?? 0);
        if ($userId <= 0) {
            Response::error('Invalid user ID.', 400);
        }

        // Users can only update themselves unless admin
        if ($authUser['user_id'] !== $userId && ($authUser['role'] ?? '') !== 'admin') {
            Response::forbidden('You can only update your own profile.');
        }

        $data = $this->validate([
            'name'  => 'required|string|min:2|max:100',
            'phone' => 'nullable|string|min:7|max:20',
        ]);

        $existing = $this->db->getRow('SELECT id FROM users WHERE id = :id', [':id' => $userId]);
        if (!$existing) {
            Response::notFound('User not found.');
        }

        $this->db->update('users', [
            'name'       => CommonHelper::sanitizeString($data['name']),
            'phone'      => isset($data['phone']) ? CommonHelper::sanitizeString($data['phone']) : null,
            'updated_at' => CommonHelper::now(),
        ], 'id = :id', [':id' => $userId]);

        $updated = $this->db->getRow(
            'SELECT id, name, email, phone, role, status, updated_at FROM users WHERE id = :id',
            [':id' => $userId]
        );

        Response::success(['user' => $updated], 'Profile updated successfully');
    }

    // ── DELETE /user/delete/{id} ─────────────────────────────────────────────
    public function delete(array $urlParams = []): void
    {
        $this->requireRole('admin');

        $userId = (int) ($urlParams['id'] ?? 0);
        if ($userId <= 0) {
            Response::error('Invalid user ID.', 400);
        }

        $user = $this->db->getRow('SELECT id, avatar FROM users WHERE id = :id', [':id' => $userId]);
        if (!$user) {
            Response::notFound('User not found.');
        }

        // Delete avatar if exists
        if (!empty($user['avatar'])) {
            (new FileUploadHelper())->delete($user['avatar']);
        }

        $this->db->delete('users', 'id = :id', [':id' => $userId]);

        $this->logger->info('User deleted', ['deleted_id' => $userId, 'by' => $this->authUser['user_id']]);

        Response::success([], 'User deleted successfully');
    }

    // ── POST /user/upload-avatar ─────────────────────────────────────────────
    public function uploadAvatar(): void
    {
        $authUser = $this->requireAuth();
        $userId   = $authUser['user_id'];

        if (!$this->request->hasFile('avatar')) {
            Response::error('No avatar file provided.', 400);
        }

        $uploader = new FileUploadHelper();

        try {
            // Remove old avatar
            $existing = $this->db->getRow('SELECT avatar FROM users WHERE id = :id', [':id' => $userId]);
            if (!empty($existing['avatar'])) {
                $uploader->delete($existing['avatar']);
            }

            $result = $uploader->upload(
                $this->request->file('avatar'),
                'user',
                $userId,
                [
                    'prefix'       => 'avatar',
                    'allowed_mime' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
                    'allowed_ext'  => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
                    'max_size'     => 2 * 1024 * 1024, // 2MB for avatars
                ]
            );

            $this->db->update('users', [
                'avatar'     => $result['relative_path'],
                'updated_at' => CommonHelper::now(),
            ], 'id = :id', [':id' => $userId]);

            Response::success([
                'avatar'     => $result['relative_path'],
                'avatar_url' => $result['url'],
                'size'       => $result['size_human'],
            ], 'Avatar uploaded successfully');

        } catch (RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    // ── PUT /user/change-password ────────────────────────────────────────────
    public function changePassword(): void
    {
        $authUser = $this->requireAuth();
        $userId   = $authUser['user_id'];

        $data = $this->validate([
            'current_password'      => 'required|string',
            'new_password'          => 'required|min:8|max:64|confirmed',
            'new_password_confirmation' => 'required',
        ]);

        $user = $this->db->getRow('SELECT id, password FROM users WHERE id = :id', [':id' => $userId]);

        if (!$user || !CommonHelper::verifyPassword($data['current_password'], $user['password'])) {
            Response::error('Current password is incorrect.', 400);
        }

        $this->db->update('users', [
            'password'   => CommonHelper::hashPassword($data['new_password']),
            'updated_at' => CommonHelper::now(),
        ], 'id = :id', [':id' => $userId]);

        // Revoke all refresh tokens on password change
        $this->db->update(
            'refresh_tokens',
            ['revoked' => 1],
            'user_id = :uid',
            [':uid' => $userId]
        );

        $this->logger->info('Password changed', ['user_id' => $userId]);

        Response::success([], 'Password changed successfully. Please log in again.');
    }
}
