# Upgrade – 2026-01-25  
Global settings modal + Add Unit template cleanup

## Overview

This upgrade focuses on two areas:

1. **Global settings (URL + email) editor** in the Admin → Integrations → Units screen.
2. **Cleanup of Add Unit “template” logic**, so new units can be created without any legacy A2 baseline and without half-initialized unit folders.

These changes are **Free/Plus–safe** and do **not** introduce any Pro-only behaviour. They mainly improve stability and admin UX.

---

## 1. Global settings modal (site_settings.json)

### New API endpoints

Added two endpoints to manage global email + URL settings stored in  
`common/data/json/units/site_settings.json`:

- `admin/api/site_settings_get.php`
  - Reads `site_settings.json`.# Upgrade – 2026-01-25  
Global settings modal + Add Unit template cleanup

## Overview

This upgrade focuses on two areas:

1. **Global settings (URL + email) editor** in the Admin → Integrations → Units screen.
2. **Cleanup of Add Unit “template” logic**, so new units can be created without any legacy A2 baseline and without half-initialized unit folders.

These changes are **Free/Plus–safe** and do **not** introduce any Pro-only behaviour. They mainly improve stability and admin UX.

---

## 1. Global settings modal (site_settings.json)

### New API endpoints

Added two endpoints to manage global email + URL settings stored in  
`common/data/json/units/site_settings.json`:

- `admin/api/site_settings_get.php`
  - Reads `site_settings.json`.
  - Returns:
    - `public_base_url`
    - `email.enabled`
    - `email.from_email`
    - `email.from_name`
    - `email.admin_email`
  - If `public_base_url` is empty, tries to derive it from `manifest.json` (base_url/domain).

- `admin/api/site_settings_save.php`
  - Accepts JSON POST payload:
    - `public_base_url` (string)
    - `email.enabled` (bool)
    - `email.from_email` (string)
    - `email.from_name` (string)
    - `email.admin_email` (string)
  - Does basic sanity checks on email fields (must contain `@` when non-empty).
  - Loads existing `site_settings.json` and **only updates** `public_base_url` and `email` block,
    leaving other keys (`autopilot`, etc.) untouched.
  - Writes changes atomically via `*.tmp` + `rename()`.

`admin/integrations.php` was updated to register these endpoints in `$CFG['api']`:

- `siteSettingsGet  => /app/admin/api/site_settings_get.php`
- `siteSettingsSave => /app/admin/api/site_settings_save.php`

### New modal in Integrations → Units

In `admin/integrations.php`:

- Added a new button in the Units / domain row:

  - **“E-mail & URL”** (`id="btnOpenGlobalSettings"`)

- Added a new `<dialog>`:

  - `id="dlgGlobalSettings"`
  - Contains fields for:
    - `Base URL` (`#gsBaseUrl`)
    - `Email enabled` (`#gsEmailEnabled`)
    - `From name` (`#gsFromName`)
    - `From e-mail` (`#gsFromEmail`)
    - `Admin e-mail` (`#gsAdminEmail`)
  - Footer buttons:
    - **Prekliči** (`#btnGlobalSettingsCancel`, `type="button"`)
    - **Shrani** (form `submit`)

### JS wiring (admin/ui/js/integrations_units.js)

`integrations_units.js` was extended with:

- Element refs inside `boot()` for the new modal:
  - `btnOpenGlobalSettings`, `dlgGlobalSettings`, `formGlobalSettings`,
    `btnGlobalSettingsCancel`, `inGsBaseUrl`, `chkGsEmailEnable`,
    `inGsFromName`, `inGsFromEmail`, `inGsAdminEmail`.

- Functions:
  - `loadGlobalSettingsIntoModal()`
    - Calls `CFG.api.siteSettingsGet`.
    - Populates modal fields from response.
    - If `public_base_url` is empty, falls back to current domain/base URL input value.
  - `saveGlobalSettings(e)`
    - Normalizes `public_base_url` (auto-prefix `https://` when missing).
    - Sends JSON payload to `CFG.api.siteSettingsSave`.
    - On success:
      - Updates top-level domain/base URL input for visual consistency.
      - Closes the modal.
  - `openGlobalSettingsModal()`
    - Calls `loadGlobalSettingsIntoModal()` and then `showModal()`.

- Event listeners (inside `boot()`):
  - `btnOpenGlobalSettings` → `openGlobalSettingsModal()`
  - `formGlobalSettings` → on `submit`, `preventDefault()` + `saveGlobalSettings(e)`
  - `btnGlobalSettingsCancel` → closes `dlgGlobalSettings` without saving

**Bugfix:**  
Early debug wiring only closed the modal on submit (no save).  
This is now replaced with a proper call to `saveGlobalSettings`, and the Cancel button again
just closes the dialog.

