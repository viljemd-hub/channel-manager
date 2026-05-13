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
4. Open /app/admin/.
5. Complete the first-use setup.

Example target path:

    /var/www/html/app

Then open:

    http://your-domain.example/app/admin/

### Option 2: DEB / Ubuntu-Debian install

Use the clean DEB package for Ubuntu/Debian based systems.

Example:

    sudo apt update
    sudo apt install ./cmfree_clean_amd64_1.2.1.deb

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

## Help center

The package includes an internal help center for users and installers.

Important admin pages include contextual help and guide support so the system can be used in an “AnyDesk mode” setup, where a remote helper can install, configure or explain the system to a new user.

## Project status

CM Free / Plus v1.2.1 is a practical release candidate for testing and real-world feedback.

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

    CM Free / Plus v1.2.1
