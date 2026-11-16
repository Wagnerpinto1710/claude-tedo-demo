# Africa Kaiser — Industrial Supply & Procurement Website

A bilingual (Portuguese/English) marketing site and quotation workflow for **Africa Kaiser**, an industrial supply and procurement company serving Angola.

The repository contains a production build of the React storefront plus a small PHP backend. It is designed to be uploaded to ordinary shared hosting: there is no Node.js build step or database server to run. The backend stores catalogue data, reviews, quote requests and RFQs in a self-creating SQLite database, and sends email through SMTP with PHPMailer.

> **Stack:** prebuilt React SPA · PHP 8+ · SQLite (PDO) · PHPMailer

---

## What the site does

- **Product catalogue** — categories, products, specs, multiple images, featured items and price-on-request support. Prices can be a single value or a range.
- **Cart → quote checkout** — visitors build a list of items and submit it as a quote request. No payment is taken online.
- **Contact form** — sends a message to the admin inbox and replies directly to the visitor.
- **RFQ portal** — buyers upload an Excel/PDF/Word/photo material list (up to 10 files, 10 MB each). The RFQ is saved privately, emailed to the team, and shown in the admin panel with downloadable files.
- **Customer reviews** — star ratings and comments for products or the company. Reviews can be moderated; reviews matched to a real order reference + email automatically get a **Verified buyer** badge.
- **Admin panel** — manage RFQs, products, categories, reviews and quote requests without touching the database.
- **Email notifications** — new RFQs, contact messages and quote requests are emailed via SMTP. Buyers receive an RFQ confirmation in their chosen language.
- **Bilingual UI** — the frontend ships with Portuguese and English copy; the RFQ confirmation email is also localised.

---

## Repository layout

```text
.
├── .env.example             # Safe configuration template (copy to .env)
├── .gitignore               # Excludes secrets and runtime data
├── index.html               # React SPA entry point (prebuilt)
├── static/                  # Compiled JS/CSS bundles
├── products/                # Original catalogue images
├── clients/                 # Client logos
├── banner*.jpg              # Hero/banner images
├── manifest.json            # Web app manifest
├── favicon.*, logo*.png     # Icons
│
├── db.php                   # Shared helpers: .env loader, SQLite, schema, catalogue, seeding
├── products.php             # GET  public catalogue API
├── reviews.php              # GET/POST reviews API
├── send_mail.php            # POST contact form and cart quote requests
├── rfq.php                  # POST RFQ form with file upload
├── mailer.php               # Shared PHPMailer/SMTP setup
├── admin.php                # Admin panel (login, products, categories, RFQs, reviews, orders)
├── reviews-admin.php        # Legacy URL; redirects to admin.php?tab=reviews
├── seed-catalog.json        # Initial catalogue loaded into an empty database
│
├── vendor/                  # Bundled PHPMailer + autoloader
└── data/                    # Created at runtime (SQLite DB and private RFQ files)
    └── rfq/<REF>/           # Uploaded RFQ attachments
```

`uploads/products/` is also created at runtime for images uploaded through the admin panel.

---

## Requirements

- **PHP 8.0 or newer** (the code uses `str_contains`, `str_starts_with`, typed signatures and strict types)
- PHP extensions:
  - `pdo_sqlite` — required
  - `mbstring` — required
  - `fileinfo` — required for upload type checks
  - `openssl` — required for SMTP over TLS/SSL
  - `gd` — optional; used to resize large admin image uploads. Without it, originals are saved unchanged.
- A web server (Apache, Nginx, LiteSpeed, etc.)
- Write permission for the PHP process on the project directory (to create `data/` and `uploads/`)
- Composer is **not** required: PHPMailer is vendored with a minimal autoloader in `vendor/autoload.php`

---

## Quick start (local)

