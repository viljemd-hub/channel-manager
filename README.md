# CM Free / Plus

CM Free / Plus is a lightweight, self-hosted reservation and availability manager for small accommodation providers, apartments, holiday rentals and similar small hospitality setups.

It is built around a simple principle:

> Own your calendar, own your data, avoid unnecessary SaaS complexity.

CM Free / Plus runs on a classic web stack, stores its state in JSON files, and is designed to be understandable, movable and easy to maintain.

## Live demo

Demo:

http://cmfree.duckdns.org/app/

The demo is hosted on a small local test machine and is intended for quick first impressions, UI testing and feedback.

## Downloads

Download page:

https://apartmamatevz.si/cmfree/download.html

Available package types:

### Clean install packages

Use these for real installations.

- Clean ZIP: for classic hosting or manual installation.
- Clean DEB: for Ubuntu/Debian systems.

The clean packages do not include:

- common/data/admin_key.txt
- common/data/json/
- demo reservations
- runtime state

The first-use flow creates the initial admin key and JSON structure.

### Demo / showroom packages

Use these for testing, demonstrations or sandbox installs.

Demo packages may include prepared example content or demo state so the system is easier to inspect immediately after installation.

## Main features

CM Free / Plus currently includes:

- public availability calendar
- date range selection
- offer / inquiry flow
- admin calendar
- inquiry management
- reservation management
- local blocks / hard locks
- basic unit management
- first-use setup flow
- help center and onboarding guides
- JSON-backed data storage
- ICS-oriented import/export workflows
- simple review flow
- optional graffiti / lightweight engagement widget
- email sending through system mail transport

## Installation options

### Option 1: ZIP / hosting install

Use the clean ZIP package if you want to install manually on a hosting account or web server.

Basic idea:

1. Download the clean ZIP.
2. Extract the app/ folder to your web root.
3. Make sure app/common/data/ is writable by the web server.
4. **Block direct web access to app/common/data/** - it holds the admin key,
   guest/reservation records, and (if you run the VRP node) your signing key.
   A `.htaccess` is included in that folder as a first line of defense, but
   it only works if your host has `AllowOverride` enabled for that path - do
   not rely on it alone. On your own server (Apache, not shared hosting),
   add a vhost-level rule instead, which `.htaccess` cannot be bypassed by a
   misconfigured override:

       <DirectoryMatch "^/var/www/html/app/common/data/(?!json/units/)">
           Require all denied
       </DirectoryMatch>

   (adjust the path to your install; `json/units/` is exempted because a
   small number of public, non-sensitive per-unit files are served from
   there - check your own unit config before assuming anything else under
   `common/data/` is safe to expose.) After adding it, confirm it works:
   `curl -I https://your-domain.example/app/common/data/admin_key.txt`
   should return 403, not 200.
5. Open /app/admin/.
6. Complete the first-use setup.

Example target path:

    /var/www/html/app

Then open:

    http://your-domain.example/app/admin/

### Option 2: DEB / Ubuntu-Debian install

Use the clean DEB package for Ubuntu/Debian based systems.

Example:

    sudo apt update
    sudo apt install ./cmfree_clean_amd64_1.3.1.deb

The DEB package installs the application under:

    /var/www/html/app

and declares dependencies for:

- Apache
- PHP
- common PHP modules
- msmtp
- msmtp-mta
- unzip

After installation, open:

    http://localhost/app/admin/

or from another device on the same network:

    http://SERVER-IP/app/admin/

The DEB package does not add its own Apache vhost rule for
`app/common/data/` - add the deny rule shown under Option 1, step 4, before
exposing the server beyond your local network.

## First-use setup

The clean package is intentionally shipped without runtime state.

On first use, the setup flow creates:

- common/data/admin_key.txt
- common/data/json/instance.json
- common/data/json/units/manifest.json
- the first unit
- per-unit JSON files
- integration skeletons

The admin key is generated automatically if it does not already exist.

## Data model

CM Free / Plus is JSON-backed.

Typical runtime structure:

    common/data/
    ├── admin_key.txt
    ├── i18n/
    └── json/
        ├── instance.json
        ├── units/
        │   ├── manifest.json
        │   └── <UNIT>/
        │       ├── prices.json
        │       ├── occupancy.json
        │       ├── occupancy_merged.json
        │       ├── local_bookings.json
        │       ├── day_use.json
        │       ├── special_offers.json
        │       └── site_settings.json
        ├── integrations/
        ├── inquiries/
        └── reservations/

The Git repository does not include private runtime state such as real reservations, admin keys or local JSON data.

## Email / msmtp

CM Free / Plus can send emails through the system mail transport.

The DEB package includes msmtp and msmtp-mta as dependencies, but SMTP credentials still need to be configured for the target system or installation.

After setup, verify that sendmail compatibility exists:

    which sendmail
    php -i | grep -i sendmail_path

## ICS and channel workflows

CM Free / Plus includes ICS-oriented workflows for calendar interoperability.

The project is designed around a practical separation:

- internal state is managed locally
- confirmed reservations / hard locks can be exported
- soft holds and unconfirmed internal states should not be exported as confirmed external availability

This keeps the system safer when working with external calendars and booking platforms.

### Double-booking safety on CM Free

CM Free never auto-confirms an external inquiry — every request (direct or from a connected ICS channel) requires your manual review before it becomes a confirmed reservation. Because of this, CM Free reflects your true local calendar at the moment you confirm, even though external channel calendars sync every 15 minutes rather than instantly.

To keep this guarantee airtight, we recommend turning off "Instant Book" on Booking.com/Airbnb when using CM Free. This way, a request there also waits for your confirmation — giving you the same safety window on both sides, instead of racing an automatic OTA confirmation against a 15-minute-old local calendar.

Want bookings to confirm automatically on all channels, without manual review? CM Plus's Autopilot does exactly that — before every automatic confirmation, it forces a fresh pull from all connected channels and will refuse to auto-confirm rather than risk a conflict.

## Help center

The package includes an internal help center for users and installers.

Important admin pages include contextual help and guide support so the system can be used in an “AnyDesk mode” setup, where a remote helper can install, configure or explain the system to a new user.

## License and editions

CM Free is released under the GNU Affero General Public License v3.0 or later (AGPL-3.0-or-later).

Copyright © 2026 Viljem Dvojmoč.

This public repository contains the CM Free distribution source.

CM Plus upgrade packages, private customer-specific upgrade material, and CM PRO are separate editions and are not included in this repository or license unless explicitly stated.

CM Plus may be provided as a separate upgrade package. CM PRO is a separate commercial/professional edition and is not a module bundle or automatic upgrade from CM Free.

SPDX-License-Identifier: AGPL-3.0-or-later

## Project status

CM Free / Plus v1.3.1 is a practical release candidate for testing and real-world feedback.

It is currently suitable for:

- demos
- sandbox installs
- small self-hosted tests
- early adopters
- small accommodation providers who want a lightweight local-first reservation tool

For production use, always test the full flow first:

- first-use setup
- public calendar
- inquiry submission
- admin acceptance
- reservation confirmation
- email sending
- ICS import/export behavior
- backups

## Public project links

- Demo: http://cmfree.duckdns.org/app/
- Download page: https://apartmamatevz.si/cmfree/download.html
- Journal story: https://apartmamatevz.si/journal/posts/2026-05-12-cm-freeplus-rezervacijski-sistem.html

## Notes

This project started as a practical tool for a small independent accommodation business and is developed with real-world testing in mind.

The goal is not to replace large commercial channel managers, but to provide a small, understandable and self-hosted alternative for simple use cases.

## Version

Current release:

    CM Free / Plus v1.3.1
