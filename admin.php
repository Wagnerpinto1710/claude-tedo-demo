<?php
/**
 * Africa Kaiser – admin panel (products, categories, reviews, orders).
 *
 * Open https://www.africakaiser.co.uk/admin.php and log in with ADMIN_PASSWORD from .env.
 * .env options:
 *   ADMIN_PASSWORD=a-long-password-only-you-know        (at least 12 characters)
 *   ADMIN_ALLOWED_IPS=1.2.3.4,5.6.7.8                   (optional: only these IPs can open the panel)
 */

declare(strict_types=1);
require __DIR__ . '/db.php';

const UPLOAD_DIR = __DIR__ . '/uploads/products';
const UPLOAD_URL = '/uploads/products/';
const MAX_UPLOAD_MB = 8;

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");

// ---------- Access control ----------
$allowedIps = array_filter(array_map('trim', explode(',', (string) ak_env('ADMIN_ALLOWED_IPS', ''))));
if ($allowedIps && !in_array($_SERVER['REMOTE_ADDR'] ?? '', $allowedIps, true)) {
    http_response_code(404);
    exit('Not found');
}

$password = (string) ak_env('ADMIN_PASSWORD', '');
$passwordHash = (string) ak_env('ADMIN_PASSWORD_HASH', '');
if ($passwordHash === '' && strlen($password) < 12) {
    http_response_code(503);
    exit('Admin panel is locked: set ADMIN_PASSWORD (12+ characters) in the .env file.');
}

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('ak_admin');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
session_start();

$db = ak_db();
$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|admin');

function flash(string $msg, string $type = 'ok'): void { $_SESSION['flash'] = [$type, $msg]; }
function go(string $qs = ''): void { header('Location: admin.php' . ($qs ? '?' . $qs : '')); exit; }
function post(string $k, int $max = 5000): string { return isset($_POST[$k]) && is_string($_POST[$k]) ? trim(mb_substr($_POST[$k], 0, $max)) : ''; }

// Idle timeout: 2 hours.
if (!empty($_SESSION['admin']) && time() - ($_SESSION['seen'] ?? 0) > 7200) {
    $_SESSION = [];
    session_regenerate_id(true);
}

// Login / logout
if (isset($_POST['login'])) {
    $db->exec("DELETE FROM login_attempts WHERE at < datetime('now', '-1 day')");
    $st = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_hash = ? AND at > datetime('now', '-15 minutes')");
    $st->execute([$ipHash]);
    if ((int) $st->fetchColumn() >= 5) {
        flash('Too many attempts. Try again in 15 minutes.', 'err');
        go();
    }
    $given = (string) ($_POST['password'] ?? '');
    $ok = $passwordHash !== '' ? password_verify($given, $passwordHash) : hash_equals($password, $given);
    if ($ok) {
        session_regenerate_id(true);
        $_SESSION = ['admin' => true, 'csrf' => bin2hex(random_bytes(32)), 'seen' => time()];
        $db->prepare('DELETE FROM login_attempts WHERE ip_hash = ?')->execute([$ipHash]);
        go();
    }
    $db->prepare("INSERT INTO login_attempts (ip_hash, at) VALUES (?, datetime('now'))")->execute([$ipHash]);
    sleep(1);
    flash('Wrong password.', 'err');
    go();
}
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    go();
}

$loggedIn = !empty($_SESSION['admin']);
if ($loggedIn) $_SESSION['seen'] = time();

// All changes require a valid CSRF token.
if ($loggedIn && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        flash('Your session expired. Please try again.', 'err');
        go();
    }
}

// ---------- RFQ file download (logged-in only) ----------
if ($loggedIn && isset($_GET['download'], $_GET['f'])) {
    $st = $db->prepare('SELECT files FROM rfqs WHERE ref = ?');
    $st->execute([(string) $_GET['download']]);
    $files = json_decode((string) $st->fetchColumn(), true) ?: [];
    $file = $files[(int) $_GET['f']] ?? null;
    $path = $file ? ak_private_dir('rfq/' . basename((string) $_GET['download'])) . '/' . basename($file['stored']) : '';
    if (!$file || !is_file($path)) { http_response_code(404); exit('File not found'); }
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $file['name']) . '"');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

// ---------- Helpers ----------
function slugify(string $s): string
{
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    $s = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $s));
    return trim(substr($s, 0, 50), '-') ?: 'item';
}

function unique_id(PDO $db, string $table, string $col, string $base): string
{
    $id = $base; $n = 2;
    $st = $db->prepare("SELECT 1 FROM $table WHERE $col = ?");
    while (true) {
        $st->execute([$id]);
        if (!$st->fetchColumn()) return $id;
        $id = $base . '-' . $n++;
    }
}

function money(?string $v): ?float
{
    $v = str_replace([',', '$', ' '], ['', '', ''], (string) $v);
    return $v === '' || !is_numeric($v) ? null : round((float) $v, 2);
}

/** Saves uploaded images; returns their public paths. Resizes large photos when GD is available. */
function save_uploads(string $field, array &$errors): array
{
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]['name'])) return [];
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
    $guard = UPLOAD_DIR . '/.htaccess';
    if (!file_exists($guard)) {
        file_put_contents($guard, "Options -Indexes -ExecCGI\nRemoveHandler .php .phtml .phar .pl .py .cgi\nRemoveType .php .phtml .phar\n<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|sh)$\">\n  Require all denied\n</FilesMatch>\n");
    }
    $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $saved = [];
    foreach ($_FILES[$field]['name'] as $i => $origName) {
        $err = $_FILES[$field]['error'][$i];
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        if ($err !== UPLOAD_ERR_OK) { $errors[] = "$origName: upload failed (too large?)."; continue; }
        $tmp = $_FILES[$field]['tmp_name'][$i];
        if ($_FILES[$field]['size'][$i] > MAX_UPLOAD_MB * 1024 * 1024) { $errors[] = "$origName is over " . MAX_UPLOAD_MB . ' MB.'; continue; }
        $mime = $finfo->file($tmp);
        if (!isset($types[$mime]) || !@getimagesize($tmp)) { $errors[] = "$origName is not a JPG, PNG, WebP or GIF image."; continue; }
        $name = date('Ymd') . '-' . bin2hex(random_bytes(6)) . '.' . $types[$mime];
        $dest = UPLOAD_DIR . '/' . $name;
        if (!resize_image($tmp, $dest, $mime) && !move_uploaded_file($tmp, $dest)) { $errors[] = "$origName could not be saved."; continue; }
        chmod($dest, 0644);
        $saved[] = UPLOAD_URL . $name;
    }
    return $saved;
}

