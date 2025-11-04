# AnonGuard — depersonalizer plugin

This plugin anonymizes personal data in Webasyst Shop-Script orders and contacts
that are older than a configured retention period.

Features:

* Backend interface for configuring retention, previewing affected data and running depersonalization with progress tracking.
* Download link for the latest batch log directly from the plugin page.
* CLI command for batch depersonalization.
* Settings stored in `wa_app_settings`.
* All runs write to `wa-log/depersonalizer.log`.
* Batch details saved as JSON under `wa-log/depersonalizer/YYYY-MM-DD/`.