1. **Create the configuration file** in the project root:

   ```bash
   cp .env.example .env
   ```

   Then edit `.env` with your own values. The minimum needed to log in and browse locally is:

   ```dotenv
   SMTP_HOST=smtp.example.com
   SMTP_PORT=465
   SMTP_USERNAME=website@example.com
   SMTP_PASSWORD=change-me
   ADMIN_EMAIL=you@example.com

   ADMIN_PASSWORD=a-long-password-of-at-least-12-characters
   ```

   Email settings are optional for looking around; the catalogue, admin panel and stored RFQs work without them. Email sending will simply be skipped or return an error until SMTP is configured.

2. **Start a local PHP server** from the project root:

   ```bash
   php -S 127.0.0.1:8000
   ```

3. Open:
   - Website: <http://127.0.0.1:8000/>
   - Admin: <http://127.0.0.1:8000/admin.php>

The SQLite database is created automatically on first request and seeded with `seed-catalog.json`.

> **Local-server warning:** PHP's built-in server does not read `.htaccess` files. For local development that is fine, but make sure `data/` is not publicly reachable in production. Better still, point `DB_PATH` outside the web root.

### Resetting the demo data

Delete the database file and reload the site:

```bash
rm -f data/africakaiser.sqlite data/africakaiser.sqlite-wal data/africakaiser.sqlite-shm
```

The catalogue is re-seeded only when the `categories` table is empty. To reset only the catalogue, delete every category and product in the admin panel or drop those tables.

---

## Configuration (`.env`)

The project reads a plain `.env` file next to `db.php`. Start from the safe template in `.env.example`. The format is intentionally simple: `KEY=value`, optional single/double quotes, `#` comments, and no variable interpolation.

**Never commit `.env`.** It contains the admin password and SMTP credentials. The included `.gitignore` already excludes `.env`, `.env.*`, the SQLite database, and runtime `data/` / `uploads/` directories. Do not force-add them.

| Variable | Required | Default | Purpose |
| --- | --- | --- | --- |
| `SMTP_HOST` | For email | — | SMTP server hostname |
| `SMTP_PORT` | No | `465` | SMTP port (`465` = implicit SSL, `587` = STARTTLS) |
| `SMTP_USERNAME` | For email | — | SMTP login and the default `From` address |
| `SMTP_PASSWORD` | For email | — | SMTP password |
| `SMTP_SECURE` | No | `ssl`, or `tls` when port is `587` | `ssl`, `tls`, or `none` (plain SMTP, local testing only) |
| `ADMIN_EMAIL` | No | `SMTP_USERNAME` | Recipient for website enquiries. Comma-separate multiple addresses. |
| `ALLOWED_ORIGIN` | No | — | Sets `Access-Control-Allow-Origin` for the PHP APIs. Use only if the frontend is hosted on a different origin. |
| `ADMIN_PASSWORD` | Yes* | — | Admin panel password; must be at least 12 characters |
| `ADMIN_PASSWORD_HASH` | Alternative to `ADMIN_PASSWORD` | — | `password_hash()` output; used instead of the plain password when set |
| `ADMIN_ALLOWED_IPS` | No | — | Comma-separated IP allow-list for `admin.php`. If set, other IPs receive a 404. |
| `DB_PATH` | No | `data/africakaiser.sqlite` | SQLite file path. RFQ attachments are stored in a private `rfq/` folder beside it. |
| `AUTO_APPROVE_VERIFIED` | No | `true` | Publish verified-buyer reviews immediately without moderation |
| `REVIEWS_SALT` | No | `ak` | Salt used to hash reviewer IPs. Set a private random value in production. |

\* The admin panel stays locked (HTTP 503) until `ADMIN_PASSWORD` is 12+ characters or `ADMIN_PASSWORD_HASH` is set.

Example with all important options:

```dotenv
SMTP_HOST=smtp.yourprovider.com
SMTP_PORT=465
SMTP_USERNAME=website@example.com
SMTP_PASSWORD=********
ADMIN_EMAIL=owner@example.com,sales@example.com
ALLOWED_ORIGIN=https://www.example.com

ADMIN_PASSWORD=a-long-password-only-you-know
ADMIN_ALLOWED_IPS=203.0.113.10
DB_PATH=/home/account/private/africakaiser.sqlite
REVIEWS_SALT=replace-with-a-long-random-string
AUTO_APPROVE_VERIFIED=true
```

