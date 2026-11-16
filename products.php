<?php
/** Africa Kaiser – public catalogue API used by the website (read-only). */
declare(strict_types=1);
require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ak_json(405, ['ok' => false, 'error' => 'Method not allowed.']);
}
$origin = ak_env('ALLOWED_ORIGIN');
if ($origin) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Cache-Control: no-cache, must-revalidate');

try {
    ak_json(200, ['ok' => true] + ak_catalog(ak_db()));
} catch (Throwable $e) {
    error_log('products: ' . $e->getMessage());
    ak_json(500, ['ok' => false, 'error' => 'Catalogue unavailable.']);
}
