<?php
//newAuthController.php

/**
 * AuthController — Handles authentication endpoints.
 *
 * POST /auth/register  → register()
 * POST /auth/login     → login()
 */

// check why the email during registration is validating
/*
declare(strict_types=1);

require_once ROOT_PATH . '/app/helpers/MailHelper.php';
require_once ROOT_PATH . '/app/controllers/BaseController.php';

class NewAuthController extends BaseController
{

    //  POST /auth/register 
    public function register(array $params = []): void
    {
        $data = $this->validate([
            'name'        => 'required',
            'password'    => 'required|password_validation',
            'email'       => 'required|email|unique_email',
            'countrycode' => 'required|country_code_validation',
            'phone'       => 'required|phone_validation|unique_phone',

            'degree'      => 'nullable|string',
            'clinicname'  => 'nullable|string',
            'address'     => 'nullable|string',
            'image'       => 'nullable|string',
    ]);

    try {
        $this->db->beginTransaction();

        //  INSERT USER FIRST
        $userId = $this->db->insert('users', [
            'name'       => $data['name'],
            'email'      => $data['email'],
            'password'   => CommonHelper::hashPassword($data['password']),
            'phone'      => $data['phone'],
            'countrycode'=> $data['countrycode'],
            'role'       => 'user',
            'status'     => 'inactive', //  IMPORTANT (not active yet)
            'created_at' => CommonHelper::now(),
            'degree'     => $data['degree'],
            'hc_name'    => $data['clinicname'],
            'hc_address' => $data['address'],
        ]);

        //  CREATE UPLOAD FOLDER
        $uploadPath = ROOT_PATH . "/uploads/$userId/";
        if (!file_exists($uploadPath)) {
            mkdir($uploadPath, 0777, true);
        }

        //  SAVE IMAGE
        if (!empty($data['image'])) {
            $imageData = base64_decode($data['image']);

            $fileName = $userId . "_" . time() . ".png";
            file_put_contents($uploadPath . $fileName, $imageData);

            // save path in DB
            $this->db->update('users', [
                'avatar' => "uploads/$userId/$fileName"
            ], 'id = :id', [':id' => $userId]);
        }

        //  GENERATE OTP
        $otp = rand(100000, 999999);

        $this->db->update('users', [
            'otp' => $otp,
            'otp_expires' => date("Y-m-d H:i:s", strtotime("+5 minutes"))
        ], 'id = :id', [':id' => $userId]);

        $this->db->commit();

        //  SEND RESPONSE FIRST
        echo json_encode([
            "success" => true,
            "message" => "User registered. OTP sent to email.",
            "email" => $data['email']
        ]);

        // close response
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        //  SEND EMAIL
        MailHelper::sendRegisterOtpEmail($data['email'], $otp);

        exit;

    } catch (Throwable $e) {
        $this->db->rollback();
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

        $input = trim($data['name']);

        $conditions = [];
        $params = [];

        //  enable/disable fields easily
        $conditions[] = "email = :email";
        $params[':email'] = $input;

        $conditions[] = "phone = :phone";
        $params[':phone'] = $input;

        //  if you want name, just add this line
        // $conditions[] = "name = :name";
        // $params[':name'] = $input;

        $where = implode(" OR ", $conditions);

        $user = $this->db->getRow(
            "SELECT id, password, status, role FROM users WHERE $where LIMIT 1",
            $params
        );

        $errors = [];

        if (!$user) {
            $errors['name'][] = 'User not found';
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

        /*if ($user['status'] !== 'active') {
            Response::error('Your account is ' . $user['status'] . '. Please contact support.', 403);
        }*/
        if ($user['status'] !== 'active') {

    //  GET REAL EMAIL
    $userFull = $this->db->getRow(
        "SELECT email FROM users WHERE id = :id",
        [':id' => $user['id']]
    );

    $userEmail = $userFull['email'];

    //  GENERATE NEW OTP
    $otp = rand(100000, 999999);

    $this->db->update('users', [
        'otp' => $otp,
        'otp_expires' => date("Y-m-d H:i:s", strtotime("+5 minutes"))
    ], 'id = :id', [':id' => $user['id']]);

    //  SEND RESPONSE FIRST
    echo json_encode([
        "success" => false,
        "inactive" => true,
        "message" => "Account not verified. OTP sent again.",
        "email" => $userEmail
    ]);

    //  CLOSE RESPONSE
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    //  SEND EMAIL IN BACKGROUND
    MailHelper::sendRegisterOtpEmail($userEmail, $otp);

    exit;
}

        $accessToken  = $this->auth->generateToken(['user_id' => (int) $user['id'], 'role' => $user['role']]);
        $refreshToken = $this->auth->generateRefreshToken((int) $user['id'], $accessToken);

        
        //$this->logger->info('User logged in', ['user_id' => $user['id'], 'name' => $user['name']]);

        // update last login // run slow tasks AFTER response
        $this->db->update('users', ['last_login_at' => CommonHelper::now(), 'updated_at' => CommonHelper::now()], 'id = :id', [':id' => $user['id']]);

        //  REMOVE PASSWORD BEFORE RESPONSE
        unset($user['password']);

        ob_clean();

        header('Content-Type: application/json');
        http_response_code(200);

        echo json_encode([
             "success" => true,
            'data'    => [
                'user' => $user,
                'access_token'  => $accessToken,//can be commenetd
                'refresh_token' => $refreshToken,// can be commented
                //'token_type'    => 'Bearer',
                'expires_in'    => Config::get('jwt.expiry'),// can be commented
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
            'email' => 'required|email'
        ]);

        $user = $this->db->getRow(
            'SELECT id, email FROM users WHERE email = :email',
            [':email' => $data['email']]
        );

        if (!$user) {
            echo json_encode([
                "success" => false,
                "errors" => [
                    "email" => ["Email address not registered"]
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
            'email' => 'required|email',
            'otp' => 'required',
            'password' => 'required|password_validation'
        ]);

        $user = $this->db->getRow(
            'SELECT id, otp, otp_expires FROM users WHERE email = :email',
            [':email' => $data['email']]
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

    // Verify details while registration
    public function verifyOtp()
    {
        $data = $this->validate([
            'email' => 'required|email',
            'otp'   => 'required'
        ]);

        $user = $this->db->getRow(
            'SELECT id, otp, otp_expires FROM users WHERE email = :email',
            [':email' => $data['email']]
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

        //  ACTIVATE USER
        $this->db->update('users', [
            'status' => 'active',
            'otp' => null,
            'otp_expires' => null
        ], 'id = :id', [':id' => $user['id']]);

        echo json_encode([
            "success" => true,
            "message" => "Account verified Successfully"
        ]);
        exit;
    }

// resend otp to verify details while registration
public function resendOtp()
{
    $data = $this->validate([
        'email' => 'required|email'
    ]);

    $user = $this->db->getRow(
        'SELECT id FROM users WHERE email = :email',
        [':email' => $data['email']]
    );

    if (!$user) {
        echo json_encode([
            "success" => false,
            "message" => "User not found"
        ]);
        exit;
    }

    //  generate NEW OTP
    $otp = rand(100000, 999999);

    //  overwrite old OTP (auto expire old)
    $this->db->update('users', [
        'otp' => $otp,
        'otp_expires' => date("Y-m-d H:i:s", strtotime("+5 minutes"))
    ], 'id = :id', [':id' => $user['id']]);

    echo json_encode([
        "success" => true,
        "message" => "OTP resent"
    ]);

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    // for resend who register first time
    MailHelper::sendRegisterOtpEmail($data['email'], $otp);
    // for inactive resend password
    MailHelper::sendResetEmail($data['email'], $otp);//MailHelper::sendResetEmail($email, $otp); 

    exit;
}

    // check phone number is present during first page register
public function checkPhone()
{
    $data = json_decode(file_get_contents("php://input"), true);

    $phone = $data['phone'] ?? null;
$countryCode = $data['countrycode'] ?? null;

if (!$phone || !$countryCode) {
    echo json_encode(["success" => false, "message" => "Phone and country code required"]);
    return;
}

$user = $this->db->getRow(
    "SELECT id FROM users WHERE phone = :phone AND countrycode = :countrycode",
    [
        ':phone' => $phone,
        ':countrycode' => $countryCode
    ]
);

    echo json_encode([
        "success" => true,
        "data" => [
            "exists" => $user ? true : false
        ]
    ]);
}

// check email is present during first page register
public function checkEmail()
{
    $data = json_decode(file_get_contents("php://input"), true);

    $email = $data['email'] ?? null;

    if (!$email) {
        echo json_encode(["success" => false, "message" => "Email required"]);
        return;
    }

    $user = $this->db->getRow(
        "SELECT id FROM users WHERE email = :email",
        [':email' => $email]
    );

    echo json_encode([
        "success" => true,
        "data" => [
            "exists" => $user ? true : false
        ]
    ]);
}
}

*/

/* <?php
//newAuthController.php

//**
// * AuthController — Handles authentication endpoints.
// *
// * POST /auth/register  → register()
// * POST /auth/login     → login()
 

// check why the email during registration is validating

declare(strict_types=1);

require_once ROOT_PATH . '/app/helpers/MailHelper.php';
require_once ROOT_PATH . '/app/controllers/BaseController.php';

class NewAuthController extends BaseController
{

    //  POST /auth/register 
    public function register(array $params = []): void
    {
        $data = $this->validate([
            'name'        => 'required',
            'password'    => 'required|password_validation',
            'email'       => 'required|email|unique_email',
            'countrycode' => 'required|country_code_validation',
            'phone'       => 'required|phone_validation|unique_phone',

            'degree'      => 'nullable|string',
            'clinicname'  => 'nullable|string',
            'address'     => 'nullable|string',
            'image'       => 'nullable|string',
    ]);

    try {
        $this->db->beginTransaction();

        // ✅ INSERT USER FIRST
        $userId = $this->db->insert('users', [
            'name'       => $data['name'],
            'email'      => $data['email'],
            'password'   => CommonHelper::hashPassword($data['password']),
            'phone'      => $data['phone'],
            'countrycode'=> $data['countrycode'],
            'role'       => 'user',
            'status'     => 'inactive', // 🔥 IMPORTANT (not active yet)
            'created_at' => CommonHelper::now(),
            'degree'     => $data['degree'],
            'hc_name'    => $data['clinicname'],
            'hc_address' => $data['address'],
        ]);

        // ✅ CREATE UPLOAD FOLDER
        $uploadPath = ROOT_PATH . "/uploads/$userId/";
        if (!file_exists($uploadPath)) {
            mkdir($uploadPath, 0777, true);
        }

        // ✅ SAVE IMAGE
        if (!empty($data['image'])) {
            $imageData = base64_decode($data['image']);

            $fileName = $userId . "_" . time() . ".png";
            file_put_contents($uploadPath . $fileName, $imageData);

            // save path in DB
            $this->db->update('users', [
                'avatar' => "uploads/$userId/$fileName"
            ], 'id = :id', [':id' => $userId]);
        }

        // ✅ GENERATE OTP
        $otp = rand(100000, 999999);

        $this->db->update('users', [
            'otp' => $otp,
            'otp_expires' => date("Y-m-d H:i:s", strtotime("+5 minutes"))
        ], 'id = :id', [':id' => $userId]);

        $this->db->commit();

        // ✅ SEND RESPONSE FIRST
        echo json_encode([
            "success" => true,
            "message" => "User registered. OTP sent to email.",
            "email" => $data['email']
        ]);

        // close response
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        // ✅ SEND EMAIL
        MailHelper::sendRegisterOtpEmail($data['email'], $otp);

        exit;

    } catch (Throwable $e) {
        $this->db->rollback();
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

        $input = trim($data['name']);

        $conditions = [];
        $params = [];

        // ✅ enable/disable fields easily
        $conditions[] = "email = :email";
        $params[':email'] = $input;

        $conditions[] = "phone = :phone";
        $params[':phone'] = $input;

        // 👉 if you want name, just add this line
        // $conditions[] = "name = :name";
        // $params[':name'] = $input;

        $where = implode(" OR ", $conditions);

        $user = $this->db->getRow(
            "SELECT id, password, status, role FROM users WHERE $where LIMIT 1",
            $params
        );

        $errors = [];

        if (!$user) {
            $errors['name'][] = 'User not found';
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

        //if ($user['status'] !== 'active') {
        //    Response::error('Your account is ' . $user['status'] . '. Please contact support.', 403);
        //}
        if ($user['status'] !== 'active') {

    // 🔥 GET REAL EMAIL
    $userFull = $this->db->getRow(
        "SELECT email FROM users WHERE id = :id",
        [':id' => $user['id']]
    );

    $userEmail = $userFull['email'];

    // 🔥 GENERATE NEW OTP
    $otp = rand(100000, 999999);

    $this->db->update('users', [
        'otp' => $otp,
        'otp_expires' => date("Y-m-d H:i:s", strtotime("+5 minutes"))
    ], 'id = :id', [':id' => $user['id']]);

    // 🔥 SEND RESPONSE FIRST
    echo json_encode([
        "success" => false,
        "inactive" => true,
        "message" => "Account not verified. OTP sent again.",
        "email" => $userEmail
    ]);

    // 🔥 CLOSE RESPONSE
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    // 🔥 SEND EMAIL IN BACKGROUND
    MailHelper::sendRegisterOtpEmail($userEmail, $otp);

    exit;
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
             "success" => true,
            'data'    => [
                'user' => $user,
                'access_token'  => $accessToken,//can be commenetd
                'refresh_token' => $refreshToken,// can be commented
                //'token_type'    => 'Bearer',
                'expires_in'    => Config::get('jwt.expiry'),// can be commented
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
            'email' => 'required|email'
        ]);

        $user = $this->db->getRow(
            'SELECT id, email FROM users WHERE email = :email',
            [':email' => $data['email']]
        );

        if (!$user) {
            echo json_encode([
                "success" => false,
                "errors" => [
                    "email" => ["Email address not registered"]
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
            'email' => 'required|email',
            'otp' => 'required',
            'password' => 'required|password_validation'
        ]);

        $user = $this->db->getRow(
            'SELECT id, otp, otp_expires FROM users WHERE email = :email',
            [':email' => $data['email']]
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

    // Verify details while registration
    public function verifyOtp()
    {
        $data = $this->validate([
            'email' => 'required|email',
            'otp'   => 'required'
        ]);

        $user = $this->db->getRow(
            'SELECT id, otp, otp_expires FROM users WHERE email = :email',
            [':email' => $data['email']]
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

        // ✅ ACTIVATE USER
        $this->db->update('users', [
            'status' => 'active',
            'otp' => null,
            'otp_expires' => null
        ], 'id = :id', [':id' => $user['id']]);

        echo json_encode([
            "success" => true,
            "message" => "Account verified Successfully"
        ]);
        exit;
    }

// resend otp to verify details while registration
public function resendOtp()
{
    $data = $this->validate([
        'email' => 'required|email'
    ]);

    $user = $this->db->getRow(
        'SELECT id FROM users WHERE email = :email',
        [':email' => $data['email']]
    );

    if (!$user) {
        echo json_encode([
            "success" => false,
            "message" => "User not found"
        ]);
        exit;
    }

    //  generate NEW OTP
    $otp = rand(100000, 999999);

    //  overwrite old OTP (auto expire old)
    $this->db->update('users', [
        'otp' => $otp,
        'otp_expires' => date("Y-m-d H:i:s", strtotime("+5 minutes"))
    ], 'id = :id', [':id' => $user['id']]);

    echo json_encode([
        "success" => true,
        "message" => "OTP resent"
    ]);

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    // for resend who register first time
    MailHelper::sendRegisterOtpEmail($data['email'], $otp);
    // for inactive resend password
    MailHelper::sendResetEmail($data['email'], $otp);//MailHelper::sendResetEmail($email, $otp); 

    exit;
}

    // check phone number is present during first page register
public function checkPhone()
{
    $data = json_decode(file_get_contents("php://input"), true);

    $phone = $data['phone'] ?? null;
$countryCode = $data['countrycode'] ?? null;

if (!$phone || !$countryCode) {
    echo json_encode(["success" => false, "message" => "Phone and country code required"]);
    return;
}

$user = $this->db->getRow(
    "SELECT id FROM users WHERE phone = :phone AND countrycode = :countrycode",
    [
        ':phone' => $phone,
        ':countrycode' => $countryCode
    ]
);

    echo json_encode([
        "success" => true,
        "data" => [
            "exists" => $user ? true : false
        ]
    ]);
}

// check email is present during first page register
public function checkEmail()
{
    $data = json_decode(file_get_contents("php://input"), true);

    $email = $data['email'] ?? null;

    if (!$email) {
        echo json_encode(["success" => false, "message" => "Email required"]);
        return;
    }

    $user = $this->db->getRow(
        "SELECT id FROM users WHERE email = :email",
        [':email' => $email]
    );

    echo json_encode([
        "success" => true,
        "data" => [
            "exists" => $user ? true : false
        ]
    ]);
}
}
 */