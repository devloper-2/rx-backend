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

/* {
  "name": "Test",
  "email":"test@int.com",
  "password":"NEWtest1@",
  "countrycode":"+92",
  "phone":"1234567000101"
} */

  /* {
  "name": "hero",
  "email":"hero@int.com",
  "password":"Herotest1@",
  "countrycode":"+92",
  "phone":"1234567000101"
} */

  // Client , +1 0987654321 , Client123@
  // Welcome , +91 1234567890 , Welcome12@
  // Ajay Raj  , +1 123456789 , Ajay123@

class NewAuthController extends BaseController
{
    //  POST /auth/register 
    public function register(): void
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

            $this->db->commit();

            $user = $this->db->getRow('SELECT id, name, phone, countrycode, role, status, created_at FROM users WHERE id = :id', [':id' => $userId]);

            header('Content-Type: application/json');
            http_response_code(201);
            echo json_encode([
                'user' => $user,
                'access_token' => $accessToken,
            ]);
            exit;

        } catch (Throwable $e) {
            $this->db->rollback();
            $this->logger->error('Registration failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
   
    
    // POST /auth/login
    public function login(): void
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
            Response::error('Invalid name or password.', 401);
        }

        // update last login
        $this->db->update('users', ['last_login_at' => CommonHelper::now(), 'updated_at' => CommonHelper::now()], 'id = :id', [':id' => $user['id']]);

        $this->logger->info('User logged in', ['user_id' => $user['id'], 'name' => $user['name']]);

        header('Content-Type: application/json');
        http_response_code(200);

        echo json_encode([
            'data'    => [
                'user' => $user,
            ]
        ]);
        exit;
    }

    
}
