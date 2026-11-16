<?php
/**
 * Africa Kaiser – RFQ (request for quotation) with file upload.
 *
 * Accepts the RFQ form from the website with up to 10 files (Excel, PDF, Word, photos).
 * - Saves the RFQ and its files privately (data/rfq/<REF>/ – not reachable from the web)
 * - Emails you the RFQ with the files attached (if they fit in one email)
 * - Emails the buyer a confirmation with their reference, in Portuguese or English
 * - Shows the RFQ in admin.php → RFQs, with the files to download
 * Replies with JSON: {"ok": true, "ref": "RFQ-…"} or {"ok": false, "error": "…"}
 */

declare(strict_types=1);
require __DIR__ . '/mailer.php';

const RFQ_MAX_FILES = 10;
const RFQ_MAX_FILE_MB = 10;
const RFQ_MAX_TOTAL_MB = 25;
const RFQ_ATTACH_MAX_MB = 18;   // larger RFQs: files stay in the admin panel, the email links there

$origin = ak_env('ALLOWED_ORIGIN');
if ($origin) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ak_json(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

// Upload larger than PHP's post_max_size: PHP drops the whole request.
if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    ak_json(413, ['ok' => false, 'error' => 'Your files are too large to send. Please send fewer or smaller files.']);
}

if (ak_field('website') !== '') {                       // honeypot: bots fill it in
    ak_json(200, ['ok' => true, 'ref' => 'RFQ-' . strtoupper(bin2hex(random_bytes(3)))]);
}

$one = static fn(string $k, int $max): string => trim(preg_replace('/[\r\n]+/', ' ', ak_field($k, $max)));
$data = [
    'name'       => $one('name', 120),
    'company'    => $one('company', 160),
    'email'      => mb_strtolower($one('email', 200)),
    'phone'      => $one('phone', 60),
    'client_ref' => $one('client_ref', 80),
    'project'    => $one('project', 160),
    'sector'     => $one('sector', 60),
    'incoterm'   => $one('incoterm', 40),
    'location'   => $one('location', 160),
    'needed_by'  => preg_match('/^\d{4}-\d{2}-\d{2}$/', ak_field('needed_by', 10)) ? ak_field('needed_by', 10) : '',
    'brands'     => $one('brands', 300),
    'details'    => ak_field('details', 8000),
];
$lang = ak_field('lang', 2) === 'en' ? 'en' : 'pt';

if ($data['name'] === '' || $data['company'] === '' || $data['phone'] === '' || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
    ak_json(422, ['ok' => false, 'error' => 'Please fill in your name, company, a valid email and phone.']);
}

$db = ak_db();

// Rate limit: 10 RFQs per connection per day.
$ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|rfq');
$st = $db->prepare("SELECT COUNT(*) FROM rfqs WHERE ip_hash = ? AND created_at > datetime('now', '-1 day')");
$st->execute([$ipHash]);
if ((int) $st->fetchColumn() >= 10) {
    ak_json(429, ['ok' => false, 'error' => 'Too many requests today. Please email us your RFQ instead.']);
}