To use a hashed admin password instead:

```bash
php -r 'echo password_hash("your-password", PASSWORD_DEFAULT), PHP_EOL;'
```

Put the result in `ADMIN_PASSWORD_HASH`.

---

## Database

The schema is created automatically by `ak_db()` in `db.php`.

| Table | Contents |
| --- | --- |
| `categories` | Category slug, English/Portuguese name and blurb, image, sort order |
| `products` | Product ID, name, category, price/price range, unit, price term, MOQ, origin, descriptions (EN/PT), specs JSON, images JSON, featured/visible flags, sort order |
| `orders` | Quote requests from the cart: reference, customer, item IDs JSON, details |
| `reviews` | Target (`company` or a product ID), rating, author, body, email, order reference, verified/status, admin reply, hashed IP |
| `rfqs` | Buyer details, project/delivery fields, notes, status, uploaded-file metadata JSON |
| `login_attempts` | Admin login throttling by hashed IP |

Notes:

- The database uses WAL mode.
- Portuguese columns (`name_pt`, `description_pt`, `blurb_pt`) are added automatically to older databases on upgrade.
- On an empty database, `seed-catalog.json` loads the original catalogue and category list.
- Uploaded product images are stored under `uploads/products/`. RFQ files are stored under `data/rfq/<REF>/` and streamed only through the logged-in admin panel.
- No migration framework is used; schema changes are applied idempotently with `CREATE TABLE IF NOT EXISTS` and `ak_add_columns()`.

**Back up** the SQLite file, `data/rfq/` and `uploads/products/` together. The database and RFQ folder live in the same private directory, so backing up that directory plus `uploads/` covers everything user-generated.

---

## HTTP API

All API endpoints return JSON and set CORS headers when `ALLOWED_ORIGIN` is configured.

### `GET products.php`

Returns the public, visible catalogue. Hidden products are excluded.

```json
{
  "ok": true,
  "categories": [
    {
      "slug": "pvc-pipes",
      "name": "PVC Pipes",
      "namePt": "Tubos de PVC",
      "blurb": "SCH80 pressure pipe, 2\" to 12\"",
      "blurbPt": "Tubo de pressão SCH80, de 2\" a 12\"",
      "image": "/products/SCH80 PVC Pipe(3).png"
    }
  ],
  "products": [
    {
      "id": "10",
      "name": "Clinker",
      "namePt": "Clínquer",
      "category": "construction-materials",
      "price": 33.5,
      "priceMax": null,
      "unit": "ton",
      "priceTerm": "FOB Dongguan",
      "moq": null,
      "origin": "China",
      "description": "…",
      "descriptionPt": "…",
      "specs": [{ "label": "Application", "value": "Cement enhancer" }],
      "images": ["/products/Clinker.png"],
      "featured": 0,
      "visible": true
    }
  ]
}
```

The frontend also contains a bundled fallback catalogue and uses it if this endpoint is unreachable.

### `GET reviews.php`

Approved reviews for one target:

```text
reviews.php?target=10          # product ID
reviews.php?target=company     # service/company reviews
```

Returns `summary` (`avg`, `count`, `dist` per star) and the latest 100 approved `reviews`.

Rating summary for all targets, useful for product cards:

```text
reviews.php?summary=1
```

Returns `{ "ok": true, "targets": { "10": { "avg": 4.5, "count": 2 }, ... } }`.

### `POST reviews.php`

Form-encoded fields:

| Field | Required | Notes |
| --- | --- | --- |
| `target` | Yes | `company` or a product ID; letters, digits, dashes, max 60 chars |
| `rating` | Yes | Integer 1–5 |
| `name` | Yes | Max 80 chars |
| `email` | Yes | Validated, never shown publicly |
| `body` | Yes | At least 10 characters, max 3000 |
| `company` | No | Max 120 chars |
| `title` | No | Max 120 chars |
| `order_ref` | No | Order reference from a quote request; used to verify the buyer |

