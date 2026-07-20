<?php
/*
// MailHelper,php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once ROOT_PATH . '/PHPATH . '/PHPMailer/src/PHPMailer.php';
require_once ROOT_PMailer/src/Exception.php';
require_once ROOT_PATH . '/PHPMailer/src/SMTP.php';

class MailHelper
{
    // For forgot password
    public static function sendResetEmail($toEmail, $otp){
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'vedanshuonwork@gmail.com';
        $mail->Password   = 'onyp kirv hndr nnaw';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->Timeout    = 10;

        $mail->SMTPDebug = 2;
        $mail->Debugoutput = 'error_log';

        $mail->setFrom('vedanshuonwork@gmail.com', 'PrescriptionRx');
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = 'Password Reset OTP';
        $mail->Body = "
            <img src='cid:logo_cid' alt='Prescription RX Logo' style='height: 60px; margin-bottom: 20px;' />
            <h2>Password Reset OTP</h2>
            <p>Your OTP is:</p>
            <h1>$otp</h1>
            <p>This OTP is valid for 5 minutes.</p>
        ";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mail send failed: " . $mail->ErrorInfo);
        return false;
    }
}

// For verification during registartion
public static function sendRegisterOtpEmail($toEmail, $otp)
{
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'vedanshuonwork@gmail.com';
        $mail->Password   = 'onyp kirv hndr nnaw';

        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('vedanshuonwork@gmail.com', 'PrescriptionRx');
        $mail->addAddress($toEmail);

        $mail->isHTML(true);
        $mail->Subject = 'Verify Your Account';

        $mail->Body = "
            <img src='cid:logo_cid' alt='Prescription RX Logo' style='height: 60px; margin-bottom: 20px;' />
            <h2>Welcome to PrescriptionRx</h2>
            <p>Your OTP for account verification is:</p>
            <h1>$otp</h1>
            <p>This OTP is valid for 5 minutes.</p>
        ";

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("Mail send failed: " . $mail->ErrorInfo);
        return false;
    }
}

}*/




declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once ROOT_PATH . '/PHPMailer/src/Exception.php';
require_once ROOT_PATH . '/PHPMailer/src/PHPMailer.php';
require_once ROOT_PATH . '/PHPMailer/src/SMTP.php';

class MailHelper
{
    private static function baseMailer(): PHPMailer
    {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host       = Config::get('mail.host', 'smtp.gmail.com');
        $mail->SMTPAuth   = true;
        $mail->Username   = Config::get('mail.username'); // change email from config.php
        $mail->Password   = Config::get('mail.password'); // change password from config.php
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = Config::get('mail.port', 587);

        // Debug only in development
        if (Config::isDevelopment()) {
            $mail->SMTPDebug = 2;
            $mail->Debugoutput = 'error_log';
        }

        $mail->setFrom(
            Config::get('mail.from_email'),
            Config::get('mail.from_name', 'PrescriptionRx')
        );

        return $mail;
    }

    // ── GENERIC SEND ───────────────────────────────────────
    private static function send(string $to, string $subject, string $body): bool
    {
        try {
            $mail = self::baseMailer();

            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;

            $mail->send();
            return true;

        } catch (Exception $e) {
            Logger::getInstance()->error('Mail failed', [
                'email' => $to,
                'error' => $e->getMessage()
            ]);
            //return false;
             Response::error($mail->ErrorInfo, 500);
        }
    }

    // ── RESET PASSWORD OTP ─────────────────────────────────
    public static function sendResetEmail(string $toEmail, string $otp): bool
    {
        $body = "
            <h2>Password Reset</h2>
            <p>Your OTP is:</p>
            <h1>{$otp}</h1>
            <p>Valid for 5 minutes.</p>
        ";

        return self::send($toEmail, 'Password Reset OTP', $body);
    }

    // ── REGISTER OTP ───────────────────────────────────────
    public static function sendRegisterOtpEmail(string $toEmail, string $otp): bool
    {
        $body = "
            <h2>Welcome to PrescriptionRx</h2>
            <p>Your OTP is:</p>
            <h1>{$otp}</h1>
            <p>Valid for 5 minutes.</p>
        ";

        return self::send($toEmail, 'Verify Your Account', $body);
    }
}