// ---------- Files ----------
$allowed = [
    'pdf'  => ['application/pdf'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
    'xls'  => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2', 'application/octet-stream'],
    'csv'  => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
    'doc'  => ['application/msword', 'application/x-ole-storage', 'application/CDFV2', 'application/octet-stream'],
    'jpg'  => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'],
    'heic' => ['image/heic', 'image/heif', 'application/octet-stream'], 'heif' => ['image/heif', 'image/heic', 'application/octet-stream'],
];
$incoming = [];
if (!empty($_FILES['files']) && is_array($_FILES['files']['name'])) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $total = 0;
    foreach ($_FILES['files']['name'] as $i => $orig) {
        $err = $_FILES['files']['error'][$i];
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            ak_json(413, ['ok' => false, 'error' => 'One of your files is too large. The limit is 10 MB per file.']);
        }
        if ($err !== UPLOAD_ERR_OK) ak_json(400, ['ok' => false, 'error' => 'A file could not be uploaded. Please try again.']);
        if (count($incoming) >= RFQ_MAX_FILES) ak_json(422, ['ok' => false, 'error' => 'Please send at most 10 files.']);

        $size = (int) $_FILES['files']['size'][$i];
        $total += $size;
        if ($size > RFQ_MAX_FILE_MB * 1048576) ak_json(413, ['ok' => false, 'error' => 'One of your files is too large. The limit is 10 MB per file.']);
        if ($total > RFQ_MAX_TOTAL_MB * 1048576) ak_json(413, ['ok' => false, 'error' => 'Your files are too large to send. Please send fewer or smaller files.']);

        $ext = strtolower(pathinfo((string) $orig, PATHINFO_EXTENSION));
        $mime = $finfo->file($_FILES['files']['tmp_name'][$i]) ?: 'application/octet-stream';
        if (!isset($allowed[$ext]) || !in_array($mime, $allowed[$ext], true)) {
            ak_json(422, ['ok' => false, 'error' => 'Only Excel, PDF, Word or photo files can be sent.']);
        }
        $safe = trim(preg_replace('/[^A-Za-z0-9._ -]+/', '_', pathinfo((string) $orig, PATHINFO_FILENAME)), ' ._') ?: 'file';
        $incoming[] = ['tmp' => $_FILES['files']['tmp_name'][$i], 'name' => mb_substr($safe, 0, 80) . '.' . $ext, 'size' => $size, 'mime' => $mime];
    }
}
if (!$incoming && mb_strlen(trim($data['details'])) < 10) {
    ak_json(422, ['ok' => false, 'error' => 'Please attach your RFQ file or describe the items you need.']);
}

// ---------- Save ----------
do {
    $ref = 'RFQ-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
    $st = $db->prepare('SELECT 1 FROM rfqs WHERE ref = ?');
    $st->execute([$ref]);
} while ($st->fetchColumn());

$dir = ak_private_dir('rfq/' . $ref);
$saved = [];
foreach ($incoming as $n => $f) {
    $stored = sprintf('%02d-%s', $n + 1, $f['name']);
    if (!move_uploaded_file($f['tmp'], $dir . '/' . $stored)) {
        ak_json(500, ['ok' => false, 'error' => 'We could not save your files. Please try again or email us.']);
    }
    chmod($dir . '/' . $stored, 0640);
    $saved[] = ['name' => $f['name'], 'stored' => $stored, 'size' => $f['size'], 'mime' => $f['mime']];
}

$db->prepare("INSERT INTO rfqs (ref, name, company, email, phone, client_ref, project, sector, incoterm, location, needed_by, brands, details, files, lang, ip_hash, created_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))")
   ->execute([$ref, $data['name'], $data['company'], $data['email'], $data['phone'], $data['client_ref'], $data['project'], $data['sector'],
              $data['incoterm'], $data['location'], $data['needed_by'], $data['brands'], $data['details'], json_encode($saved, JSON_UNESCAPED_UNICODE), $lang, $ipHash]);