function resize_image(string $src, string $dest, string $mime, int $max = 1600): bool
{
    if (!function_exists('imagecreatefromstring') || $mime === 'image/gif') return false;
    [$w, $h] = getimagesize($src);
    if ($w <= $max && $h <= $max) return false;
    $img = @imagecreatefromstring((string) file_get_contents($src));
    if (!$img) return false;
    $scale = $max / max($w, $h);
    $out = imagescale($img, (int) round($w * $scale), (int) round($h * $scale), IMG_BICUBIC);
    if ($mime === 'image/png' || $mime === 'image/webp') { imagealphablending($out, false); imagesavealpha($out, true); }
    $ok = match ($mime) {
        'image/png' => imagepng($out, $dest, 6),
        'image/webp' => imagewebp($out, $dest, 85),
        default => imagejpeg($out, $dest, 85),
    };
    imagedestroy($img); imagedestroy($out);
    return $ok;
}

function delete_upload(PDO $db, string $path): void
{
    if (!str_starts_with($path, UPLOAD_URL)) return;              // never touch the original site images
    $st = $db->prepare('SELECT COUNT(*) FROM products WHERE images LIKE ?');
    $st->execute(['%' . json_encode($path, JSON_UNESCAPED_SLASHES) . '%']);
    if ((int) $st->fetchColumn() > 0) return;                     // still used by another product
    $file = UPLOAD_DIR . '/' . basename($path);
    if (is_file($file)) unlink($file);
}

// ---------- Actions ----------
$tab = $_GET['tab'] ?? 'rfqs';

if ($loggedIn && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_product') {
        $errors = [];
        $id = post('id', 80);
        $existing = null;
        if ($id !== '') {
            $st = $db->prepare('SELECT * FROM products WHERE id = ?');
            $st->execute([$id]);
            $existing = $st->fetch() ?: null;
        }
        $name = post('name', 160);
        $category = post('category', 60);
        if ($name === '') $errors[] = 'Product name is required.';
        $st = $db->prepare('SELECT 1 FROM categories WHERE slug = ?');
        $st->execute([$category]);
        if (!$st->fetchColumn()) $errors[] = 'Choose a category.';

        // Specifications (label/value rows)
        $specs = [];
        foreach ((array) ($_POST['spec_label'] ?? []) as $i => $label) {
            $label = trim(mb_substr((string) $label, 0, 80));
            $value = trim(mb_substr((string) ($_POST['spec_value'][$i] ?? ''), 0, 500));
            if ($label !== '' && $value !== '') $specs[] = ['label' => $label, 'value' => $value];
        }

        // Images: keep existing ones not ticked for removal, add new uploads, put the chosen main image first.
        $images = $existing ? (json_decode($existing['images'], true) ?: []) : [];
        $remove = array_map('strval', (array) ($_POST['remove_image'] ?? []));
        $removed = array_values(array_intersect($images, $remove));
        $images = array_values(array_diff($images, $remove));
        $images = array_merge($images, save_uploads('new_images', $errors));
        $main = post('main_image', 300);
        if ($main !== '' && in_array($main, $images, true)) {
            $images = array_values(array_unique(array_merge([$main], $images)));
        }

        if ($errors && $name === '') {
            flash(implode(' ', $errors), 'err');
            go($existing ? 'tab=edit&id=' . urlencode($id) : 'tab=edit');
        }

        $priceOnRequest = isset($_POST['price_on_request']);
        $row = [
            $name, $category,
            $priceOnRequest ? null : money(post('price', 20)),
            $priceOnRequest ? null : money(post('price_max', 20)),
            post('unit', 20) ?: 'pc', post('price_term', 60) ?: null, post('moq', 80) ?: null,
            post('origin', 60) ?: null, post('description', 5000),
            post('name_pt', 160) ?: null, post('description_pt', 5000) ?: null,
            json_encode($specs, JSON_UNESCAPED_UNICODE), json_encode($images, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            isset($_POST['featured']) ? max(1, (int) post('featured_order', 3)) : 0,
            isset($_POST['visible']) ? 1 : 0,
            (int) post('sort', 6),
        ];
        $sortIdx = count($row) - 1;
        if ($existing) {
            $db->prepare('UPDATE products SET name=?, category=?, price=?, price_max=?, unit=?, price_term=?, moq=?, origin=?, description=?, name_pt=?, description_pt=?, specs=?, images=?, featured=?, visible=?, sort=?, updated_at=datetime(\'now\') WHERE id=?')
               ->execute([...$row, $id]);
        } else {
            $id = unique_id($db, 'products', 'id', slugify($name));
            if ($row[$sortIdx] === 0) $row[$sortIdx] = (int) $db->query('SELECT COALESCE(MAX(sort), 0) + 1 FROM products')->fetchColumn();
            $db->prepare('INSERT INTO products (name, category, price, price_max, unit, price_term, moq, origin, description, name_pt, description_pt, specs, images, featured, visible, sort, updated_at, id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,datetime(\'now\'),?)')
               ->execute([...$row, $id]);
        }
        foreach ($removed as $path) delete_upload($db, $path);
        flash(($existing ? 'Saved' : 'Added') . " “{$name}”." . ($errors ? ' Note: ' . implode(' ', $errors) : ''), $errors ? 'warn' : 'ok');
        go('tab=edit&id=' . urlencode($id));
    }

    if ($action === 'toggle_product') {
        $field = $_POST['field'] === 'featured' ? 'featured' : 'visible';
        $db->prepare("UPDATE products SET $field = CASE WHEN $field > 0 THEN 0 ELSE 1 END, updated_at = datetime('now') WHERE id = ?")->execute([post('id', 80)]);
        go('tab=products&' . http_build_query(['q' => $_POST['q'] ?? '', 'cat' => $_POST['cat'] ?? '']));
    }

    if ($action === 'delete_product') {
        $st = $db->prepare('SELECT name, images FROM products WHERE id = ?');
        $st->execute([post('id', 80)]);
        if ($p = $st->fetch()) {
            $db->prepare('DELETE FROM products WHERE id = ?')->execute([post('id', 80)]);
            foreach (json_decode($p['images'], true) ?: [] as $path) delete_upload($db, $path);
            flash("Deleted “{$p['name']}”.");
        }
        go('tab=products');
    }

    if ($action === 'save_category') {
        $old = post('old_slug', 60);
        $name = post('name', 80);
        if ($name === '') { flash('Category name is required.', 'err'); go('tab=categories'); }
        $errors = [];
        $img = save_uploads('image', $errors)[0] ?? null;
        if ($old) {
            $sql = 'UPDATE categories SET name = ?, blurb = ?, sort = ?, name_pt = ?, blurb_pt = ?' . ($img ? ', image = ?' : '') . ' WHERE slug = ?';
            $args = [$name, post('blurb', 200), (int) post('sort', 6), post('name_pt', 80) ?: null, post('blurb_pt', 200) ?: null];
            if ($img) $args[] = $img;
            $args[] = $old;
            $db->prepare($sql)->execute($args);
        } else {
            $slug = unique_id($db, 'categories', 'slug', slugify($name));
            $db->prepare('INSERT INTO categories (slug, name, blurb, image, sort, name_pt, blurb_pt) VALUES (?, ?, ?, ?, ?, ?, ?)')
               ->execute([$slug, $name, post('blurb', 200), $img, (int) post('sort', 6) ?: 99, post('name_pt', 80) ?: null, post('blurb_pt', 200) ?: null]);
        }
        flash("Saved category “{$name}”." . ($errors ? ' ' . implode(' ', $errors) : ''), $errors ? 'warn' : 'ok');
        go('tab=categories');
    }

    if ($action === 'delete_category') {
        $slug = post('slug', 60);
        $st = $db->prepare('SELECT COUNT(*) FROM products WHERE category = ?');
        $st->execute([$slug]);
        if ((int) $st->fetchColumn() > 0) {
            flash('Move or delete the products in this category first.', 'err');
        } else {
            $db->prepare('DELETE FROM categories WHERE slug = ?')->execute([$slug]);
            flash('Category deleted.');
        }
        go('tab=categories');
    }

    if ($action === 'rfq_status') {
        $statuses = ['new', 'quoting', 'quoted', 'won', 'lost'];
        $status = in_array($_POST['status'] ?? '', $statuses, true) ? $_POST['status'] : 'new';
        $db->prepare('UPDATE rfqs SET status = ?, notes = ? WHERE ref = ?')->execute([$status, post('notes', 3000), post('ref', 40)]);
        flash('RFQ updated.');
        go('tab=rfq&ref=' . urlencode(post('ref', 40)));
    }

    if ($action === 'review') {
        $id = (int) ($_POST['id'] ?? 0);
        switch ($_POST['do'] ?? '') {
            case 'approve': $db->prepare("UPDATE reviews SET status = 'approved' WHERE id = ?")->execute([$id]); flash('Review published.'); break;
            case 'hide':    $db->prepare("UPDATE reviews SET status = 'hidden' WHERE id = ?")->execute([$id]); flash('Review hidden.'); break;
            case 'delete':  $db->prepare('DELETE FROM reviews WHERE id = ?')->execute([$id]); flash('Review deleted.'); break;
            case 'reply':
                $reply = post('reply', 1500);
                $db->prepare('UPDATE reviews SET reply = ? WHERE id = ?')->execute([$reply ?: null, $id]);
                flash('Reply saved.');
                break;
        }
        go('tab=reviews&status=' . urlencode($_POST['status'] ?? 'pending'));
    }
}

