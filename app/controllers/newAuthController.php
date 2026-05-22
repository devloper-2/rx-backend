<?php
//newAuthController.php

/**
 * AuthController — Handles authentication endpoints.
 *
 * POST /auth/register  → register()
 * POST /auth/login     → login()
 */


declare(strict_types=1);

require_once ROOT_PATH . '/app/helpers/MailHelper.php';
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
            'password' => 'required',
        ]);

        $user = $this->db->getRow(
            //'SELECT * FROM users WHERE name = :name LIMIT 1',
            'SELECT id, password, status, role FROM users WHERE name = :name LIMIT 1',
            [':name' => ($data['name'])]
        );

        $errors = [];

        if (!$user) {
            $errors['name'][] = 'Name not found';
        } else {
            if (!CommonHelper::verifyPassword($data['password'], $user['password'])) {
                $errors['password'][] = 'Incorrect password';
            }
        }

        if (!empty($errors)) {
            echo json_encode([
                "success" => false,
                "message" => "Validation Failed",
                "errors" => $errors ?? []
            ]);
            http_response_code(401);
            exit;
            }

        if ($user['status'] !== 'active') {
            Response::error('Your account is ' . $user['status'] . '. Please contact support.', 403);
        }

        $accessToken  = $this->auth->generateToken(['user_id' => (int) $user['id'], 'role' => $user['role']]);
        $refreshToken = $this->auth->generateRefreshToken((int) $user['id'], $accessToken);

        
        //$this->logger->info('User logged in', ['user_id' => $user['id'], 'name' => $user['name']]);

        // update last login // run slow tasks AFTER response
        $this->db->update('users', ['last_login_at' => CommonHelper::now(), 'updated_at' => CommonHelper::now()], 'id = :id', [':id' => $user['id']]);

        // ✅ REMOVE PASSWORD BEFORE RESPONSE
        unset($user['password']);

        ob_clean();

        header('Content-Type: application/json');
        http_response_code(200);

        echo json_encode([
            'data'    => [
                'user' => $user,
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
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



// POST/auth/forgot-password-otp
public function forgotPasswordOtp()
{
    $data = $this->validate([
        'phone' => 'required|phone_validation'
    ]);

    $user = $this->db->getRow(
        'SELECT id, email FROM users WHERE phone = :phone',
        [':phone' => $data['phone']]
    );

    if (!$user) {
        echo json_encode([
            "success" => false,
            "errors" => [
                "phone" => ["Mobile number not registered"]
            ]
        ]);
        exit;
    }

    // Generate OTP
    $otp = rand(100000, 999999);

    $this->db->update(
        'users',
        [
            'otp' => $otp,
            'otp_expires' => date("Y-m-d H:i:s", strtotime("+5 minutes"))
        ],
        'id = :id',
        [':id' => $user['id']]
    );

    // 1. Send response FIRST
echo json_encode([
    "success" => true,
    "message" => "OTP generated",
    "email" => $user['email']
]);

// 2. Close response (VERY IMPORTANT)
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

if (empty($user['email'])) {
    echo json_encode([
        "success" => false,
        "message" => "Email not registered for this user"
    ]);
    exit;
}

// 3. Send email in background
MailHelper::sendResetEmail($user['email'], $otp);

exit;
}



// POST/auth/reset-password-otp
public function resetPasswordOtp()
{
    $data = $this->validate([
        //'email' => 'required|email',
        'phone' => 'required',
        'otp' => 'required',
        'password' => 'required|password_validation'
    ]);

    $user = $this->db->getRow(
        'SELECT id, otp, otp_expires FROM users WHERE phone = :phone',
        [':phone' => $data['phone']]
    );

    if (!$user || $user['otp'] != $data['otp']) {
        echo json_encode([
            "success" => false,
            "errors" => [
                "otp" => ["Invalid OTP"]
            ]
        ]);
        exit;
    }

    if (strtotime($user['otp_expires']) < time()) {
        echo json_encode([
            "success" => false,
            "errors" => [
                "otp" => ["OTP expired"]
            ]
        ]);
        exit;
    }

    $this->db->update(
        'users',
        [
            'password' => CommonHelper::hashPassword($data['password']),
            'otp' => null,
            'otp_expires' => null
        ],
        'id = :id',
        [':id' => $user['id']]
    );

    echo json_encode([
        "success" => true,
        "message" => "Password updated"
    ]);
    exit;
}


}
