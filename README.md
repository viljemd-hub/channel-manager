# Channel Manager – CM Free (dev snapshot)

This repository contains a development snapshot of **CM Free** (PHP/Apache).  
It is shared **as-is**: developer-friendly, JSON-based, and still evolving.

- Landing page / docs: https://cmfree.netlify.app/ (usage manual: `#details`)
- Public UI: `/app/public/`
- Admin UI: `/app/admin/`

---

## What CM Free is

CM Free is a lightweight channel/booking manager built around:
- **public booking flow** (calendar → offer → inquiry → thankyou),
- **admin workflow** (inquiries → accept/reject → reservations),
- JSON storage (no database),
- optional email sending (msmtp / sendmail integration).

---

## Quick start (minimal)

### 1) Requirements
- Linux server (Debian/Ubuntu recommended)
- Apache 2.4
- PHP 8.1+ with common extensions:
  - `php-mbstring`, `php-json`, `php-xml`, `php-curl`, `php-zip`
- `zip` / `unzip` or `tar`

### 2) Unpack the app
Unpack under `/var/www/html` so you get `/var/www/html/app`:

```bash
cd /var/www/html
sudo tar xzf app_cmfree_*.tar.gz
# or
sudo unzip app_cmfree_*.zip
````

### 3) Permissions

Apache must be able to write into JSON + logs:

```bash
cd /var/www/html
sudo chown -R www-data:www-data app
sudo find app -type d -exec chmod 775 {} \;
sudo find app -type f -exec chmod 664 {} \;
```

Make sure these are writable:

* `app/common/data/json`
* `app/common/data/json/units`
* `app/common/data/json/inquiries`
* `app/common/data/json/reservations`
* `app/logs`

### 4) Apache

Serve as `http://<host>/app`:

* DocumentRoot: `/var/www/html`
* Restart:

```bash
sudo systemctl restart apache2
```

Open:

* `http://<server>/app/admin/`
* `http://<server>/app/public/`

---

## Admin access (important)

Admin pages in this snapshot use an **access-key gate** (`require_key()`), e.g. `admin/admin_calendar.php` includes it.

* The mechanism lives in: `app/admin/_common.php` (loaded by admin pages).
* If you hit an access-key screen or redirect, search for the key configuration in that file.

Helpful command:

```bash
cd /var/www/html/app
grep -RIn --color=always "require_key" admin
```

Security note: this is a dev snapshot. If you expose it publicly, protect `/app/admin/` (reverse proxy, IP allowlist, basic auth, VPN, etc.).

---

## Configuration

Most runtime configuration is stored in JSON:

* Global site settings:

  * `app/common/data/json/site_settings.json`
* Per-unit settings and data:

  * `app/common/data/json/units/<UNIT>/...`

For first use, adjust at least:

* `site_settings.json`:

  * `site.base_url`, default language
  * `email.*` (if you want mail sending)
* Create at least one unit (via admin UI or JSON templates):

  * meta + capacity
  * `prices.json` (flat nightly price is enough)
  * occupancy can start empty

---

## Email (optional)

If your server is not configured for sending email yet:

* disable in `site_settings.json` via `email.enabled=false`,
* or configure msmtp/sendmail properly, then enable.

---

## First-use checklist (demo flow)

1. Open public:

* `http://<server>/app/public/`

2. Submit an inquiry:

* select unit + dates → offer page → submit inquiry

3. Open admin:

* `http://<server>/app/admin/`
* go to **Inquiries**
* confirm or reject an inquiry

4. Confirm reservation (guest link, if enabled):

* use the email link to confirm reservation as a guest

This creates:

* `common/data/json/inquiries/YYYY/MM/{pending,accepted,rejected,confirmed}`
* `common/data/json/reservations/YYYY/<UNIT>/...`
  and updates occupancy/merged data for the selected unit.

Note: `thankyou.php` uses PRG (Post/Redirect/Get) to prevent duplicate inquiry processing on browser refresh.

---

## Data model (no database)

CM Free is intentionally JSON-first:

* easier to inspect and debug,
* logs are preferred over “magic” UI.

---

## Logs / troubleshooting

If something behaves strangely:

* `app/logs/app.log`
* `/var/log/apache2/error.log`

Tip:

* if you see a 500 error, check Apache error log first.

---

## Disclaimer

This is a work-in-progress dev snapshot, shared as-is.
Expect rough edges, missing documentation, and paths optimized for `/var/www/html/app`.

Feedback, ideas and criticism are welcome.

```

### Kaj je konkretno popravljeno
- odstranjena zavajajoča trditev o “no authentication”
- dodano “Admin access” poglavje (brez ugibanja; samo: kje je mehanizem in kako ga najdeš)
- dodani linki do landing/docs
- Quick start je na vrhu (to je pomembno za prve uporabnike)

Če želiš, ti lahko naslednjič pripravim še:
- minimalen `LICENSE` (če želiš “res free” za druge),
- GitHub “Release v1.0.0” opis (da meriš download count),
- 2 issue template datoteki (bug report + feature request).

Ti samo povej, ali želiš, da je to “open-source” (MIT/Apache) ali “source-available” (bolj kontrolirano).
```
# Channel Manager — kratka navodila