// ---------- Views ----------
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$csrf = $_SESSION['csrf'] ?? '';
$token = '<input type="hidden" name="csrf" value="' . $e($csrf) . '">';
$categories = $loggedIn ? $db->query('SELECT * FROM categories ORDER BY sort, name')->fetchAll() : [];
$catNames = array_column($categories, 'name', 'slug');
$newRfqs = $loggedIn ? (int) $db->query("SELECT COUNT(*) FROM rfqs WHERE status = 'new'")->fetchColumn() : 0;
$pendingReviews = $loggedIn ? (int) $db->query("SELECT COUNT(*) FROM reviews WHERE status = 'pending'")->fetchColumn() : 0;
$img = static fn(string $p): string => htmlspecialchars(str_replace(' ', '%20', $p), ENT_QUOTES, 'UTF-8');
$price = static function (array $p): string {
    if ($p['price'] === null) return '<span class="muted">On request</span>';
    $s = '$' . number_format((float) $p['price'], 2);
    if ($p['price_max'] !== null) $s .= ' to $' . number_format((float) $p['price_max'], 2);
    return $s . ' <span class="muted">/ ' . htmlspecialchars($p['unit']) . '</span>';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Admin · Africa Kaiser</title>
<link rel="icon" href="/favicon.png">
<style>
  :root { --navy:#3a2e1f; --gold:#b5976a; --gold-d:#96784b; --cream:#f4ede1; --bg:#f7f5f1; --line:#e6e1d8; --muted:#646b78; --ok:#1f7a4d; --err:#b42318; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: Inter, system-ui, -apple-system, 'Segoe UI', Arial, sans-serif; background: var(--bg); color: #1b1f27; font-size: 15px; }
  a { color: inherit; }
  header.top { background: var(--gold); color: #2b2114; }
  .wrap { max-width: 1180px; margin: 0 auto; padding: 0 20px; }
  .top .wrap { display: flex; align-items: center; gap: 24px; height: 60px; }
  .brand { display: flex; align-items: center; gap: 10px; font-weight: 700; letter-spacing: .05em; text-decoration: none; }
  .brand img { width: 34px; height: 34px; background: #fff; border-radius: 50%; padding: 3px; }
  .brand small { color: #3d3020; font-weight: 600; letter-spacing: .1em; font-size: 11px; }
  nav.tabs { display: flex; gap: 4px; flex: 1; overflow-x: auto; }
  nav.tabs a { padding: 8px 14px; border-radius: 8px; text-decoration: none; color: #2b2114; white-space: nowrap; font-weight: 500; }
  nav.tabs a.on, nav.tabs a:hover { background: rgba(43,33,20,.12); color: #2b2114; }
  .pill { background: var(--navy); color: #fff; border-radius: 99px; padding: 1px 7px; font-size: 12px; margin-left: 4px; }
  .top-right { display: flex; gap: 16px; align-items: center; font-size: 14px; white-space: nowrap; }
  .top-right a { color: #2b2114; }
  main { padding: 26px 0 60px; }
  h1 { font-size: 24px; margin: 0; color: var(--navy); }
  .bar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 18px; flex-wrap: wrap; }
  .card { background: #fff; border: 1px solid var(--line); border-radius: 12px; }
  .pad { padding: 20px; }
  .btn { display: inline-flex; align-items: center; gap: 6px; border: 1.5px solid var(--gold); background: var(--gold); color: #fff; padding: 9px 16px; border-radius: 8px; font-weight: 600; cursor: pointer; text-decoration: none; font-size: 14px; font-family: inherit; }
  .btn:hover { background: var(--gold-d); border-color: var(--gold-d); }
  .btn.ghost { background: #fff; color: var(--navy); border-color: var(--line); }
  .btn.ghost:hover { border-color: var(--navy); }
  .btn.danger { background: #fff; color: var(--err); border-color: #f2c4bf; }
  .btn.danger:hover { background: #fdecea; }
  .btn.sm { padding: 5px 10px; font-size: 13px; }
  .flash { padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-weight: 500; }
  .flash.ok { background: #e6f4ec; color: var(--ok); } .flash.err { background: #fdecea; color: var(--err); } .flash.warn { background: #fff4e0; color: #8a5a00; }
  .filters { display: flex; gap: 8px; flex-wrap: wrap; }
  input[type=text], input[type=number], input[type=password], input[type=search], select, textarea {
    border: 1.5px solid var(--line); border-radius: 8px; padding: 9px 11px; font: inherit; background: #fcfbf9; width: 100%; }
  input:focus, select:focus, textarea:focus { outline: 0; border-color: var(--gold); background: #fff; }
  .filters input, .filters select { width: auto; min-width: 200px; }
  table { width: 100%; border-collapse: collapse; }
  th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid var(--line); vertical-align: middle; font-size: 14px; }
  th { font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); background: #fbfaf7; font-weight: 600; }
  tr:last-child td { border-bottom: 0; }
  tr.hidden-row td { opacity: .55; }
  .thumb { width: 52px; height: 52px; border-radius: 8px; border: 1px solid var(--line); object-fit: contain; background: #fff; display: block; }
  .noimg { width: 52px; height: 52px; border-radius: 8px; background: var(--cream); display: grid; place-items: center; color: var(--gold-d); font-size: 11px; }
  .pname { font-weight: 600; text-decoration: none; color: var(--navy); }
  .pname:hover { text-decoration: underline; }
  .muted { color: var(--muted); font-size: 13px; }
  .tag { display: inline-block; font-size: 12px; padding: 2px 8px; border-radius: 99px; background: var(--cream); color: #5a4a2e; }
  .toggle { border: 0; background: none; cursor: pointer; font-size: 13px; font-weight: 600; padding: 4px 8px; border-radius: 6px; font-family: inherit; }
  .toggle.on { color: var(--ok); background: #e6f4ec; } .toggle.off { color: var(--muted); background: #f0eee9; }
  .star.on { color: #b07d00; background: #fff4d6; }
  .actions { display: flex; gap: 6px; justify-content: flex-end; }
  form.inline { display: inline; }
  .grid { display: grid; grid-template-columns: 1.4fr 1fr; gap: 20px; align-items: start; }
  .fields { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
  .fields .full { grid-column: 1 / -1; }
  label.f { display: flex; flex-direction: column; gap: 6px; font-weight: 600; font-size: 13px; color: #3b4150; }
  label.f .hint, .hint { font-weight: 400; color: var(--muted); font-size: 12px; }
  label.check { display: flex; align-items: center; gap: 8px; font-size: 14px; cursor: pointer; }
  label.check input { width: 17px; height: 17px; accent-color: var(--gold); }
  h2 { font-size: 16px; color: var(--navy); margin: 0 0 14px; }
  .section + .section { margin-top: 18px; }
  .spec-row { display: grid; grid-template-columns: 1fr 1.6fr auto; gap: 8px; margin-bottom: 8px; }
  .imgs { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 10px; }
  .imgbox { border: 1.5px solid var(--line); border-radius: 10px; padding: 8px; background: #fff; font-size: 12px; }
  .imgbox img { width: 100%; aspect-ratio: 1; object-fit: contain; display: block; margin-bottom: 6px; }
  .imgbox label { display: flex; gap: 5px; align-items: center; margin-top: 3px; cursor: pointer; }
  .imgbox.main { border-color: var(--gold); }
  .drop { border: 2px dashed var(--line); border-radius: 10px; padding: 18px; text-align: center; color: var(--muted); margin-top: 10px; display: block; cursor: pointer; }
  .drop:hover { border-color: var(--gold); }
  .drop input { margin-top: 8px; }
  #previews { display: grid; grid-template-columns: repeat(auto-fill, minmax(80px, 1fr)); gap: 8px; margin-top: 10px; }
  #previews img { width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 8px; border: 1px solid var(--line); }
  .sticky { position: sticky; bottom: 0; background: rgba(247,245,241,.95); padding: 14px 0; margin-top: 18px; display: flex; gap: 10px; border-top: 1px solid var(--line); }
  .login { max-width: 380px; margin: 10vh auto; }
  .login h1 { margin-bottom: 4px; }
  .login form { display: flex; flex-direction: column; gap: 12px; margin-top: 16px; }
  .review { padding: 16px 20px; border-bottom: 1px solid var(--line); }
  .stars { color: #e0a526; letter-spacing: 2px; }
  .badge { background: #e6f4ec; color: var(--ok); font-size: 12px; padding: 2px 8px; border-radius: 99px; font-weight: 600; }
  details summary { cursor: pointer; color: var(--navy); font-weight: 600; }
  pre.order { white-space: pre-wrap; font-family: inherit; font-size: 13px; background: var(--bg); padding: 12px; border-radius: 8px; margin: 10px 0 0; }
  .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 18px; }
  .stat { padding: 14px 16px; } .stat b { display: block; font-size: 22px; color: var(--navy); }
  .st { display: inline-block; font-size: 12px; font-weight: 700; padding: 3px 9px; border-radius: 99px; vertical-align: middle; }
  .st-new { background: #fff4d6; color: #8a5a00; } .st-quoting { background: #e8effc; color: #2a55b8; } .st-quoted { background: var(--cream); color: #5a4a2e; }
  .st-won { background: #e6f4ec; color: var(--ok); } .st-lost { background: #f0eee9; color: var(--muted); }
  table.kv th { width: 38%; background: none; text-transform: none; letter-spacing: 0; font-size: 13px; padding-left: 0; }
  table.kv td { padding-right: 0; }
  ul.files { list-style: none; padding: 0; margin: 0; display: grid; gap: 8px; }
  ul.files a { font-weight: 600; color: var(--navy); }
  @media (max-width: 860px) {
    .grid, .fields { grid-template-columns: 1fr; } .stats { grid-template-columns: 1fr 1fr; }
    .top .wrap { flex-wrap: wrap; height: auto; padding-top: 10px; padding-bottom: 10px; }
    .hide-sm { display: none; } .filters input, .filters select { min-width: 0; flex: 1; }
  }
</style>
</head>
<body>
<?php if (!$loggedIn): ?>
  <div class="wrap">
    <div class="card pad login">
      <div class="brand" style="color:var(--navy)"><img src="/ak-icon.png" alt="">AFRICA KAISER</div>
      <h1 style="margin-top:18px">Admin login</h1>
      <p class="muted">Private area. Authorised access only.</p>
      <?php if ($flash): ?><div class="flash <?= $e($flash[0]) ?>"><?= $e($flash[1]) ?></div><?php endif; ?>
      <form method="post" autocomplete="off">
        <input type="password" name="password" placeholder="Password" required autofocus>
        <button class="btn" name="login" value="1" style="justify-content:center">Log in</button>
      </form>
    </div>
  </div>
<?php else: ?>
<header class="top">
  <div class="wrap">
    <a class="brand" href="admin.php"><img src="/ak-icon.png" alt=""><span>AFRICA KAISER<br><small>ADMIN</small></span></a>
    <nav class="tabs">
      <?php foreach (['rfqs' => 'RFQs', 'products' => 'Products', 'categories' => 'Categories', 'reviews' => 'Reviews', 'orders' => 'Cart orders'] as $k => $label): ?>
        <a href="?tab=<?= $k ?>" class="<?= ($tab === $k || ($tab === 'edit' && $k === 'products') || ($tab === 'rfq' && $k === 'rfqs')) ? 'on' : '' ?>"><?= $label ?><?= $k === 'reviews' && $pendingReviews ? '<span class="pill">' . $pendingReviews . '</span>' : '' ?><?= $k === 'rfqs' && $newRfqs ? '<span class="pill">' . $newRfqs . '</span>' : '' ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="top-right"><a href="/" target="_blank" rel="noopener">View site ↗</a><a href="?logout=1">Log out</a></div>
  </div>
</header>
<main class="wrap">
<?php if ($flash): ?><div class="flash <?= $e($flash[0]) ?>"><?= $e($flash[1]) ?></div><?php endif; ?>

<?php /* ================= RFQ LIST ================= */ if ($tab === 'rfqs'):
    $status = (string) ($_GET['status'] ?? '');
    $sql = 'SELECT * FROM rfqs' . ($status !== '' ? ' WHERE status = ?' : '') . ' ORDER BY created_at DESC LIMIT 500';
    $st = $db->prepare($sql);
    $st->execute($status !== '' ? [$status] : []);
    $rows = $st->fetchAll();
    $counts = array_column($db->query('SELECT status, COUNT(*) c FROM rfqs GROUP BY status')->fetchAll(), 'c', 'status');
?>
  <div class="stats">
    <div class="card stat"><b><?= array_sum($counts) ?></b><span class="muted">RFQs received</span></div>
    <div class="card stat"><b><?= (int) ($counts['new'] ?? 0) ?></b><span class="muted">New, not started</span></div>
    <div class="card stat"><b><?= (int) ($counts['quoted'] ?? 0) ?></b><span class="muted">Quoted</span></div>
    <div class="card stat"><b><?= (int) ($counts['won'] ?? 0) ?></b><span class="muted">Won</span></div>
  </div>
  <div class="bar">
    <h1>Requests for quotation</h1>
    <div class="filters">
      <?php foreach (['' => 'All', 'new' => 'New', 'quoting' => 'Quoting', 'quoted' => 'Quoted', 'won' => 'Won', 'lost' => 'Lost'] as $k => $label): ?>
        <a class="btn <?= $status === $k ? '' : 'ghost' ?> sm" href="?tab=rfqs<?= $k !== '' ? '&status=' . $k : '' ?>"><?= $label ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="card">
    <table>
      <thead><tr><th>Reference</th><th>Company</th><th class="hide-sm">Sector</th><th class="hide-sm">Files</th><th class="hide-sm">Required by</th><th>Status</th><th class="hide-sm">Received (UTC)</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="7" class="muted" style="padding:24px">No RFQs yet. They appear here as soon as a buyer sends one from the website.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><a class="pname" href="?tab=rfq&ref=<?= urlencode($r['ref']) ?>"><?= $e($r['ref']) ?></a><?= $r['client_ref'] ? '<br><span class="muted">' . $e($r['client_ref']) . '</span>' : '' ?></td>
          <td><?= $e($r['company']) ?><br><span class="muted"><?= $e($r['name']) ?></span></td>
          <td class="hide-sm"><?= $e($r['sector'] ?: '') ?></td>
          <td class="hide-sm"><?= count(json_decode($r['files'], true) ?: []) ?></td>
          <td class="hide-sm"><?= $e($r['needed_by'] ?: '') ?></td>
          <td><span class="st st-<?= $e($r['status']) ?>"><?= $e(ucfirst($r['status'])) ?></span></td>
          <td class="hide-sm"><?= $e($r['created_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php /* ================= RFQ DETAIL ================= */ elseif ($tab === 'rfq'):
    $st = $db->prepare('SELECT * FROM rfqs WHERE ref = ?');
    $st->execute([(string) ($_GET['ref'] ?? '')]);
    $r = $st->fetch();
?>
  <?php if (!$r): ?><p>RFQ not found. <a href="?tab=rfqs">Back to RFQs</a></p><?php else: $files = json_decode($r['files'], true) ?: []; ?>
  <div class="bar">
    <div><a href="?tab=rfqs" class="muted">← All RFQs</a><h1><?= $e($r['ref']) ?> <span class="st st-<?= $e($r['status']) ?>"><?= $e(ucfirst($r['status'])) ?></span></h1></div>
    <a class="btn" href="mailto:<?= $e($r['email']) ?>?subject=<?= rawurlencode(($r['lang'] === 'en' ? 'Quotation ' : 'Cotação ') . $r['ref'] . ($r['client_ref'] ? ' / ' . $r['client_ref'] : '')) ?>">Reply by email</a>
  </div>
  <div class="grid">
    <div>
      <div class="card pad section">
        <h2>Request</h2>
        <table class="kv">
          <?php foreach (['Client RFQ no.' => 'client_ref', 'Project / site' => 'project', 'Sector' => 'sector', 'Delivery terms' => 'incoterm', 'Delivery location' => 'location', 'Required by' => 'needed_by', 'Preferred brands' => 'brands'] as $label => $k): if (($r[$k] ?? '') === '') continue; ?>
            <tr><th><?= $label ?></th><td><?= $e($r[$k]) ?></td></tr>
          <?php endforeach; ?>
        </table>
        <?php if ($r['details']): ?><h2 style="margin-top:18px">Items / details</h2><pre class="order"><?= $e($r['details']) ?></pre><?php endif; ?>
      </div>
      <div class="card pad section">
        <h2>Files (<?= count($files) ?>)</h2>
        <?php if (!$files): ?><p class="muted">No files attached.</p><?php endif; ?>
        <ul class="files">
          <?php foreach ($files as $i => $f): ?>
            <li><a href="?download=<?= urlencode($r['ref']) ?>&f=<?= $i ?>">⬇ <?= $e($f['name']) ?></a> <span class="muted"><?= number_format($f['size'] / 1024) ?> KB</span></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
    <div>
      <div class="card pad section">
        <h2>Buyer</h2>
        <table class="kv">
          <tr><th>Company</th><td><?= $e($r['company']) ?></td></tr>
          <tr><th>Name</th><td><?= $e($r['name']) ?></td></tr>
          <tr><th>Email</th><td><a href="mailto:<?= $e($r['email']) ?>"><?= $e($r['email']) ?></a></td></tr>
          <tr><th>Phone</th><td><?= $e($r['phone']) ?><?php $wa = preg_replace('/\D+/', '', (string) $r['phone']); if ($wa): ?> · <a href="https://wa.me/<?= $e($wa) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?></td></tr>
          <tr><th>Language</th><td><?= $r['lang'] === 'en' ? 'English' : 'Portuguese' ?></td></tr>
          <tr><th>Received</th><td><?= $e($r['created_at']) ?> UTC</td></tr>
        </table>
      </div>
      <div class="card pad section">
        <h2>Progress</h2>
        <form method="post" style="display:grid;gap:12px">
          <?= $token ?><input type="hidden" name="action" value="rfq_status"><input type="hidden" name="ref" value="<?= $e($r['ref']) ?>">
          <label class="f">Status
            <select name="status">
              <?php foreach (['new' => 'New: not started', 'quoting' => 'Quoting: asking suppliers', 'quoted' => 'Quoted: sent to buyer', 'won' => 'Won: order received', 'lost' => 'Lost'] as $k => $label): ?>
                <option value="<?= $k ?>" <?= $r['status'] === $k ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select></label>
          <label class="f">Internal notes <span class="hint">only you see these</span><textarea name="notes" rows="5"><?= $e($r['notes']) ?></textarea></label>
          <div><button class="btn">Save</button></div>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

<?php /* ================= PRODUCTS LIST ================= */ elseif ($tab === 'products'):
    $q = trim((string) ($_GET['q'] ?? ''));
    $cat = (string) ($_GET['cat'] ?? '');
    $sql = 'SELECT * FROM products WHERE 1';
    $args = [];
    if ($q !== '') { $sql .= ' AND (name LIKE ? OR id LIKE ? OR specs LIKE ?)'; $args = array_merge($args, ["%$q%", "%$q%", "%$q%"]); }
    if ($cat !== '') { $sql .= ' AND category = ?'; $args[] = $cat; }
    $st = $db->prepare($sql . ' ORDER BY sort, name');
    $st->execute($args);
    $rows = $st->fetchAll();
    $totals = $db->query('SELECT COUNT(*) total, SUM(visible) live, SUM(featured > 0) featured, SUM(price IS NULL) onreq FROM products')->fetch();
?>
  <div class="stats">
    <div class="card stat"><b><?= (int) $totals['total'] ?></b><span class="muted">Products</span></div>
    <div class="card stat"><b><?= (int) $totals['live'] ?></b><span class="muted">Visible on site</span></div>
    <div class="card stat"><b><?= (int) $totals['featured'] ?></b><span class="muted">Featured on home</span></div>
    <div class="card stat"><b><?= (int) $totals['onreq'] ?></b><span class="muted">Price on request</span></div>
  </div>
  <div class="bar">
    <form class="filters" method="get">
      <input type="hidden" name="tab" value="products">
      <input type="search" name="q" value="<?= $e($q) ?>" placeholder="Search products…">
      <select name="cat" onchange="this.form.submit()">
        <option value="">All categories</option>
        <?php foreach ($categories as $c): ?><option value="<?= $e($c['slug']) ?>" <?= $cat === $c['slug'] ? 'selected' : '' ?>><?= $e($c['name']) ?></option><?php endforeach; ?>
      </select>
      <button class="btn ghost">Filter</button>
    </form>
    <a class="btn" href="?tab=edit">+ Add product</a>
  </div>
  <div class="card">
    <table>
      <thead><tr><th style="width:70px"></th><th>Product</th><th class="hide-sm">Category</th><th>Price</th><th class="hide-sm">On site</th><th class="hide-sm">Featured</th><th></th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="7" class="muted" style="padding:24px">No products found.</td></tr><?php endif; ?>
      <?php foreach ($rows as $p): $imgs = json_decode($p['images'], true) ?: []; ?>
        <tr class="<?= $p['visible'] ? '' : 'hidden-row' ?>">
          <td><?php if ($imgs): ?><img class="thumb" src="<?= $img($imgs[0]) ?>" alt=""><?php else: ?><div class="noimg">No photo</div><?php endif; ?></td>
          <td><a class="pname" href="?tab=edit&id=<?= urlencode($p['id']) ?>"><?= $e($p['name']) ?></a><br><span class="muted"><?= $e($p['moq'] ? 'MOQ ' . $p['moq'] : $p['id']) ?></span></td>
          <td class="hide-sm"><span class="tag"><?= $e($catNames[$p['category']] ?? $p['category']) ?></span></td>
          <td><?= $price($p) ?></td>
          <td class="hide-sm">
            <form class="inline" method="post"><?= $token ?><input type="hidden" name="action" value="toggle_product"><input type="hidden" name="field" value="visible"><input type="hidden" name="id" value="<?= $e($p['id']) ?>"><input type="hidden" name="q" value="<?= $e($q) ?>"><input type="hidden" name="cat" value="<?= $e($cat) ?>">
              <button class="toggle <?= $p['visible'] ? 'on' : 'off' ?>" title="Show or hide on the website"><?= $p['visible'] ? '● Visible' : '○ Hidden' ?></button></form>
          </td>
          <td class="hide-sm">
            <form class="inline" method="post"><?= $token ?><input type="hidden" name="action" value="toggle_product"><input type="hidden" name="field" value="featured"><input type="hidden" name="id" value="<?= $e($p['id']) ?>"><input type="hidden" name="q" value="<?= $e($q) ?>"><input type="hidden" name="cat" value="<?= $e($cat) ?>">
              <button class="toggle star <?= $p['featured'] ? 'on' : 'off' ?>" title="Show in Featured products on the home page"><?= $p['featured'] ? '★ Featured' : '☆' ?></button></form>
          </td>
          <td><div class="actions"><a class="btn ghost sm" href="?tab=edit&id=<?= urlencode($p['id']) ?>">Edit</a></div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php /* ================= PRODUCT EDIT ================= */ elseif ($tab === 'edit'):
    $p = null;
    if (!empty($_GET['id'])) {
        $st = $db->prepare('SELECT * FROM products WHERE id = ?');
        $st->execute([$_GET['id']]);
        $p = $st->fetch() ?: null;
    }
    $specs = $p ? (json_decode($p['specs'], true) ?: []) : [];
    $imgs = $p ? (json_decode($p['images'], true) ?: []) : [];
    $v = static fn(string $k, $d = '') => $p[$k] ?? $d;
    $onRequest = $p && $p['price'] === null;
?>
  <div class="bar">
    <div><a href="?tab=products" class="muted">← All products</a><h1><?= $p ? 'Edit product' : 'Add product' ?></h1></div>
    <?php if ($p): ?><a class="btn ghost" href="/product/<?= urlencode($p['id']) ?>" target="_blank" rel="noopener">View on site ↗</a><?php endif; ?>
  </div>
  <form method="post" enctype="multipart/form-data" id="productForm">
    <?= $token ?><input type="hidden" name="action" value="save_product"><input type="hidden" name="id" value="<?= $e($v('id')) ?>">
    <div class="grid">
      <div>
        <div class="card pad section">
          <h2>Details</h2>
          <div class="fields">
            <label class="f full">Product name*<input type="text" name="name" required maxlength="160" value="<?= $e($v('name')) ?>" placeholder="e.g. 4&quot; SCH80 PVC Pipe"></label>
            <label class="f">Category*
              <select name="category" required>
                <?php foreach ($categories as $c): ?><option value="<?= $e($c['slug']) ?>" <?= $v('category') === $c['slug'] ? 'selected' : '' ?>><?= $e($c['name']) ?></option><?php endforeach; ?>
              </select></label>
            <label class="f">Country of origin<input type="text" name="origin" maxlength="60" value="<?= $e($v('origin', 'China')) ?>"></label>
            <label class="f full">Description <span class="hint">Shown on the product page</span><textarea name="description" rows="4" maxlength="5000"><?= $e($v('description')) ?></textarea></label>
            <label class="f full">Name in Portuguese <span class="hint">Shown when the site is in PT. Leave empty to use the English name.</span><input type="text" name="name_pt" maxlength="160" value="<?= $e($v('name_pt')) ?>" placeholder="ex. Capacete de segurança EN397, Amarelo"></label>
            <label class="f full">Description in Portuguese<textarea name="description_pt" rows="4" maxlength="5000"><?= $e($v('description_pt')) ?></textarea></label>
          </div>
        </div>
        <div class="card pad section">
          <h2>Price</h2>
          <label class="check" style="margin-bottom:12px"><input type="checkbox" name="price_on_request" id="onreq" <?= $onRequest ? 'checked' : '' ?>> Price on request (customers add it to a quote)</label>
          <div class="fields" id="priceFields">
            <label class="f">Price (USD)<input type="text" inputmode="decimal" name="price" value="<?= ($p && $p['price'] !== null) ? $e(number_format((float) $p['price'], 2, '.', '')) : '' ?>" placeholder="48.00"></label>
            <label class="f">Max price <span class="hint">optional, for a range</span><input type="text" inputmode="decimal" name="price_max" value="<?= ($p && $p['price_max'] !== null) ? $e(number_format((float) $p['price_max'], 2, '.', '')) : '' ?>"></label>
            <label class="f">Per (unit)<input type="text" name="unit" maxlength="20" value="<?= $e($v('unit', 'pc')) ?>" list="units"></label>
            <label class="f">Price term<input type="text" name="price_term" maxlength="60" value="<?= $e($v('price_term')) ?>" placeholder="FOB / EXW / CIF Luanda" list="terms"></label>
            <label class="f full">Minimum order (MOQ)<input type="text" name="moq" maxlength="80" value="<?= $e($v('moq')) ?>" placeholder="e.g. 106 pcs"></label>
          </div>
          <datalist id="units"><option>pc</option><option>ton</option><option>m</option><option>kg</option><option>box</option><option>set</option><option>roll</option><option>pair</option></datalist>
          <datalist id="terms"><option>FOB</option><option>FOB Dongguan</option><option>EXW</option><option>CIF Luanda</option><option>DDP Luanda</option></datalist>
        </div>
        <div class="card pad section">
          <h2>Specifications</h2>
          <div id="specs">
            <?php foreach ($specs ?: [['label' => '', 'value' => '']] as $s): ?>
              <div class="spec-row"><input type="text" name="spec_label[]" value="<?= $e($s['label']) ?>" placeholder="e.g. Size"><input type="text" name="spec_value[]" value="<?= $e($s['value']) ?>" placeholder="e.g. 60.32 × 5.54 mm"><button type="button" class="btn ghost sm" onclick="this.parentNode.remove()" title="Remove row">✕</button></div>
            <?php endforeach; ?>
          </div>
          <button type="button" class="btn ghost sm" onclick="addSpec()">+ Add row</button>
        </div>
      </div>

      <div>
        <div class="card pad section">
          <h2>Photos</h2>
          <?php if ($imgs): ?>
            <div class="imgs">
              <?php foreach ($imgs as $i => $src): ?>
                <div class="imgbox <?= $i === 0 ? 'main' : '' ?>">
                  <img src="<?= $img($src) ?>" alt="">
                  <label><input type="radio" name="main_image" value="<?= $e($src) ?>" <?= $i === 0 ? 'checked' : '' ?>> Main photo</label>
                  <label><input type="checkbox" name="remove_image[]" value="<?= $e($src) ?>"> Remove</label>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?><p class="muted">No photos yet.</p><?php endif; ?>
          <label class="drop">
            <strong>Upload photos</strong><br><span class="hint">JPG, PNG or WebP · up to <?= MAX_UPLOAD_MB ?> MB each · you can pick several</span><br>
            <input type="file" name="new_images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple id="fileInput">
          </label>
          <div id="previews"></div>
        </div>
        <div class="card pad section">
          <h2>Visibility</h2>
          <label class="check"><input type="checkbox" name="visible" <?= !$p || $p['visible'] ? 'checked' : '' ?>> Show on the website</label>
          <label class="check" style="margin-top:10px"><input type="checkbox" name="featured" <?= $p && $p['featured'] ? 'checked' : '' ?>> Featured on the home page</label>
          <div class="fields" style="margin-top:12px">
            <label class="f">Featured position<input type="number" name="featured_order" min="1" max="99" value="<?= $e($p && $p['featured'] ? $p['featured'] : 1) ?>"></label>
            <label class="f">Sort order <span class="hint">lower = first</span><input type="number" name="sort" min="0" value="<?= $e($v('sort', 0)) ?>"></label>
          </div>
        </div>
      </div>
    </div>
    <div class="sticky">
      <button class="btn"><?= $p ? 'Save changes' : 'Add product' ?></button>
      <a class="btn ghost" href="?tab=products">Cancel</a>
    </div>
  </form>
  <?php if ($p): ?>
    <form method="post" onsubmit="return confirm('Delete “<?= $e(addslashes($p['name'])) ?>” permanently?')" style="margin-top:6px">
      <?= $token ?><input type="hidden" name="action" value="delete_product"><input type="hidden" name="id" value="<?= $e($p['id']) ?>">
      <button class="btn danger sm">Delete this product</button>
    </form>
  <?php endif; ?>
  <script>
    function addSpec() {
      const row = document.createElement('div');
      row.className = 'spec-row';
      row.innerHTML = '<input type="text" name="spec_label[]" placeholder="e.g. Weight"><input type="text" name="spec_value[]" placeholder="e.g. 7.93 kg/pc"><button type="button" class="btn ghost sm" title="Remove row">✕</button>';
      row.querySelector('button').onclick = () => row.remove();
      document.getElementById('specs').appendChild(row);
      row.querySelector('input').focus();
    }
    const onreq = document.getElementById('onreq'), pf = document.getElementById('priceFields');
    const syncPrice = () => pf.querySelectorAll('input[name=price], input[name=price_max]').forEach(i => { i.disabled = onreq.checked; });
    onreq.addEventListener('change', syncPrice); syncPrice();
    document.getElementById('fileInput').addEventListener('change', (ev) => {
      const box = document.getElementById('previews'); box.innerHTML = '';
      [...ev.target.files].forEach(f => { const im = document.createElement('img'); im.src = URL.createObjectURL(f); im.title = f.name; box.appendChild(im); });
    });
    document.querySelectorAll('.imgbox input[type=radio]').forEach(r => r.addEventListener('change', () => {
      document.querySelectorAll('.imgbox').forEach(b => b.classList.toggle('main', b.contains(r) && r.checked));
    }));
  </script>

<?php /* ================= CATEGORIES ================= */ elseif ($tab === 'categories'):
    $counts = array_column($db->query('SELECT category, COUNT(*) n FROM products GROUP BY category')->fetchAll(), 'n', 'category');
?>
  <div class="bar"><h1>Categories</h1></div>
  <div class="grid">
    <div class="card">
      <table>
        <thead><tr><th style="width:70px"></th><th>Category</th><th>Products</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($categories as $c): ?>
          <tr>
            <td><?php if ($c['image']): ?><img class="thumb" src="<?= $img($c['image']) ?>" alt=""><?php else: ?><div class="noimg"></div><?php endif; ?></td>
            <td>
              <details>
                <summary><?= $e($c['name']) ?></summary>
                <form method="post" enctype="multipart/form-data" style="margin-top:12px;display:grid;gap:10px">
                  <?= $token ?><input type="hidden" name="action" value="save_category"><input type="hidden" name="old_slug" value="<?= $e($c['slug']) ?>">
                  <label class="f">Name<input type="text" name="name" value="<?= $e($c['name']) ?>" required></label>
                  <label class="f">Short description<input type="text" name="blurb" value="<?= $e($c['blurb']) ?>"></label>
                  <label class="f">Name in Portuguese<input type="text" name="name_pt" value="<?= $e($c['name_pt'] ?? '') ?>"></label>
                  <label class="f">Short description in Portuguese<input type="text" name="blurb_pt" value="<?= $e($c['blurb_pt'] ?? '') ?>"></label>
                  <label class="f">Order<input type="number" name="sort" value="<?= (int) $c['sort'] ?>"></label>
                  <label class="f">New picture <span class="hint">optional</span><input type="file" name="image[]" accept="image/*"></label>
                  <div><button class="btn sm">Save</button></div>
                </form>
              </details>
              <span class="muted"><?= $e($c['blurb']) ?></span>
            </td>
            <td><?= (int) ($counts[$c['slug']] ?? 0) ?></td>
            <td>
              <?php if (empty($counts[$c['slug']])): ?>
                <form method="post" onsubmit="return confirm('Delete this category?')"><?= $token ?><input type="hidden" name="action" value="delete_category"><input type="hidden" name="slug" value="<?= $e($c['slug']) ?>"><button class="btn danger sm">Delete</button></form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card pad">
      <h2>Add a category</h2>
      <form method="post" enctype="multipart/form-data" style="display:grid;gap:12px">
        <?= $token ?><input type="hidden" name="action" value="save_category">
        <label class="f">Name*<input type="text" name="name" required placeholder="e.g. PPE & Safety"></label>
        <label class="f">Short description<input type="text" name="blurb" placeholder="e.g. Helmets, gloves, boots and coveralls"></label>
        <label class="f">Name in Portuguese<input type="text" name="name_pt" placeholder="ex. EPI e Segurança"></label>
        <label class="f">Short description in Portuguese<input type="text" name="blurb_pt" placeholder="ex. Capacetes, luvas, botas e fatos-macaco"></label>
        <label class="f">Order<input type="number" name="sort" value="<?= count($categories) + 1 ?>"></label>
        <label class="f">Picture<input type="file" name="image[]" accept="image/*"></label>
        <div><button class="btn">Add category</button></div>
      </form>
    </div>
  </div>

<?php /* ================= REVIEWS ================= */ elseif ($tab === 'reviews'):
    $status = in_array($_GET['status'] ?? '', ['pending', 'approved', 'hidden'], true) ? $_GET['status'] : 'pending';
    $counts = array_column($db->query('SELECT status, COUNT(*) c FROM reviews GROUP BY status')->fetchAll(), 'c', 'status');
    $st = $db->prepare('SELECT r.*, p.name AS product FROM reviews r LEFT JOIN products p ON p.id = r.target WHERE r.status = ? ORDER BY r.created_at DESC LIMIT 300');
    $st->execute([$status]);
    $rows = $st->fetchAll();
?>
  <div class="bar">
    <h1>Reviews</h1>
    <div class="filters">
      <?php foreach (['pending' => 'Waiting for approval', 'approved' => 'Published', 'hidden' => 'Hidden'] as $k => $label): ?>
        <a class="btn <?= $status === $k ? '' : 'ghost' ?> sm" href="?tab=reviews&status=<?= $k ?>"><?= $label ?> (<?= (int) ($counts[$k] ?? 0) ?>)</a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="card">
    <?php if (!$rows): ?><p class="muted pad">Nothing here.</p><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <div class="review">
        <span class="stars"><?= str_repeat('★', (int) $r['rating']) . '<span style="color:#ddd5c6">' . str_repeat('★', 5 - (int) $r['rating']) . '</span>' ?></span>
        <strong><?= $e($r['title'] ?: '') ?></strong>
        <?php if ($r['verified']): ?><span class="badge">✓ Verified buyer</span><?php endif; ?>
        <p style="margin:8px 0"><?= nl2br($e($r['body'])) ?></p>
        <div class="muted">
          <?= $e($r['name']) ?><?= $r['company'] ? ', ' . $e($r['company']) : '' ?> · <?= $e($r['email']) ?> ·
          <?= $r['target'] === 'company' ? 'Service review' : 'Product: ' . $e($r['product'] ?: $r['target']) ?>
          <?= $r['order_ref'] ? ' · Order ' . $e($r['order_ref']) : '' ?> · <?= $e($r['created_at']) ?> UTC
        </div>
        <form method="post" style="margin-top:10px">
          <?= $token ?><input type="hidden" name="action" value="review"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="status" value="<?= $e($status) ?>">
          <textarea name="reply" rows="2" placeholder="Public reply from Africa Kaiser (optional)"><?= $e($r['reply']) ?></textarea>
          <div class="actions" style="justify-content:flex-start;margin-top:8px">
            <?php if ($status !== 'approved'): ?><button class="btn sm" name="do" value="approve">Publish</button><?php endif; ?>
            <button class="btn ghost sm" name="do" value="reply">Save reply</button>
            <?php if ($status !== 'hidden'): ?><button class="btn ghost sm" name="do" value="hide">Hide</button><?php endif; ?>
            <button class="btn danger sm" name="do" value="delete" onclick="return confirm('Delete this review permanently?')">Delete</button>
          </div>
        </form>
      </div>
    <?php endforeach; ?>
  </div>

<?php /* ================= ORDERS ================= */ elseif ($tab === 'orders'):
    $rows = $db->query('SELECT * FROM orders ORDER BY created_at DESC LIMIT 300')->fetchAll();
?>
  <div class="bar"><h1>Quote requests</h1><span class="muted">From the website checkout. Full details are also emailed to you.</span></div>
  <div class="card">
    <table>
      <thead><tr><th>Reference</th><th>Customer</th><th class="hide-sm">Items</th><th>Date (UTC)</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="4" class="muted" style="padding:24px">No quote requests yet.</td></tr><?php endif; ?>
      <?php foreach ($rows as $o): ?>
        <tr>
          <td><details><summary><?= $e($o['ref']) ?></summary><?php if ($o['details']): ?><pre class="order"><?= $e($o['details']) ?></pre><?php endif; ?></details></td>
          <td><?= $e($o['name']) ?><?= $o['company'] ? '<br><span class="muted">' . $e($o['company']) . '</span>' : '' ?><br><a href="mailto:<?= $e($o['email']) ?>" class="muted"><?= $e($o['email']) ?></a></td>
          <td class="hide-sm"><?= count(json_decode($o['items'], true) ?: []) ?></td>
          <td><?= $e($o['created_at']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
</main>
<?php endif; ?>
</body>
</html>
