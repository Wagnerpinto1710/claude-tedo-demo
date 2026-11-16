<?php
/**
 * Africa Kaiser – contact form & quote request mailer.
 *
 * Receives POSTs from the website (Contact page and Checkout) and emails them to ADMIN_EMAIL.
 * Replies with JSON: {"ok": true} or {"ok": false, "error": "..."}.
 *
 * Setup (once, on the server, in the same folder as this file):
 *   composer require phpmailer/phpmailer
 * Then create a .env file next to this script (never commit it):
 *   SMTP_HOST=smtp.yourprovider.com
 *   SMTP_PORT=465            # 465 = SSL, 587 = STARTTLS
 *   SMTP_USERNAME=website@africakaiser.co.uk
 *   SMTP_PASSWORD=********
 *   ADMIN_EMAIL=founder@africakaiser.co.uk
 *   ALLOWED_ORIGIN=https://www.africakaiser.co.uk   # optional
 */

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

header('Content-Type: application/json; charset=utf-8');

function reply(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body);
    exit;
}

// --- Config (read from .env by db.php) ---
if (ak_env('SMTP_HOST') === null || ak_env('SMTP_USERNAME') === null) {
    error_log('send_mail: SMTP settings missing in .env');
    reply(500, ['ok' => false, 'error' => 'The mail service is not configured.']);
}
$allowedOrigin = ak_env('ALLOWED_ORIGIN', '');
if ($allowedOrigin !== '') {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
    header('Vary: Origin');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST');
    header('Access-Control-Allow-Headers: Accept');
    reply(204, ['ok' => true]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reply(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

// --- Input (plain strings, trimmed and length-limited) ---
function field(string $key, int $max = 500): string
{
    $v = isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
    return mb_substr($v, 0, $max);
}

// Headers must never contain line breaks (prevents header injection).
function oneLine(string $v): string
{
    return trim(preg_replace('/[\r\n]+/', ' ', $v));
}

$type    = field('form_type', 20) === 'order' ? 'order' : 'contact';
$name    = oneLine(field('name', 120));
$company = oneLine(field('company', 160));
$email   = field('email', 200);
$phone   = oneLine(field('phone', 60));
$subject = oneLine(field('subject', 200));
$message = field('message', 10000);
$orderRef = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', field('order_ref', 40)));
$itemIds  = json_decode(field('items', 4000), true);
$itemIds  = is_array($itemIds) ? array_values(array_filter($itemIds, 'is_string')) : [];

if ($name === '' || $message === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    reply(422, ['ok' => false, 'error' => 'Please fill in your name, a valid email and your message.']);
}

$esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
$label = $type === 'order' ? 'New quote request from the web shop' : 'New message from the website';

$rows = [
    'Name'    => $name,
    'Company' => $company,
    'Email'   => $email,
    'Phone'   => $phone,
    'Subject' => $subject,
];

$html = '<h2 style="font-family:Arial,sans-serif;color:#3a2e1f">' . $esc($label) . '</h2><table style="font-family:Arial,sans-serif;font-size:14px">';
$text = $label . "\n\n";
foreach ($rows as $k => $v) {
    if ($v === '') continue;
    $html .= '<tr><td style="padding:4px 12px 4px 0;color:#646b78">' . $k . '</td><td><strong>' . $esc($v) . '</strong></td></tr>';
    $text .= "$k: $v\n";
}
$html .= '</table><pre style="font-family:Arial,sans-serif;font-size:14px;white-space:pre-wrap;background:#f7f5f1;padding:12px;border-radius:6px">' . $esc($message) . '</pre>';
$text .= "\n" . $message . "\n";

// --- Send ---
$mail = new PHPMailer(true);
try {
    $port = (int) (ak_env('SMTP_PORT', '465'));
    $mail->isSMTP();
    $mail->Host       = ak_env('SMTP_HOST', '');
    $mail->SMTPAuth   = true;
    $mail->Username   = ak_env('SMTP_USERNAME', '');
    $mail->Password   = ak_env('SMTP_PASSWORD', '');
    $secure = strtolower(ak_env('SMTP_SECURE', $port === 587 ? 'tls' : 'ssl'));
    if ($secure === 'none') {
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;          // local testing only
    } else {
        $mail->SMTPSecure = $secure === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
    }
    $mail->Port       = $port;
    $mail->CharSet    = 'UTF-8';

    // Send FROM your own mailbox (so it isn't rejected as spoofed); replies go to the visitor.
    $mail->setFrom(ak_env('SMTP_USERNAME', ''), 'Africa Kaiser Website');
    foreach (array_filter(array_map('trim', explode(',', (string) ak_env('ADMIN_EMAIL', ak_env('SMTP_USERNAME', ''))))) as $to) $mail->addAddress($to);
    $mail->addReplyTo($email, $name);

    $mail->isHTML(true);
    $mail->Subject = ($type === 'order' ? '[Quote] ' : '[Website] ') . ($subject !== '' ? $subject : $name);
    $mail->Body    = $html;
    $mail->AltBody = $text;

    $mail->send();

    // Remember the order so the customer can later leave a "Verified buyer" review.
    if ($type === 'order' && $orderRef !== '') {
        try {
            ak_db()->prepare("INSERT OR REPLACE INTO orders (ref, email, name, company, items, details, created_at) VALUES (?, ?, ?, ?, ?, ?, datetime('now'))")
                ->execute([$orderRef, mb_strtolower($email), $name, $company, json_encode($itemIds), $text]);
        } catch (Throwable $e) {
            error_log('send_mail: could not save order ' . $orderRef . ': ' . $e->getMessage());
        }
    }
    reply(200, ['ok' => true]);
} catch (MailException $e) {
    error_log('send_mail: ' . $mail->ErrorInfo);
    reply(502, ['ok' => false, 'error' => 'We could not send your message right now.']);
}
