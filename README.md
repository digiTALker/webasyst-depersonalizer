# Webasyst Depersonalizer (Standalone)

This repository contains a standalone PHP web page for anonymizing old Webasyst/Shop-Script personal data directly in MySQL.

It is not a Webasyst plugin. Do not install it through Webasyst Installer, hooks, plugin registration, or backend routing. Old plugin code is kept only in `legacy-plugin/` for reference.

## Target Deployment

Example deployment path:

```text
/home/d/dtalke/shop.incyber.ru/public_html/test/tools/depersonalizer/
```

Example URL:

```text
https://shop.incyber.ru/test/tools/depersonalizer/index.php
```

The tool should be pointed at the test database first:

```text
dtalke_test_shop
```

## Local Config

Create `config.local.php` from `config.local.php.example`. Do not commit it.

```php
<?php
return array(
    'driver'   => 'mysql',
    'host'     => 'localhost',
    'port'     => 3306,
    'database' => 'dtalke_test_shop',
    'user'     => 'dtalke_test_shop',
    'password' => 'REPLACE_WITH_REAL_PASSWORD',
    'charset'  => 'utf8mb4',
    'socket'   => '',

    'access_token' => 'REPLACE_WITH_LONG_RANDOM_ACCESS_TOKEN',
    'allow_prod' => false,
);
```

The loader also tolerates nested `db` arrays and Webasyst `wa-config/db.php`, but the normalized internal config is flat.

## Beget / PHP

The web server for this folder may run PHP 8.1 while the default shell `php` can be PHP 5.6. Use the explicit PHP 8.1 binary for syntax checks:

```bash
cd ~/shop.incyber.ru/public_html/test/tools/depersonalizer
/usr/local/php-cgi/8.1/bin/php -l index.php
/usr/local/php-cgi/8.1/bin/php -l src/StandaloneConfigLoader.php
/usr/local/php-cgi/8.1/bin/php -l src/StandaloneDepersonalizer.php
```

All SQL is kept MySQL 5.7-compatible. Do not add CTEs, window functions, `JSON_TABLE`, `REGEXP_REPLACE`, or MySQL 8-only collations.

## Safety

- Protect the folder with BasicAuth or an IP allowlist before opening it publicly.
- A sample BasicAuth file is provided as `.htaccess.example`; it assumes `/home/d/dtalke/.htpasswd` exists.
- Delete `phpver.php` from the deployment folder.
- Do not leave debug output enabled.
- Do not commit `config.local.php`, logs, storage files, or real credentials.
- Rotate credentials if they were pasted into chats, screenshots, or logs.
- Make a fresh database backup before any non-dry-run execution.
- Real runs require the backup checkbox and exact `ANONYMIZE` confirmation phrase.
- Production-like targets are blocked unless local config explicitly contains `'allow_prod' => true`.

## Workflow

1. Open the page and unlock it with the access token.
2. Confirm the environment badge is `TEST`.
3. Confirm DB is `dtalke_test_shop`.
4. Run `Preview` with the default 365-day retention.
5. Review detected `shop_order_params` candidate keys and uncheck anything technical.
6. Keep `Dry-run` enabled and press `Run`.
7. Review progress and logs.
8. Only after validating logs, disable `Dry-run`, check the backup box, type `ANONYMIZE`, and run the real batch.

## What It Changes

- Orders are selected from `shop_order` by `create_datetime < cutoff`.
- Already processed orders are skipped using `shop_order_params` markers:
  - `_depersonalizer_ext_processed = 1`
  - `_depersonalizer_ext_processed_at = timestamp`
- Candidate personal data is updated only in selected `shop_order_params` keys.
- Optional contact anonymization is limited to contacts with no newer orders.
- Contact state is tracked in standalone table `depersonalizer_state`; `wa_contact_params` is optional and not required.
- The tool does not delete order/contact rows and does not modify products, items, totals, statuses, payments, shipping rates, or reports.

## Logs

Runtime logs are created automatically:

```text
logs/depersonalizer.log
logs/batches/YYYY-MM-DD/batch-HH-MM-SS-random.json
```

Batch logs include run id, timestamp, dry-run flag, options, cutoff, processed/skipped order and contact IDs, and selected include keys. Original personal data values are not logged.

## Legacy Plugin

`legacy-plugin/` is reference-only. The supported product is the standalone page in this repository root.
