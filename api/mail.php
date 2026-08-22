<?php
require __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Sends the OTP email. Returns true on success, or throws PHPMailer\Exception on failure.
 */
function send_otp_email(string $toEmail, string $code): bool {
    $config = require __DIR__ . '/config.php';

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $config['smtp_host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $config['smtp_user'];
    $mail->Password   = $config['smtp_pass'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = $config['smtp_port'];

    $mail->setFrom($config['from_email'], $config['from_name']);
    $mail->addAddress($toEmail);

    $mail->isHTML(true);
    $mail->Subject = 'Your EduTrack verification code';
    $mail->Body    = "<p>Your verification code is:</p><h2 style=\"letter-spacing:4px;\">{$code}</h2><p>This code expires in 10 minutes.</p>";
    $mail->AltBody  = "Your verification code is: {$code} (expires in 10 minutes)";

    return $mail->send();
}