Izsek iz INSTALL.txt (povzetek):
```
 
Channel Manager – CM Free (dev snapshot)
=======================================

This is a development snapshot of my CM Free app (PHP/Apache).

It is not a polished installer yet, but it should run on a standard Linux LAMP stack.
Below are minimal steps to get it running on your own test server.

1. Requirements
---------------

- Linux server (Debian/Ubuntu recommended)
- Apache 2.4
- PHP 8.1+ with common extensions:
  - php-mbstring, php-json, php-xml, php-curl, php-zip
- `zip` / `unzip` or `tar` for unpacking

2. Unpack the app
-----------------

Copy the archive to your server and unpack it under /var/www/html:

    cd /var/www/html
    sudo tar xzf app_cmfree_*.tar.gz
    # or
    sudo unzip app_cmfree_*.zip

You should now have:

    /var/www/html/app

3. File ownership & permissions
-------------------------------

Make Apache the owner and give it write access to the data/logs folders:

    cd /var/www/html
    sudo chown -R www-data:www-data app
    sudo find app -type d -exec chmod 775 {} \;
    sudo find app -type f -exec chmod 664 {} \;

Make sure these directories are writable:

    app/common/data/json
    app/common/data/json/units
    app/common/data/json/inquiries
    app/common/data/json/reservations
    app/logs

4. Apache configuration
-----------------------

Simplest option is to serve it as http://your-host/app:

- Ensure the default vhost DocumentRoot is /var/www/html.
- Restart Apache:

    sudo systemctl restart apache2

Then open in a browser:

    http://localhost/app/admin/
    http://localhost/app/public/

5. Initial configuration
------------------------

Most runtime configuration lives in JSON files under:

    app/common/data/json/units/
    app/common/data/json/site_settings.json

For this dev snapshot, you will probably want to:

- Adjust `site_settings.json` (emails, language, currency, etc.).
- Adjust or create unit JSON files under `units/<UNIT>/`.
- Clear demo data:
  - `common/data/json/inquiries/*`
  - `common/data/json/reservations/*`

There is no “wizard” yet – configuration is mostly JSON + admin UI.

6. Disclaimer
-------------

This package is a work-in-progress dev snapshot, shared as-is.
Expect rough edges, missing documentation and hard-coded paths
(`/var/www/html/app`).

Feedback, ideas and criticism are very welcome. :)


7. First-use checklist (how to actually start using it)
-------------------------------------------------------

After unpacking the app and fixing permissions, these are the minimal steps
to get a working demo of CM Free.

1) Open the admin area

Open in your browser:

    http://<server>/app/admin/

There is no authentication in this dev snapshot. The landing page links to
the main admin sections (calendar, inquiries, integrations, etc.).

2) Configure basic site settings

Edit:

    app/common/data/json/site_settings.json

and adjust at least:

- "site": name, base_url, default language
- "email": from_email, from_name, admin_email
- "currency" and formatting (if needed)

If email is not configured on your server, you can temporarily disable sending
in the "email.enabled" flag or in the admin UI.

3) Create or adjust at least one unit

Either:

- Use the admin UI: "Units" → "Add unit" (recommended in this snapshot), or
- Manually edit JSON under:

    app/common/data/json/units/<UNIT>/

For a quick test you only need:

- basic meta (name, capacity),
- a simple prices.json (flat price per night),
- occupancy.json can stay empty.

4) Check integrations (Autopilot / ICS)

For CM Free, Autopilot is intentionally locked and disabled.
The "Autopilot – Plus" card in Integrations is only a preview.

ICS / Channels cards are present as early UI – you do not need to configure
them to test the core booking flow.

5) Test the public booking flow

Open:

    http://<server>/app/public/

Then:

1. Choose a unit and dates in the calendar.
2. Go to the offer page and submit an inquiry.
3. Check the admin "Inquiries" section.
4. Confirm one inquiry (soft-hold) and then use the email link to confirm
   the reservation as a guest.
5. Optionally cancel the reservation using the cancel link in the email.

This should create JSON files under:

- common/data/json/inquiries/YYYY/MM/{pending,accepted,rejected,confirmed}
- common/data/json/reservations/YYYY/<UNIT>/

and update the occupancy for the selected unit.

6) Logs for debugging

If something behaves strangely, look at:

    app/logs/app.log
    /var/log/apache2/error.log

Most API endpoints log at least one line there when something fails.

This snapshot is intentionally "developer-friendly": JSON structure and logs
are more important than polished UI at this stage.
