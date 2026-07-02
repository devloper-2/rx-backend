<?php
//newAuthController.php
declare(strict_types=1);

require_once ROOT_PATH . '/app/controllers/BaseController.php';
require_once ROOT_PATH . '/app/helpers/MailHelper.php';

class NewAuthController extends BaseController
{
    // ── REGISTER ─────────────────────────────────────────
    public function register(): void
    {
        $data = $this->validate([
            'name'     => 'required|min:2|max:100',
            'email'    => 'required|email|unique:doctors,email',
            'mobile'   => 'required|mobile|unique:doctors,mobile',
            'password' => 'required|strong_password',
        ]);

        $doctorId = $this->db->insert('doctors', [
            'name'          => $data['name'],
            'email'         => $data['email'],
            'mobile'        => $data['mobile'],
            'password_hash' => CommonHelper::hashPassword($data['password']),
            'degree'        => $data['degree'] ?? null,
            'specialization' => $data['specialization'] ?? null,
            'registration_no' => $data['registration_no'] ?? null,
            'experience_yrs' => $data['experience_yrs'] ?? null,
            'plan'          => 'trial',
            'plan_expires_at' => date('Y-m-d H:i:s', strtotime('+1 month')),
            'is_active'     => 0,
            'created_at'    => CommonHelper::now(),
        ]);

        // GENERATE OTP FIRST
        $otp = CommonHelper::generateOtp();

        // INSERT OTP
        $this->db->insert('verification', [
            'doctor_id'   => $doctorId,
            'otp'         => $otp,
            'otp_expires' => date('Y-m-d H:i:s', strtotime('+5 minutes')),
            'type'        => 'email',
            'send_to'     => $data['email'],
        ]);

        // SEND EMAIL
        MailHelper::sendRegisterOtpEmail($data['email'], $otp);

        // RESPONSE
        Response::success([
            'doctor_id' => $doctorId
        ], 'OTP sent to email');

        if (!$verificationId) {
            Response::error('OTP generation failed', 500);
        }
        
    }

    // ── VERIFY OTP ────────────────────────────────────────
    public function verifyOtp(): void
    {
        $data = $this->validate([
            'doctor_id' => 'required|numeric',
            'otp'       => 'required'
        ]);

        $row = $this->db->getRow(
            "SELECT * FROM verification 
            WHERE doctor_id = :id 
            ORDER BY id DESC 
            LIMIT 1",
            [':id' => $data['doctor_id']]
        );

        if (!$row || $row['otp'] !== $data['otp']) {
            Response::error('Invalid OTP', 400);
        }

        if (strtotime($row['otp_expires']) < time()) {
            Response::error('OTP expired', 400);
        }

        $this->db->update('doctors', [
            'is_active' => 1
        ], 'id = :id', [':id' => $data['doctor_id']]);

        // delete AFTER success
        $this->db->executeQuery(
            "DELETE FROM verification WHERE doctor_id = :id",
            [':id' => $data['doctor_id']]
        );

        // AUTO LOGIN AFTER VERIFY

        $accessToken = $this->auth->generateToken([
            'doctor_id' => (int)$data['doctor_id']
        ]);

        $refreshToken = $this->auth->generateRefreshToken((int)$data['doctor_id']);

        Response::success([
            'doctor' => [
                'id' => $data['doctor_id']
            ],
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken
        ], 'Account verified & logged in');
    }

    // ── LOGIN ─────────────────────────────────────────────
    public function login(): void
    {
            $data = $this->validate([
                'login'    => 'required',
                'password' => 'required',
            ]);

            $doctor = $this->db->getRow(
                "SELECT * FROM doctors 
                WHERE email = :email OR mobile = :mobile",
                [
                    ':email'  => $data['login'],
                    ':mobile' => $data['login']
                ]
            );

            if (!$doctor) {
                Response::error('User not found', 404, [
                    'field' => 'login'
                ]);
            }

            if (!CommonHelper::verifyPassword($data['password'], $doctor['password_hash'])) {
                Response::error('Incorrect password', 401, [
                    'field' => 'password'
                ]);
            }

            /*if (!(int)$doctor['is_active']) {
                Response::error('Account not verified', 403);
            }*/
           if (!(int)$doctor['is_active']) {

                // delete old OTP
                $this->db->executeQuery(
                    "DELETE FROM verification WHERE doctor_id = :id",
                    [':id' => $doctor['id']]
                );

                $otp = CommonHelper::generateOtp();

                // save OTP
                $this->db->insert('verification', [
                    'doctor_id'   => $doctor['id'],
                    'otp'         => $otp,
                    'otp_expires' => date('Y-m-d H:i:s', strtotime('+5 minutes')),
                    'type'        => 'email',
                    'send_to'     => $doctor['email'],
                ]);

                // SEND MAIL (IMPORTANT)
                MailHelper::sendRegisterOtpEmail($doctor['email'], $otp);

                Response::error('Account not verified', 403, [
                    'doctor_id' => $doctor['id'],
                    'action'    => 'verify_otp'
                ]);
            }


            $accessToken = $this->auth->generateToken([
                'doctor_id' => (int)$doctor['id']
            ]);

            $refreshToken = $this->auth->generateRefreshToken((int)$doctor['id']);

            Response::success([
                'doctor' => [
                    'id'    => $doctor['id'],
                    'name'  => $doctor['name'],
                    'email' => $doctor['email']
                ],
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
            ], 'Login successful');
    }

