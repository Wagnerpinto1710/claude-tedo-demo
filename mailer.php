<?php
/** Shared PHPMailer setup (SMTP settings come from .env). */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/db.php';

use PHPMailer\PHPMailer\PHPMailer;

function ak_mail_configured(): bool
{
    return ak_env('SMTP_HOST') !== null && ak_env('SMTP_USERNAME') !== null;
}

function ak_mailer(): PHPMailer
{
    $mail = new PHPMailer(true);
    $port = (int) ak_env('SMTP_PORT', '465');
    $mail->isSMTP();
    $mail->Host = (string) ak_env('SMTP_HOST', '');
    $mail->SMTPAuth = true;
    $mail->Username = (string) ak_env('SMTP_USERNAME', '');
    $mail->Password = (string) ak_env('SMTP_PASSWORD', '');
    $secure = strtolower((string) ak_env('SMTP_SECURE', $port === 587 ? 'tls' : 'ssl'));
    if ($secure === 'none') {
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;   // local testing only
    } else {
        $mail->SMTPSecure = $secure === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
    }
    $mail->Port = $port;
    $mail->CharSet = 'UTF-8';
    $mail->setFrom((string) ak_env('SMTP_USERNAME', ''), 'Africa Kaiser');
    return $mail;
}

/** Where website messages go. ADMIN_EMAIL can list several addresses separated by commas. */
function ak_admin_emails(): array
{
    $list = array_filter(array_map('trim', explode(',', (string) ak_env('ADMIN_EMAIL', ak_env('SMTP_USERNAME', '')))));
    return array_values(array_filter($list, static fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL)));
}

function ak_admin_email(): string
{
    return ak_admin_emails()[0] ?? (string) ak_env('SMTP_USERNAME', '');
}
