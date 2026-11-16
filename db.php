<?php
/**
 * Shared helpers: settings from .env and the SQLite database used for orders and reviews.
 * No extra packages needed (PHP 8+ with pdo_sqlite, which most hosts have).
 */

declare(strict_types=1);

function ak_env(string $key, ?string $default = null): ?string
{
    static $vars = null;
    if ($vars === null) {
        $vars = [];
        $file = __DIR__ . '/.env';
        if (is_readable($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
                [$k, $v] = array_map('trim', explode('=', $line, 2));
                $v = preg_replace('/\s+#.*$/', '', $v);          // strip trailing comments
                $vars[$k] = trim($v, "\"'");
            }
        }
    }
    $val = $_ENV[$key] ?? $vars[$key] ?? getenv($key);
    return ($val === false || $val === null || $val === '') ? $default : (string) $val;
}

function ak_db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;

    // Default location: a "data" folder next to this script, blocked from the web by its own .htaccess.
    // Better: set DB_PATH in .env to a folder outside public_html.
    $path = ak_env('DB_PATH', __DIR__ . '/data/africakaiser.sqlite');
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    if (!file_exists($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    }

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('CREATE TABLE IF NOT EXISTS orders (
        ref TEXT PRIMARY KEY,
        email TEXT NOT NULL,
        name TEXT,
        company TEXT,
        items TEXT NOT NULL,
        details TEXT,
        created_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS reviews (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        target TEXT NOT NULL,
        rating INTEGER NOT NULL,
        name TEXT NOT NULL,
        company TEXT,
        title TEXT,
        body TEXT NOT NULL,
        email TEXT NOT NULL,
        order_ref TEXT,
        verified INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT \'pending\',
        reply TEXT,
        ip_hash TEXT,
        created_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_reviews_target ON reviews (target, status)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS categories (
        slug TEXT PRIMARY KEY,
        name TEXT NOT NULL,
        blurb TEXT,
        image TEXT,
        sort INTEGER NOT NULL DEFAULT 0
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS products (
        id TEXT PRIMARY KEY,
        name TEXT NOT NULL,
        category TEXT NOT NULL,
        price REAL,
        price_max REAL,
        unit TEXT NOT NULL DEFAULT \'pc\',
        price_term TEXT,
        moq TEXT,
        origin TEXT,
        description TEXT,
        specs TEXT NOT NULL DEFAULT \'[]\',
        images TEXT NOT NULL DEFAULT \'[]\',
        featured INTEGER NOT NULL DEFAULT 0,
        visible INTEGER NOT NULL DEFAULT 1,
        sort INTEGER NOT NULL DEFAULT 0,
        updated_at TEXT
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS login_attempts (ip_hash TEXT NOT NULL, at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS rfqs (
        ref TEXT PRIMARY KEY,
        name TEXT NOT NULL,
        company TEXT,
        email TEXT NOT NULL,
        phone TEXT,
        client_ref TEXT,
        project TEXT,
        sector TEXT,
        incoterm TEXT,
        location TEXT,
        needed_by TEXT,
        brands TEXT,
        details TEXT,
        files TEXT NOT NULL DEFAULT \'[]\',
        lang TEXT,
        status TEXT NOT NULL DEFAULT \'new\',
        notes TEXT,
        ip_hash TEXT,
        created_at TEXT NOT NULL
    )');
    // Portuguese fields (added later – safe to run on existing databases)
    $added = ak_add_columns($pdo, 'categories', ['name_pt' => 'TEXT', 'blurb_pt' => 'TEXT'])
           + ak_add_columns($pdo, 'products', ['name_pt' => 'TEXT', 'description_pt' => 'TEXT']);
    if ($added > 0) ak_backfill_pt($pdo);
    ak_seed_catalog($pdo);
    return $pdo;
}

function ak_json(int $code, array $body): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

function ak_field(string $key, int $max = 500): string
{
    $v = isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
    return mb_substr($v, 0, $max);
}

/** First run only: load the original catalogue (seed-catalog.json) into the database. */
function ak_seed_catalog(PDO $pdo): void
{
    if ((int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn() > 0) return;
    $file = __DIR__ . '/seed-catalog.json';
    if (!is_readable($file)) return;
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) return;

    $pdo->beginTransaction();
    $c = $pdo->prepare('INSERT OR IGNORE INTO categories (slug, name, blurb, image, sort, name_pt, blurb_pt) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($data['categories'] ?? [] as $cat) {
        $c->execute([$cat['slug'], $cat['name'], $cat['blurb'] ?? '', $cat['image'] ?? null, (int) ($cat['sort'] ?? 0), $cat['name_pt'] ?? null, $cat['blurb_pt'] ?? null]);
    }
    $p = $pdo->prepare('INSERT OR IGNORE INTO products (id, name, category, price, price_max, unit, price_term, moq, origin, description, specs, images, featured, visible, sort, updated_at, name_pt, description_pt)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, datetime(\'now\'), ?, ?)');
    foreach ($data['products'] ?? [] as $x) {
        $p->execute([
            $x['id'], $x['name'], $x['category'], $x['price'], $x['price_max'] ?? null, $x['unit'] ?? 'pc',
            $x['price_term'] ?? null, $x['moq'] ?? null, $x['origin'] ?? null, $x['description'] ?? '',
            json_encode($x['specs'] ?? [], JSON_UNESCAPED_UNICODE), json_encode($x['images'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            (int) ($x['featured'] ?? 0), (int) ($x['sort'] ?? 0), ($x['name_pt'] ?? '') ?: null, ($x['description_pt'] ?? '') ?: null,
        ]);
    }
    $pdo->commit();
}

/** Public product + category data, as used by the website. */
function ak_catalog(PDO $pdo, bool $includeHidden = false): array
{
    $cats = array_map(static fn(array $c): array => [
        'slug' => $c['slug'], 'name' => $c['name'], 'namePt' => $c['name_pt'] ?: null,
        'blurb' => $c['blurb'] ?: '', 'blurbPt' => $c['blurb_pt'] ?: null, 'image' => $c['image'] ?: null,
    ], $pdo->query('SELECT * FROM categories ORDER BY sort, name')->fetchAll());
    $sql = 'SELECT * FROM products' . ($includeHidden ? '' : ' WHERE visible = 1') . ' ORDER BY sort, name';
    $products = array_map(static function (array $r): array {
        return [
            'id' => $r['id'],
            'name' => $r['name'],
            'namePt' => $r['name_pt'] ?: null,
            'category' => $r['category'],
            'price' => $r['price'] === null ? null : (float) $r['price'],
            'priceMax' => $r['price_max'] === null ? null : (float) $r['price_max'],
            'unit' => $r['unit'],
            'priceTerm' => $r['price_term'] ?: null,
            'moq' => $r['moq'] ?: null,
            'origin' => $r['origin'] ?: 'China',
            'description' => $r['description'] ?: '',
            'descriptionPt' => $r['description_pt'] ?: null,
            'specs' => json_decode($r['specs'], true) ?: [],
            'images' => json_decode($r['images'], true) ?: [],
            'featured' => (int) $r['featured'],
            'visible' => (bool) $r['visible'],
        ];
    }, $pdo->query($sql)->fetchAll());
    return ['categories' => $cats, 'products' => $products];
}

/** Adds columns that older databases don't have yet. */
function ak_add_columns(PDO $pdo, string $table, array $columns): int
{
    $have = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
    $added = 0;
    foreach ($columns as $name => $type) {
        if (!in_array($name, $have, true)) {
            $pdo->exec("ALTER TABLE $table ADD COLUMN $name $type");
            $added++;
        }
    }
    return $added;
}

/** After upgrading an existing database: fill in Portuguese names for the original catalogue items. */
function ak_backfill_pt(PDO $pdo): void
{
    $data = json_decode((string) @file_get_contents(__DIR__ . '/seed-catalog.json'), true);
    if (!is_array($data)) return;
    $c = $pdo->prepare('UPDATE categories SET name_pt = COALESCE(name_pt, ?), blurb_pt = COALESCE(blurb_pt, ?) WHERE slug = ?');
    foreach ($data['categories'] ?? [] as $x) $c->execute([$x['name_pt'] ?? null, $x['blurb_pt'] ?? null, $x['slug']]);
    $p = $pdo->prepare('UPDATE products SET name_pt = COALESCE(name_pt, ?) WHERE id = ?');
    foreach ($data['products'] ?? [] as $x) if (!empty($x['name_pt'])) $p->execute([$x['name_pt'], $x['id']]);
}

/** Private folder for files customers upload with an RFQ (not reachable from the web). */
function ak_private_dir(string $sub): string
{
    $base = dirname(ak_env('DB_PATH', __DIR__ . '/data/africakaiser.sqlite'));
    $dir = $base . '/' . trim($sub, '/');
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    return $dir;
}