    // ── LOGOUT ────────────────────────────────────────────
    public function logout(): void
    {
        $data = $this->validate([
            'refresh_token' => 'required'
        ]);

        $updated = $this->db->update(
            'auth_tokens',
            ['revoked_at' => CommonHelper::now()],
            'refresh_token = :token',
            [':token' => $data['refresh_token']]
        );

        if ($updated === 0) {
            Response::error('Invalid token', 400);
        }

        Response::success([], 'Logged out successfully');
    }

    // ── FORGOT PASSWORD ───────────────────────────────────
    public function forgotPasswordOtp(): void
    {

        $data = $this->validate([
            'email' => 'required|email'
        ]);

        $doctor = $this->db->getRow(
            "SELECT id FROM doctors WHERE email = :email",
            [':email' => $data['email']]
        );

        if (!$doctor) {
            Response::error('Doctor not found', 404);
        }

        // NOW delete old OTP
        $this->db->executeQuery(
            "DELETE FROM verification WHERE doctor_id = :id",
            [':id' => $doctor['id']]
        );

        $otp = CommonHelper::generateOtp();

        MailHelper::sendResetEmail($data['email'], $otp);
        

        $this->db->insert('verification', [
            'doctor_id'   => $doctor['id'],
            'otp'         => $otp,
            'otp_expires' => date('Y-m-d H:i:s', strtotime('+5 minutes')),
            'type'        => 'email',
            'send_to'     => $data['email'],
        ]);

        Response::success([
            'doctor_id' => $doctor['id']
        ], 'OTP sent to email');
    }

    // ── RESET PASSWORD ────────────────────────────────────
    public function resetPasswordOtp(): void
    {
        $data = $this->validate([
            'doctor_id' => 'required',
            'otp'       => 'required',
            'password'  => 'required|strong_password'
        ]);

        $row = $this->db->getRow(
            "SELECT * FROM verification 
            WHERE doctor_id = :id 
            ORDER BY id DESC 
            LIMIT 1",
            [':id' => $data['doctor_id']]
        );

        if (!$row || $row['otp'] !== $data['otp']) {
            Response::error('Invalid OTP', 400);
        }

        // check expiry manually (PHP side)
        if (strtotime($row['otp_expires']) < time()) {
            Response::error('OTP expired', 400);
        }

        $this->db->update('doctors', [
            'password_hash' => CommonHelper::hashPassword($data['password'])
        ], 'id = :id', [':id' => $data['doctor_id']]);

        // DELETE OTP after success
        $this->db->executeQuery(
            "DELETE FROM verification WHERE doctor_id = :id",
            [':id' => $data['doctor_id']]
        );

        Response::success([], 'Password reset successful');
    }

    // ── RESET OTP ────────────────────────────────────
    public function resendOtp(): void
    {
        $data = $this->validate([
            'doctor_id' => 'required|numeric'
        ]);

        $doctor = $this->db->getRow(
            "SELECT email FROM doctors WHERE id = :id",
            [':id' => $data['doctor_id']]
        );

        if (!$doctor) {
            Response::error('Doctor not found', 404);
        }

        $this->db->executeQuery(
            "DELETE FROM verification WHERE doctor_id = :id",
            [':id' => $data['doctor_id']]
        );

        $otp = CommonHelper::generateOtp();

        MailHelper::sendRegisterOtpEmail($doctor['email'], $otp);

        $this->db->insert('verification', [
            'doctor_id'   => $data['doctor_id'],
            'otp'         => $otp,
            'otp_expires' => date('Y-m-d H:i:s', strtotime('+5 minutes')),
            'type'        => 'email',
            'send_to'     => $doctor['email'],
        ]);

        Response::success([], 'OTP resent');
    }

    // ── CHECK EMAIL ────────────────────────────────────
    public function checkEmail(): void
    {
        $data = $this->validate([
            'email' => 'required|email'
        ]);

        $exists = $this->db->getRow(
            "SELECT id FROM doctors WHERE email = :email",
            [':email' => $data['email']]
        );

        Response::success([
            'exists' => $exists ? true : false
        ]);
    }

    // ── CHECK PHONE ────────────────────────────────────
    public function checkPhone(): void
    {
        $data = $this->validate([
            'phone' => 'required',
            'countrycode' => 'required'
        ]);

        $fullPhone = $data['countrycode'] . $data['phone'];

        $exists = $this->db->getRow(
            "SELECT id FROM doctors WHERE mobile = :mobile",
            [':mobile' => $fullPhone]
        );

        Response::success([
            'exists' => $exists ? true : false
        ]);
    }
}