Result:  
Admin can now maintain **global Base URL** (for links in emails) and **email settings**
(`from_*`, `admin_email`, `enabled`) directly from the Integrations → Units UI, without
editing JSON files manually.

---

## 2. Add Unit – template cleanup & safer defaults

### Removed legacy “template unit” UI

In `admin/integrations.php` (Add Unit modal):

- Removed the legacy `<select id="addUnitTemplate">` with hardcoded options
  (A1/A2/S1) and its descriptive text.
- Add Unit modal is visually simplified:
  - Admin now sees only relevant fields:
    - Unit ID, Label, Property ID, owner, months_ahead, active/public flags,
      booking settings, cleaning, weekly/long discounts, etc.

This avoids confusing references to A2/S1 test templates, which may no longer exist
in real installations.

### JS: do not force template "A2"

In `admin/ui/js/integrations_units.js`:

- The `addPayload` for `add_unit.php` no longer does:
  - `template_unit: (inTpl?.value || 'A2')`
- Instead, template is effectively disabled for now:
  - either omitted or set to an empty value
- This ensures the frontend never implicitly requests template “A2” when it doesn’t exist.

### Backend: template is optional, no more “Template A2 missing” 500

In `admin/api/add_unit.php`:

- Previous behaviour:
  - If `template_unit` was non-empty and template directory didn’t exist,  
    the code threw a `RuntimeException("Template enota ne obstaja: {$template}")`,
    resulting in HTTP 500 and a half-created unit directory.
- New behaviour:
  - If `template_unit` is provided and the directory **exists**:
    - copy over relevant JSON files (site_settings, prices, special_offers,
      occupancy_sources, day_use) as before.
  - If `template_unit` directory does **not** exist:
    - template is treated as optional → it is silently ignored (`$template = ''`),
    - the unit is initialized from the normal baseline logic.

Baseline behaviour (when no template is valid) remains:

- `add_unit.php` builds a fresh `site_settings.json` and other core JSON files
for the new unit using internal defaults, then applies values from the Add Unit form.

Result:

- Admin can add new units **without any template unit present**,  
  even if the old A2 test unit was removed.
- No more “Template enota ne obstaja: A2” HTTP 500 errors.
- No more half-initialized unit folders caused by failed template checks.

---

## Notes for deployment

1. **Sync code to all environments** (DEV → M2 → LIVE):
   - `admin/integrations.php`
   - `admin/ui/js/integrations_units.js`
   - `admin/api/site_settings_get.php` (new)
   - `admin/api/site_settings_save.php` (new)
   - `admin/api/add_unit.php`

2. On each environment:
   - Hard-reload `admin/integrations.php` in the browser (disable cache or `Ctrl+F5`),
     to ensure the updated JS is loaded.

3. After deploy:
   - In Admin → Integrations → Units:
     - Open **“E-mail & URL”** modal, set:
       - correct `Base URL` (public app URL),
       - `From` name/email,
       - correct `Admin e-mail` (for notifications),
       - click **Shrani**.
   - Optionally add a new test unit to verify Add Unit works without template errors.

This upgrade keeps CM Free/Plus behaviour compatible, while significantly improving
admin UX and robustness around global settings and unit creation.

  - Returns:
    - `public_base_url`
    - `email.enabled`
    - `email.from_email`
    - `email.from_name`
    - `email.admin_email`
  - If `public_base_url` is empty, tries to derive it from `manifest.json` (base_url/domain).

- `admin/api/site_settings_save.php`
  - Accepts JSON POST payload:
    - `public_base_url` (string)
    - `email.enabled` (bool)
    - `email.from_email` (string)
    - `email.from_name` (string)
    - `email.admin_email` (string)
  - Does basic sanity checks on email fields (must contain `@` when non-empty).
  - Loads existing `site_settings.json` and **only updates** `public_base_url` and `email` block,
    leaving other keys (`autopilot`, etc.) untouched.
  - Writes changes atomically via `*.tmp` + `rename()`.

`admin/integrations.php` was updated to register these endpoints in `$CFG['api']`:

- `siteSettingsGet  => /app/admin/api/site_settings_get.php`
- `siteSettingsSave => /app/admin/api/site_settings_save.php`

### New modal in Integrations → Units

In `admin/integrations.php`:

- Added a new button in the Units / domain row:

  - **“E-mail & URL”** (`id="btnOpenGlobalSettings"`)

- Added a new `<dialog>`:

  - `id="dlgGlobalSettings"`
  - Contains fields for:
    - `Base URL` (`#gsBaseUrl`)
    - `Email enabled` (`#gsEmailEnabled`)
    - `From name` (`#gsFromName`)
    - `From e-mail` (`#gsFromEmail`)
    - `Admin e-mail` (`#gsAdminEmail`)
  - Footer buttons:
    - **Prekliči** (`#btnGlobalSettingsCancel`, `type="button"`)
    - **Shrani** (form `submit`)