A hidden `website` honeypot field is checked. Valid submissions are rate-limited to **5 per IP per day**. Verified reviews are published immediately by default; all others wait for approval. Returns `{ "ok": true, "status": "pending" | "approved", "verified": true | false }`.

### `POST rfq.php`

`multipart/form-data` request. Fields:

`name`, `company`, `email`, `phone` (all required), plus optional `client_ref`, `project`, `sector`, `incoterm`, `location`, `needed_by` (`YYYY-MM-DD`), `brands`, `details`, `lang` (`pt` or `en`), and `files[]` (repeatable).

Attachment rules:

- Up to **10 files**
- **10 MB per file**, **25 MB total**
- Allowed: `pdf`, `xlsx`, `xls`, `csv`, `doc`, `docx`, `jpg`, `jpeg`, `png`, `webp`, `heic`, `heif`
- MIME type is checked with `fileinfo`, not just the extension
- An RFQ needs either at least one file or at least 10 characters of item details
- Rate limit: **10 RFQs per IP per day**

Returns `{ "ok": true, "ref": "RFQ-261116-A1B2" }`. The RFQ is saved before email is attempted, so a mail failure never loses the submission. Attachments are included in the notification email when the total is 18 MB or less; larger sets remain available in the admin panel.

### `POST send_mail.php`

Handles both the contact form and the cart quote request.

Fields: `form_type` (`contact` or `order`), `name`, `email`, `message` (required); optional `company`, `phone`, `subject`, `order_ref`, `items` (JSON array of product IDs), `lang`.

Orders are also written to the `orders` table so the same reference/email can later be used to leave a **Verified buyer** review. Returns `{ "ok": true }`.

---

## Admin panel

Open `/admin.php` and log in with the configured password.

- **RFQs** — list and filter by status (`new`, `quoting`, `quoted`, `won`, `lost`), open a request, view buyer details, download attachments, add internal notes, and reply by email/WhatsApp.
- **Products** — search/filter, create and edit products, manage specs and multiple image uploads, choose the main image, set price or price-on-request, mark featured/visible, and reorder.
- **Categories** — create/edit categories with EN/PT names and blurbs.
- **Reviews** — approve, hide, delete or reply to reviews, and see verified-buyer status.
- **Cart orders** — browse quote requests submitted from checkout.

Product images uploaded in the admin panel must be JPG, PNG, WebP or GIF and are limited to 8 MB each. When the GD extension is available, large images are resized to a maximum of 1600 px.

Security properties:

- Session cookie is `HttpOnly`, `SameSite=Strict`, and `Secure` when HTTPS is detected.
- CSRF token required for every admin POST.
- Login throttled to 5 failed attempts per IP per 15 minutes.
- Idle sessions expire after 2 hours.
- `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, a restrictive CSP and `noindex, nofollow` headers.
- Optional IP allow-list via `ADMIN_ALLOWED_IPS`.

`reviews-admin.php` is kept only as a redirect to the reviews tab of the main panel.

---

## Frontend notes

- `index.html` and `static/` are **build output**; the React source is not part of this repository.
- The app uses client-side routing (`/products`, `/products/:category`, `/cart`, `/checkout`, `/rfq`, `/contact`, `/about`). The web server must rewrite unknown paths to `index.html`.
- These build-time variables control the compiled app:
  - `REACT_APP_PRODUCTS_ENDPOINT` — defaults to `/products.php`
  - `REACT_APP_REVIEWS_ENDPOINT` — defaults to `/reviews.php`
  - `REACT_APP_MAIL_ENDPOINT` — defaults to `/send_mail.php`
  - `REACT_APP_RFQ_ENDPOINT` — defaults to `/rfq.php`
  - `REACT_APP_DEMO=1` — browser-only preview mode; no requests are sent and orders are kept in `localStorage`
- To change frontend behaviour or copy, rebuild from the React source and replace `index.html`, `static/` and `asset-manifest.json`. Update the hashed filenames in `index.html` accordingly.
- The app embeds a fallback catalogue; if `products.php` fails, the site may still render bundled products. Check the network tab if catalogue edits do not appear.

---

## Deployment

### Apache

The application expects to live at the document root. The app creates its own `.htaccess` files to protect `data/` and `uploads/products/`, so Apache must allow `.htaccess` overrides (`AllowOverride All`).

Add SPA fallback rewriting (and leave the PHP handlers alone). For example, in the document root `.htaccess`:

```apache
Options -Indexes
DirectoryIndex index.html

