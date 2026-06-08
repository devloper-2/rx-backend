<?php

// MailHelper,php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once ROOT_PATH . '/PHPMailer/src/Exception.php';
require_once ROOT_PATH . '/PHPMailer/src/PHPMailer.php';
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

}