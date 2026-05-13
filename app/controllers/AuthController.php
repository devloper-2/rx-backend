<?php

declare(strict_types=1);

require_once ROOT_PATH . '/app/controllers/BaseController.php';

/**
 * AuthController — Handles authentication endpoints.
 *
 * POST /auth/register  → register()
 * POST /auth/login     → login()
 * POST /auth/refresh   → refresh()
 * POST /auth/logout    → logout()
 * GET  /auth/me        → me()  [requires JWT]
 */
class AuthController extends BaseController
{
    // ── POST /auth/register ──────────────────────────────────────────────────
    public function register(): void
    {
        $data = $this->validate([
            'name'                  => 'required|string|min:2|max:100',
            'email'                 => 'required|email|unique_email',
            'password'              => 'required|min:8|max:64|confirmed',
            'password_confirmation' => 'required',
            'phone'                 => 'nullable|string|min:7|max:20',
        ]);

        try {
            $this->db->beginTransaction();

            $userId = $this->db->insert('users', [
                'name'       => CommonHelper::sanitizeString($data['name']),
                'email'      => strtolower(trim($data['email'])),
                'password'   => CommonHelper::hashPassword($data['password']),
                'phone'      => $data['phone'] ?? null,
                'role'       => 'user',
                'status'     => 'active',
                'created_at' => CommonHelper::now(),
                'updated_at' => CommonHelper::now(),
            ]);

            $accessToken  = $this->auth->generateToken(['user_id' => $userId, 'role' => 'user']);
            $refreshToken = $this->auth->generateRefreshToken($userId, $accessToken);

            $this->db->commit();

            $user = $this->db->getRow('SELECT id, name, email, phone, role, status, created_at FROM users WHERE id = :id', [':id' => $userId]);

            Response::created([
                'user'          => $user,
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
                'token_type'    => 'Bearer',
                'expires_in'    => Config::get('jwt.expiry'),
            ], 'Registration successful');

        } catch (Throwable $e) {
            $this->db->rollback();
            $this->logger->error('Registration failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    // ── POST /auth/login ─────────────────────────────────────────────────────
    public function login(): void
    {
        $data = $this->validate([
            'email'    => 'required|email',
            'password' => 'required|min:1',
        ]);

        $user = $this->db->getRow(
            'SELECT * FROM users WHERE email = :email LIMIT 1',
            [':email' => strtolower(trim($data['email']))]
        );

        if (!$user || !CommonHelper::verifyPassword($data['password'], $user['password'])) {
            // Use same message to prevent email enumeration
            Response::error('Invalid email or password.', 401);
        }

        if ($user['status'] !== 'active') {
            Response::error('Your account is ' . $user['status'] . '. Please contact support.', 403);
        }

        $accessToken  = $this->auth->generateToken(['user_id' => (int) $user['id'], 'role' => $user['role']]);
        $refreshToken = $this->auth->generateRefreshToken((int) $user['id'], $accessToken);

        // Update last login
        $this->db->update('users', ['last_login_at' => CommonHelper::now(), 'updated_at' => CommonHelper::now()], 'id = :id', [':id' => $user['id']]);

        $this->logger->info('User logged in', ['user_id' => $user['id'], 'email' => CommonHelper::maskEmail($user['email'])]);

        Response::success([
            'user' => CommonHelper::except($user, ['password']),
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type'    => 'Bearer',
            'expires_in'    => Config::get('jwt.expiry'),
        ], 'Login successful');
    }

    // ── POST /auth/refresh ───────────────────────────────────────────────────
    public function refresh(): void
    {
        $data = $this->validate([
            'refresh_token' => 'required|string',
        ]);

        try {
            $result = $this->auth->refreshAccessToken($data['refresh_token']);
            Response::success(array_merge($result, ['expires_in' => Config::get('jwt.expiry')]), 'Token refreshed');
        } catch (RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() ?: 401);
        }
    }

    // ── POST /auth/logout ────────────────────────────────────────────────────
    public function logout(): void
    {
        $data = $this->validate([
            'refresh_token' => 'required|string',
        ]);

        $this->auth->revokeRefreshToken($data['refresh_token']);

        $this->logger->info('User logged out', ['user_id' => $this->authUser['user_id'] ?? null]);

        Response::success([], 'Logged out successfully');
    }

    // ── GET /auth/me ─────────────────────────────────────────────────────────
    public function me(): void
    {
        $authUser = $this->requireAuth();

        $user = $this->db->getRow(
            'SELECT id, name, email, phone, role, status, avatar, last_login_at, created_at FROM users WHERE id = :id',
            [':id' => $authUser['user_id']]
        );

        if (!$user) {
            Response::notFound('User not found.');
        }

        // Add avatar URL if exists
        if (!empty($user['avatar'])) {
            $uploader     = new FileUploadHelper();
            $user['avatar_url'] = $uploader->url($user['avatar']);
        }

        Response::success(['user' => $user], 'Profile fetched');
    }
}
