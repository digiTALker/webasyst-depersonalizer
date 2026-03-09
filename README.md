# Webasyst Depersonalizer (Standalone)

This repository now provides a **standalone PHP page** for depersonalizing old Webasyst Shop-Script data.

No plugin installation is required. Upload this folder to your server, open `index.php`, and run the process from the page.

## Why this version

The old plugin-oriented implementation had architecture and encoding inconsistencies (mixed UI/CLI states, duplicated logic, broken RU text encoding in files). The project is now unified around one standalone service.

## Files

- `index.php` - web page and API endpoints (`preview` + batch `run`)
- `src/StandaloneConfigLoader.php` - DB config loader (auto from `wa-config/db.php` or local override)
- `src/StandaloneDepersonalizer.php` - depersonalization engine
- `config.local.php.example` - local DB config template
- `logs/` - runtime and batch logs (created automatically)

## Deployment

1. Upload the folder to your server (for example: `/var/www/site/tools/depersonalizer/`).
2. Open `index.php` in browser.
3. If auto-detection fails, create `config.local.php` from `config.local.php.example`.
4. Run **Preview** first, then execute batches.

## Safety model (conflict-averse with Webasyst core)

This tool is intentionally conservative:

- No schema migrations or table structure changes.
- No deletions from core order/contact tables.
- Updates run in DB transactions batch-by-batch.
- Processed rows are marked via namespaced keys:
  - `shop_order_params._depersonalizer_ext_processed`
  - `wa_contact_params._depersonalizer_ext_processed`
- Existing IDs and table relations are preserved.

## Notes

- Always take a DB backup before first real run.
- Keep `dry-run` enabled until preview results look correct.
- Contact anonymization is optional and only affects contacts without newer orders.
- Restrict access to this page (IP allowlist or basic auth) before production use.

## Legacy plugin files

Old plugin files are moved to `legacy-plugin/` for reference, but the current supported workflow is the standalone page.


