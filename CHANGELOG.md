# Changelog

## 0.4.1
* Respect preview selections during batch execution by passing the flag to AJAX requests.
* Sanitize submitted keys before rendering the progress page and when executing batches.
* Provide Russian translations for the new Preview and Run buttons.

## 0.4.0
* Add interactive backend interface with preview summaries, field selection and real-time progress updates.
* Allow downloading the latest depersonalization batch log from the backend.
* Respect preview selections when depersonalizing orders and skip anonymization when all keys are deselected.

## 0.3.2
* Detect shipping_*, billing_* and utm_* parameters with heuristics and allow excluding detected keys during preview.

## 0.3.1
* Use plugin logger in CLI to create `wa-log/depersonalizer.log`.

## 0.3.0
* Initial implementation with CLI depersonalization.

