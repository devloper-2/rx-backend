<?php
//newAuthController.php

/**
 * AuthController — Handles authentication endpoints.
 *
 * POST /auth/register  → register()
 * POST /auth/login     → login()
 */

declare(strict_types=1);

require_once ROOT_PATH . '/app/controllers/BaseController.php';

class NewAuthController extends BaseController
{
    //  POST /auth/register 
    public function register(array $params = []): void
    {
        $data = $this->validate([
            'name'                  => 'required',
            'password'              => 'required|password_validation',
            'countrycode'           => 'required|country_code_validation', 
            'phone'                 => 'required|phone_validation|unique_phone',
        ]);

        try {
            $this->db->beginTransaction();

            $userId = $this->db->insert('users', [
                'name'       => ($data['name']),
                'password'   => CommonHelper::hashPassword($data['password']),
                'phone'      => $data['phone'],
                'countrycode' => $data['countrycode'],
                'role'       => 'user',
                'status'     => 'active',
                'created_at' => CommonHelper::now(),
            ]);

            $accessToken  = $this->auth->generateToken(['user_id' => $userId, 'role' => 'user']);
            $refreshToken = $this->auth->generateRefreshToken($userId, $accessToken);

            $this->db->commit();

            $user = $this->db->getRow('SELECT id, name, phone, countrycode, role, status, created_at FROM users WHERE id = :id', [':id' => $userId]);

            header('Content-Type: application/json');
            http_response_code(201);
            echo json_encode([
                'user' => $user,
                //'access_token' => $accessToken,
                //'refresh_token' => $refreshToken,
                'token_type'    => 'Bearer',
                'expires_in'    => Config::get('jwt.expiry'),
            ]);
            exit;

        } catch (Throwable $e) {
            $this->db->rollback();
            $this->logger->error('Registration failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
   
    
    // POST /auth/login
    public function login(array $params = []): void
    {
        $data = $this->validate([
            'name'     => 'required',
            'password' => 'required|password_validation',
        ]);

        $user = $this->db->getRow(
            'SELECT * FROM users WHERE name = :name LIMIT 1',
            [':name' => ($data['name'])]
        );

        if (!$user || !CommonHelper::verifyPassword($data['password'], $user['password'])) {
            //Response::error('Invalid name or password.', 401);
            if (!$user) {
                Response::error('Username not found', 401);
            }

            if (!CommonHelper::verifyPassword($data['password'], $user['password'])) {
                Response::error('Incorrect password', 401);
            }
        }

        if ($user['status'] !== 'active') {
            Response::error('Your account is ' . $user['status'] . '. Please contact support.', 403);
        }

        $accessToken  = $this->auth->generateToken(['user_id' => (int) $user['id'], 'role' => $user['role']]);
        $refreshToken = $this->auth->generateRefreshToken((int) $user['id'], $accessToken);

        // update last login
        $this->db->update('users', ['last_login_at' => CommonHelper::now(), 'updated_at' => CommonHelper::now()], 'id = :id', [':id' => $user['id']]);

        $this->logger->info('User logged in', ['user_id' => $user['id'], 'name' => $user['name']]);

        // ✅ REMOVE PASSWORD BEFORE RESPONSE
        unset($user['password']);

        header('Content-Type: application/json');
        http_response_code(200);

        echo json_encode([
            'data'    => [
                'user' => $user,
                'access_token'  => $accessToken,
                //'refresh_token' => $refreshToken,
                //'token_type'    => 'Bearer',
                'expires_in'    => Config::get('jwt.expiry'),
            ]
        ]);
        exit;
    }

    // POST /auth/refresh 
    public function refresh(array $params = []): void
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


    // POST /auth/logout 
    public function logout(array $params = []): void
    {
        $data = $this->validate([
            'refresh_token' => 'required|string',
        ]);

        $this->auth->revokeRefreshToken($data['refresh_token']);

        $this->logger->info('User logged out', ['user_id' => $this->authUser['user_id'] ?? null]);

        Response::success([], 'Logged out successfully');
    }

}
