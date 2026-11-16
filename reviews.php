<?php
/**
 * Africa Kaiser – reviews API.
 *
 * GET  reviews.php?target=<product id | company>  → approved reviews + rating summary
 * GET  reviews.php?summary=1                       → average rating per product (for product cards)
 * POST reviews.php  (target, rating, name, company, title, body, email, order_ref)
 *      → saves a review. If the order reference + email match a real order placed on the site
 *        (and the order contained that product), it is marked "Verified buyer".
 *
 * .env options:
 *   AUTO_APPROVE_VERIFIED=true   # verified-buyer reviews go live straight away (default true)
 *                                # all other reviews wait for approval in reviews-admin.php
 */

declare(strict_types=1);
require __DIR__ . '/db.php';

$origin = ak_env('ALLOWED_ORIGIN');
if ($origin) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}

const TARGET_RE = '/^[a-z0-9][a-z0-9-]{0,59}$/i';

try {
    $db = ak_db();
} catch (Throwable $e) {
    error_log('reviews: database error: ' . $e->getMessage());
    ak_json(500, ['ok' => false, 'error' => 'Reviews are temporarily unavailable.']);
}

$method = $_SERVER['REQUEST_METHOD'];

// ---------- Read ----------
if ($method === 'GET') {
    header('Cache-Control: no-cache, must-revalidate');

    if (isset($_GET['summary'])) {
        $rows = $db->query("SELECT target, ROUND(AVG(rating), 2) AS avg, COUNT(*) AS count
                            FROM reviews WHERE status = 'approved' GROUP BY target")->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[$r['target']] = ['avg' => (float) $r['avg'], 'count' => (int) $r['count']];
        }
        ak_json(200, ['ok' => true, 'targets' => (object) $out]);
    }

    $target = (string) ($_GET['target'] ?? '');
    if (!preg_match(TARGET_RE, $target)) {
        ak_json(400, ['ok' => false, 'error' => 'Unknown product.']);
    }

    $dist = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
    $st = $db->prepare("SELECT rating, COUNT(*) c FROM reviews WHERE target = ? AND status = 'approved' GROUP BY rating");
    $st->execute([$target]);
    $total = 0; $sum = 0;
    foreach ($st->fetchAll() as $r) {
        $dist[(int) $r['rating']] = (int) $r['c'];
        $total += (int) $r['c'];
        $sum += (int) $r['rating'] * (int) $r['c'];
    }

    $st = $db->prepare("SELECT id, name, company, rating, title, body, verified, reply, created_at
                        FROM reviews WHERE target = ? AND status = 'approved'
                        ORDER BY created_at DESC LIMIT 100");
    $st->execute([$target]);
    $reviews = array_map(static function ($r) {
        $r['id'] = (int) $r['id'];
        $r['rating'] = (int) $r['rating'];
        $r['verified'] = (bool) $r['verified'];
        return $r;
    }, $st->fetchAll());

    ak_json(200, [
        'ok' => true,
        'summary' => ['avg' => $total ? round($sum / $total, 2) : 0, 'count' => $total, 'dist' => $dist],
        'reviews' => $reviews,
    ]);
}

// ---------- Submit ----------
if ($method !== 'POST') {
    ak_json(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

if (ak_field('website') !== '') {               // hidden honeypot field – bots fill it in
    ak_json(200, ['ok' => true, 'status' => 'pending', 'verified' => false]);
}

$target   = ak_field('target', 60);
$rating   = (int) ak_field('rating', 1);
$name     = preg_replace('/[\r\n]+/', ' ', ak_field('name', 80));
$company  = preg_replace('/[\r\n]+/', ' ', ak_field('company', 120));
$title    = preg_replace('/[\r\n]+/', ' ', ak_field('title', 120));
$body     = ak_field('body', 3000);
$email    = mb_strtolower(ak_field('email', 200));
$orderRef = strtoupper(ak_field('order_ref', 40));

if (!preg_match(TARGET_RE, $target)) ak_json(422, ['ok' => false, 'error' => 'Unknown product.']);
if ($rating < 1 || $rating > 5)       ak_json(422, ['ok' => false, 'error' => 'Please choose a star rating.']);
if ($name === '' || mb_strlen($body) < 10) ak_json(422, ['ok' => false, 'error' => 'Please add your name and a review of at least 10 characters.']);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) ak_json(422, ['ok' => false, 'error' => 'Please enter a valid email (it is not shown publicly).']);

// Simple rate limit: 5 reviews per visitor per day.
$ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . ak_env('REVIEWS_SALT', 'ak'));
$st = $db->prepare("SELECT COUNT(*) FROM reviews WHERE ip_hash = ? AND created_at > datetime('now', '-1 day')");
$st->execute([$ipHash]);
if ((int) $st->fetchColumn() >= 5) {
    ak_json(429, ['ok' => false, 'error' => 'Too many reviews from this connection today. Please try again tomorrow.']);
}

// Verified buyer = a real order with this reference and email (that included this product).
$verified = false;
if ($orderRef !== '') {
    $st = $db->prepare('SELECT items FROM orders WHERE ref = ? AND lower(email) = ?');
    $st->execute([$orderRef, $email]);
    $order = $st->fetch();
    if ($order) {
        $items = json_decode($order['items'], true) ?: [];
        $verified = $target === 'company' || in_array($target, $items, true);
    }
}

$autoApprove = filter_var(ak_env('AUTO_APPROVE_VERIFIED', 'true'), FILTER_VALIDATE_BOOLEAN);
$status = ($verified && $autoApprove) ? 'approved' : 'pending';

$st = $db->prepare('INSERT INTO reviews (target, rating, name, company, title, body, email, order_ref, verified, status, ip_hash, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime(\'now\'))');
$st->execute([$target, $rating, $name, $company, $title, $body, $email, $orderRef ?: null, $verified ? 1 : 0, $status, $ipHash]);

ak_json(200, ['ok' => true, 'status' => $status, 'verified' => $verified]);