<IfModule mod_rewrite.c>
  RewriteEngine On

  # Serve real files and directories (PHP, static assets, images) as-is.
  RewriteCond %{REQUEST_FILENAME} -f [OR]
  RewriteCond %{REQUEST_FILENAME} -d
  RewriteRule ^ - [L]

  # Everything else is a client-side route.
  RewriteRule ^ index.html [L]
</IfModule>
```

If `AllowOverride` is disabled, add an equivalent deny rule for `data/` and `uploads/products/*.php` in the virtual host instead.

### Nginx

Nginx does not read `.htaccess`. Add explicit deny rules, then the SPA fallback:

```nginx
server {
    root /var/www/africakaiser;
    index index.html;

    # Never serve the SQLite database or uploaded RFQ files.
    location ^~ /data/ {
        deny all;
        return 404;
    }

    # Static assets and uploads.
    location / {
        try_files $uri $uri/ /index.html;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
}
```

PHP's upload limits must be at least as large as the RFQ limits. In `php.ini`:

```ini
upload_max_filesize = 10M
post_max_size = 30M
max_file_uploads = 10
```

### Production checklist

- [ ] Create `.env` with strong `ADMIN_PASSWORD`, `REVIEWS_SALT` and SMTP credentials; never commit it.
- [ ] Set `DB_PATH` to a directory **outside the web root** if possible.
- [ ] Confirm `https://your-site/data/africakaiser.sqlite` returns 403/404.
- [ ] Confirm uploaded RFQ files cannot be fetched directly by URL.
- [ ] Ensure `data/` and `uploads/` are writable by PHP but not world-readable.
- [ ] Serve the site over HTTPS (admin sessions and credentials depend on it).
- [ ] Test the contact form, a checkout request, and an RFQ with an attachment.
- [ ] Back up the SQLite database, `data/rfq/` and `uploads/products/`.
- [ ] Optionally restrict admin access with `ADMIN_ALLOWED_IPS`.

---

## Troubleshooting

| Symptom | Likely cause / fix |
| --- | --- |
| “Admin panel is locked: set ADMIN_PASSWORD…” | Set an `ADMIN_PASSWORD` of at least 12 characters, or `ADMIN_PASSWORD_HASH`, in `.env` |
| “The mail service is not configured.” | `SMTP_HOST` and `SMTP_USERNAME` are missing from `.env` |
| Emails fail but data is saved | Check SMTP host/port/encryption and credentials; inspect `error_log` messages from `send_mail.php` / `rfq.php` |
| Catalogue API returns 500 | Check PHP version, `pdo_sqlite`, and write permission on the `data/` directory |
| Product edits do not appear | The SPA may be using its bundled fallback catalogue. Check `products.php` in the browser/network tab |
| RFQ upload rejected | File type, 10 MB/file, 10 files, 25 MB total, or PHP `upload_max_filesize` / `post_max_size` limits |
| Deep links like `/products` return 404 | Add the SPA `try_files` / rewrite rule shown above |
| “Too many requests” / “Too many reviews” | Per-IP rate limits: 10 RFQs and 5 reviews per day, 5 failed admin logins per 15 minutes |
| Reviews never appear | New reviews are moderated unless they are verified buyers with `AUTO_APPROVE_VERIFIED=true` |
| Catalogue disappeared after cleanup | Seeding only runs when `categories` is empty; restore the database or re-import `seed-catalog.json` |

For PHP errors, check the server error log or run locally with:

```bash
php -d display_errors=1 -d error_reporting=E_ALL -S 127.0.0.1:8000
```

---

## License

No licence file is included. Unless separately agreed, the site content, branding and source are proprietary to Africa Kaiser. PHPMailer retains its own licence (see `vendor/phpmailer/phpmailer/LICENSE`).