### JS wiring (admin/ui/js/integrations_units.js)

`integrations_units.js` was extended with:

- Element refs inside `boot()` for the new modal:
  - `btnOpenGlobalSettings`, `dlgGlobalSettings`, `formGlobalSettings`,
    `btnGlobalSettingsCancel`, `inGsBaseUrl`, `chkGsEmailEnable`,
    `inGsFromName`, `inGsFromEmail`, `inGsAdminEmail`.

- Functions:
  - `loadGlobalSettingsIntoModal()`
    - Calls `CFG.api.siteSettingsGet`.
    - Populates modal fields from response.
    - If `public_base_url` is empty, falls back to current domain/base URL input value.
  - `saveGlobalSettings(e)`
    - Normalizes `public_base_url` (auto-prefix `https://` when missing).
    - Sends JSON payload to `CFG.api.siteSettingsSave`.
    - On success:
      - Updates top-level domain/base URL input for visual consistency.
      - Closes the modal.
  - `openGlobalSettingsModal()`
    - Calls `loadGlobalSettingsIntoModal()` and then `showModal()`.

- Event listeners (inside `boot()`):
  - `btnOpenGlobalSettings` → `openGlobalSettingsModal()`
  - `formGlobalSettings` → on `submit`, `preventDefault()` + `saveGlobalSettings(e)`
  - `btnGlobalSettingsCancel` → closes `dlgGlobalSettings` without saving

**Bugfix:**  
Early debug wiring only closed the modal on submit (no save).  
This is now replaced with a proper call to `saveGlobalSettings`, and the Cancel button again
just closes the dialog.

Result:  
Admin can now maintain **global Base URL** (for links in emails) and **email settings**
(`from_*`, `admin_email`, `enabled`) directly from the Integrations → Units UI, without
editing JSON files manually.

---

## 2. Add Unit – template cleanup & safer defaults

### Removed legacy “template unit” UI

In `admin/integrations.php` (Add Unit modal):

- Removed the legacy `<select id="addUnitTemplate">` with hardcoded options
  (A1/A2/S1) and its descriptive text.
- Add Unit modal is visually simplified:
  - Admin now sees only relevant fields:
    - Unit ID, Label, Property ID, owner, months_ahead, active/public flags,
      booking settings, cleaning, weekly/long discounts, etc.

This avoids confusing references to A2/S1 test templates, which may no longer exist
in real installations.

### JS: do not force template "A2"

In `admin/ui/js/integrations_units.js`:

- The `addPayload` for `add_unit.php` no longer does:
  - `template_unit: (inTpl?.value || 'A2')`
- Instead, template is effectively disabled for now:
  - either omitted or set to an empty value
- This ensures the frontend never implicitly requests template “A2” when it doesn’t exist.

### Backend: template is optional, no more “Template A2 missing” 500

In `admin/api/add_unit.php`:

- Previous behaviour:
  - If `template_unit` was non-empty and template directory didn’t exist,  
    the code threw a `RuntimeException("Template enota ne obstaja: {$template}")`,
    resulting in HTTP 500 and a half-created unit directory.
- New behaviour:
  - If `template_unit` is provided and the directory **exists**:
    - copy over relevant JSON files (site_settings, prices, special_offers,
      occupancy_sources, day_use) as before.
  - If `template_unit` directory does **not** exist:
    - template is treated as optional → it is silently ignored (`$template = ''`),
    - the unit is initialized from the normal baseline logic.

Baseline behaviour (when no template is valid) remains:

- `add_unit.php` builds a fresh `site_settings.json` and other core JSON files
for the new unit using internal defaults, then applies values from the Add Unit form.

Result:

- Admin can add new units **without any template unit present**,  
  even if the old A2 test unit was removed.
- No more “Template enota ne obstaja: A2” HTTP 500 errors.
- No more half-initialized unit folders caused by failed template checks.

---

## Notes for deployment

1. **Sync code to all environments** (DEV → M2 → LIVE):
   - `admin/integrations.php`
   - `admin/ui/js/integrations_units.js`
   - `admin/api/site_settings_get.php` (new)
   - `admin/api/site_settings_save.php` (new)
   - `admin/api/add_unit.php`

2. On each environment:
   - Hard-reload `admin/integrations.php` in the browser (disable cache or `Ctrl+F5`),
     to ensure the updated JS is loaded.

3. After deploy:
   - In Admin → Integrations → Units:
     - Open **“E-mail & URL”** modal, set:
       - correct `Base URL` (public app URL),
       - `From` name/email,
       - correct `Admin e-mail` (for notifications),
       - click **Shrani**.
   - Optionally add a new test unit to verify Add Unit works without template errors.

This upgrade keeps CM Free/Plus behaviour compatible, while significantly improving
admin UX and robustness around global settings and unit creation.
