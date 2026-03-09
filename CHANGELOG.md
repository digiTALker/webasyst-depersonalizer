# Changelog

## 1.0.0
- Reworked project from plugin-first implementation to standalone web page service (`index.php`).
- Added automatic DB discovery from `wa-config/db.php` with `config.local.php` fallback.
- Added conflict-averse batch processing with transactions and explicit processed markers.
- Added preview mode and iterative AJAX run with progress output.
- Normalized docs and removed encoding-corrupted text from active workflow files.
- Moved old plugin artifacts into legacy-plugin/ to avoid deployment ambiguity.

## 0.3.2
- Detect shipping_*, billing_* and utm_* parameters with heuristics and allow excluding detected keys during preview.

## 0.3.1
- Use plugin logger in CLI to create `wa-log/depersonalizer.log`.

## 0.3.0
- Initial implementation with CLI depersonalization.