// ---------- Emails (the RFQ is already saved, so a mail problem never loses it) ----------
$esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
if (ak_mail_configured()) {
    // 1) To you, with the files attached
    try {
        $labels = ['Company' => 'company', 'Name' => 'name', 'Email' => 'email', 'Phone / WhatsApp' => 'phone', 'Client RFQ no.' => 'client_ref',
                   'Project / site' => 'project', 'Sector' => 'sector', 'Delivery terms' => 'incoterm', 'Delivery location' => 'location',
                   'Required by' => 'needed_by', 'Preferred brands' => 'brands'];
        $rows = '';
        $text = "New RFQ $ref\n\n";
        foreach ($labels as $label => $key) {
            if ($data[$key] === '') continue;
            $rows .= '<tr><td style="padding:4px 14px 4px 0;color:#646b78">' . $label . '</td><td><strong>' . $esc($data[$key]) . '</strong></td></tr>';
            $text .= "$label: {$data[$key]}\n";
        }
        $totalSize = array_sum(array_column($saved, 'size'));
        $attach = $totalSize <= RFQ_ATTACH_MAX_MB * 1048576;
        $fileList = implode('', array_map(static fn($f) => '<li>' . $esc($f['name']) . ' (' . round($f['size'] / 1024) . ' KB)</li>', $saved));
        $adminUrl = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/admin.php?tab=rfq&ref=' . urlencode($ref);

        $mail = ak_mailer();
        foreach (ak_admin_emails() as $to) $mail->addAddress($to);
        $mail->addReplyTo($data['email'], $data['name']);
        $mail->Subject = "[RFQ] $ref: " . ($data['company'] ?: $data['name']) . ($data['client_ref'] ? " ({$data['client_ref']})" : '');
        $mail->isHTML(true);
        $mail->Body = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#1b1f27">'
            . '<h2 style="color:#3a2e1f;margin:0 0 12px">New RFQ ' . $esc($ref) . '</h2><table>' . $rows . '</table>'
            . ($data['details'] !== '' ? '<h3 style="color:#3a2e1f">Items / details</h3><pre style="white-space:pre-wrap;font-family:Arial,sans-serif;background:#f7f5f1;padding:12px;border-radius:6px">' . $esc($data['details']) . '</pre>' : '')
            . ($saved ? '<h3 style="color:#3a2e1f">Files (' . count($saved) . ')</h3><ul>' . $fileList . '</ul>'
                . ($attach ? '<p>Attached to this email.</p>' : '<p><strong>Too large to attach.</strong> Download them in the admin panel.</p>') : '')
            . '<p><a href="' . $esc($adminUrl) . '" style="color:#96784b">Open in admin panel</a> · Buyer language: ' . strtoupper($lang) . '</p></div>';
        $mail->AltBody = $text . ($data['details'] !== '' ? "\nItems / details:\n{$data['details']}\n" : '') . "\nAdmin: $adminUrl\n";
        if ($attach) {
            foreach ($saved as $f) $mail->addAttachment($dir . '/' . $f['stored'], $f['name']);
        }
        $mail->send();
    } catch (Throwable $e) {
        error_log("rfq: admin email for $ref failed: " . $e->getMessage());
    }

    // 2) Confirmation to the buyer
    try {
        $first = explode(' ', $data['name'])[0];
        if ($lang === 'pt') {
            $subject = "Recebemos o seu pedido de cotação $ref";
            $html = "<p>Caro(a) " . $esc($first) . ",</p>"
                . "<p>Obrigado pelo seu pedido de cotação. A sua referência é <strong>" . $esc($ref) . "</strong>"
                . ($data['client_ref'] ? " (a sua referência: " . $esc($data['client_ref']) . ")" : '') . ".</p>"
                . "<p>A nossa equipa irá analisar cada item com os fabricantes e enviar-lhe uma cotação firme, com preço, prazo de entrega e entrega DDP ou CIF Luanda, normalmente em 48 horas.</p>"
                . ($saved ? "<p>Ficheiros recebidos: " . count($saved) . ".</p>" : '')
                . "<p>Se tiver alguma questão, responda a este email ou contacte-nos por WhatsApp: +44 7393 915652.</p>"
                . "<p>Com os melhores cumprimentos,<br><strong>Africa Kaiser</strong><br>Fornecimento Industrial e Procurement</p>";
        } else {
            $subject = "We received your RFQ $ref";
            $html = "<p>Dear " . $esc($first) . ",</p>"
                . "<p>Thank you for your request for quotation. Your reference is <strong>" . $esc($ref) . "</strong>"
                . ($data['client_ref'] ? " (your reference: " . $esc($data['client_ref']) . ")" : '') . ".</p>"
                . "<p>Our team will check each line with manufacturers and send you a firm quote with price, lead time and delivery DDP or CIF Luanda, usually within 48 hours.</p>"
                . ($saved ? "<p>Files received: " . count($saved) . ".</p>" : '')
                . "<p>If you have any questions, reply to this email or WhatsApp us on +44 7393 915652.</p>"
                . "<p>Kind regards,<br><strong>Africa Kaiser</strong><br>Industrial Supply &amp; Procurement</p>";
        }
        $mail = ak_mailer();
        $mail->addAddress($data['email'], $data['name']);
        $mail->addReplyTo(ak_admin_email(), 'Africa Kaiser');
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#1b1f27;line-height:1.5">' . $html . '</div>';
        $mail->AltBody = trim(html_entity_decode(strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $html)), ENT_QUOTES, 'UTF-8'));
        $mail->send();
    } catch (Throwable $e) {
        error_log("rfq: confirmation email for $ref failed: " . $e->getMessage());
    }
}

ak_json(200, ['ok' => true, 'ref' => $ref]);
