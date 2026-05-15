<?php
/**
 * CM Free / CM Plus – Channel Manager
 * File: admin/help.php
 */

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_key();
?>
<!doctype html>
<html lang="sl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>CM Help / User Guide</title>

  <link rel="stylesheet" href="/app/admin/ui/css/helpers.css">
  <link rel="stylesheet" href="/app/admin/ui/css/layout.css">
  <link rel="stylesheet" href="/app/admin/ui/css/header.css">

  <style>
    .help-root{
      max-width:1100px;
      margin:0 auto;
      padding:24px;
    }
    .help-hero{
      border:1px solid rgba(255,255,255,.12);
      background:rgba(11,33,66,.45);
      border-radius:18px;
      padding:22px;
      margin-bottom:18px;
    }
    .help-hero h1{
      margin:0 0 8px;
      font-size:28px;
    }
    .help-hero p{
      margin:0;
      opacity:.85;
      line-height:1.5;
    }
    .help-grid{
      display:grid;
      grid-template-columns:repeat(auto-fit,minmax(240px,1fr));
      gap:14px;
      margin:18px 0;
    }
    .help-card{
      border:1px solid rgba(255,255,255,.12);
      background:rgba(10,26,51,.75);
      border-radius:16px;
      padding:16px;
    }

    .help-card-link{
      display:block;
      color:inherit;
      text-decoration:none;
      cursor:pointer;
      transition:transform .08s ease, border-color .2s ease, background .2s ease;
    }
    .help-card-link:hover{
      transform:translateY(-1px);
      border-color:rgba(87,166,255,.35);
      background:rgba(11,33,66,.9);
    }
    .help-card h2{
      margin:0 0 8px;
      font-size:18px;
    }
    .help-card p,
    .help-card li{
      font-size:14px;
      line-height:1.5;
      opacity:.88;
    }
    .help-card ul{
      margin:8px 0 0 18px;
      padding:0;
    }
    .help-section{
      margin-top:22px;
      border-top:1px solid rgba(255,255,255,.12);
      padding-top:18px;
      scroll-magin-top:90px;
    }
    .help-section h2{
      margin:0 0 10px;
    }
    .help-section h3{
      margin:18px 0 6px;
      font-size:16px;
    }
    .help-section p,
    .help-section li{
      line-height:1.55;
      opacity:.88;
    }
    .help-section a{
      color:#8fd1ff;
      text-decoration:underline;
      text-underline-offset:3px;
    }
    .help-actions{
      display:flex;
      flex-wrap:wrap;
      gap:10px;
      margin-top:14px;
    }
    .help-note{
      border-left:4px solid #57a6ff;
      background:rgba(87,166,255,.08);
      padding:12px 14px;
      border-radius:12px;
      margin:14px 0;
      font-size:14px;
      line-height:1.5;
    }
    .help-warning{
      border-left:4px solid #ffbf57;
      background:rgba(255,191,87,.08);
      padding:12px 14px;
      border-radius:12px;
      margin:14px 0;
      font-size:14px;
      line-height:1.5;
    }
    .help-code{
      display:block;
      white-space:pre-wrap;
      overflow:auto;
      border:1px solid rgba(255,255,255,.12);
      background:rgba(0,0,0,.28);
      border-radius:14px;
      padding:12px 14px;
      margin:10px 0 14px;
      color:#eaf2ff;
      font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;
      font-size:13px;
      line-height:1.45;
    }
    .inline-code{
      display:inline-block;
      padding:1px 6px;
      border:1px solid rgba(255,255,255,.12);
      background:rgba(0,0,0,.22);
      border-radius:7px;
      font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;
      font-size:.92em;
    }
    
        html{
      scroll-behavior:smooth;
      background:#050b16;
    }

    body.adm-shell.theme-dark{
      background:
        radial-gradient(circle at top left, rgba(47,139,255,.10), transparent 34rem),
        #050b16;
      color:#e8eef8;
      min-height:100vh;
    }

    .adm-header{
      background:#061112;
      border-bottom:1px solid rgba(47,139,255,.35);
      box-shadow:0 10px 30px rgba(0,0,0,.22);
    }

    .help-root{
      color:#e8eef8;
    }

    .help-hero{
      background:linear-gradient(135deg, rgba(8,28,54,.94), rgba(5,18,34,.94));
      border-color:rgba(47,139,255,.32);
      box-shadow:0 18px 44px rgba(0,0,0,.22);
      color:#e8eef8;
    }

    .help-card{
      background:linear-gradient(135deg, rgba(8,25,47,.94), rgba(8,18,34,.94));
      border-color:rgba(80,119,170,.32);
      color:#e8eef8;
      box-shadow:0 10px 26px rgba(0,0,0,.16);
    }

    .help-card h2,
    .help-section h2,
    .help-section h3,
    .help-hero h1{
      color:#f4f8ff;
    }

    .help-card p,
    .help-card li,
    .help-section p,
    .help-section li,
    .help-hero p{
      color:#c7d6ea;
      opacity:1;
    }

    .help-card-link{
      position:relative;
      display:block;
      color:inherit;
      text-decoration:none;
      cursor:pointer;
      transition:
        transform .12s ease,
        border-color .18s ease,
        background .18s ease,
        box-shadow .18s ease;
    }

    .help-card-link:hover{
      transform:translateY(-1px);
      background:linear-gradient(135deg, rgba(10,36,68,.98), rgba(8,24,45,.98));
      border-color:rgba(87,166,255,.55);
      box-shadow:0 14px 34px rgba(0,0,0,.28);
    }

    .help-card-link:focus-visible{
      outline:2px solid rgba(143,209,255,.62);
      outline-offset:3px;
    }

    .help-card-link::after{
      content:"↓";
      position:absolute;
      right:14px;
      top:14px;
      color:#8fd1ff;
      opacity:.38;
      font-size:15px;
      transition:opacity .18s ease, transform .18s ease;
    }

    .help-card-link:hover::after{
      opacity:.85;
      transform:translateY(2px);
    }

    .help-section{
      scroll-margin-top:90px;
    }

    .help-note{
      color:#d8eaff;
      background:rgba(47,139,255,.10);
      border-left-color:#57a6ff;
    }

    .help-warning{
      color:#fff2d5;
      background:rgba(255,191,87,.10);
      border-left-color:#ffbf57;
    }

    .help-code,
    .inline-code{
      color:#eaf2ff;
      background:rgba(0,0,0,.30);
      border-color:rgba(143,209,255,.18);
    }
    .help-root{
  max-width:1200px;
}

.help-hero,
.help-grid,
.help-section{
  max-width:980px;
  margin-left:auto;
  margin-right:auto;
}
  </style>
</head>
<body class="adm-shell theme-dark">
  <header class="adm-header">
    <div class="hdr-left">
      <h1 class="hdr-title">CM Help / User Guide</h1>
    </div>
    <div class="hdr-right">
      <a class="btn small" href="#email">E-mail</a>
      <a class="btn small" href="/app/admin/admin_calendar.php">Calendar</a>
      <a class="btn small" href="/app/admin/manage_reservations.php">Reservations</a>
      <a class="btn small" href="/app/admin/inquiries_admin.php">Inquiries</a>
      <a class="btn small" href="/app/admin/integrations.php">Integrations</a>
    </div>
  </header>

  <main class="help-root">
    <section class="help-hero">
      <h1>Welcome to CM</h1>
      <p>
        CM is a simple self-hosted Channel Manager for small accommodation owners.
        It helps you manage availability, prices, inquiries, reservations and calendar integrations from one place.
      </p>

      <div class="help-actions">
        <a class="btn primary" href="/app/admin/admin_calendar.php">Start with Calendar</a>
        <a class="btn" href="/app/admin/integrations.php">Open Integrations</a>
      </div>
    </section>

    <section class="help-grid">
      <a class="help-card help-card-link" href="#calendar">
        <h2>1. Calendar</h2>
        <p>Your main working screen for availability, prices, blocks and visual overview.</p>
      </a>

      <a class="help-card help-card-link" href="#reservations">
        <h2>2. Reservations</h2>
        <p>Review confirmed, cancelled, external and soft-hold reservations.</p>
      </a>

      <a class="help-card help-card-link" href="#inquiries">
        <h2>3. Inquiries</h2>
        <p>Handle guest requests before they become confirmed reservations.</p>
      </a>

      <a class="help-card help-card-link" href="#integrations">
        <h2>4. Integrations</h2>
        <p>Connect external calendars and prepare the system for real-world sync.</p>
      </a>

      <a class="help-card help-card-link" href="#email">
        <h2>5. E-mail / SMTP</h2>
        <p>Set up msmtp so CM can send guest and admin e-mails through Gmail or another SMTP account.</p>
      </a>
    </section>

    <section class="help-section" id="calendar">
      <h2>Calendar</h2>

      <p>
        The admin calendar is the central view of your unit availability.
        Start here when you want to check dates, select a range, set prices, create blocks or add an admin reservation.
      </p>

      <h3>Basic workflow</h3>
      <ul>
        <li>Select the unit at the top.</li>
        <li>Use previous / next / today to move through months.</li>
        <li>Drag or click dates to select a range.</li>
        <li>Use the action buttons to block, unblock, set price, set offer or create an admin reservation.</li>
      </ul>

      <h3>Layers</h3>
      <p>
        Calendar layers let you decide what you want to see: occupancy, local blocks, prices, offers and pending inquiries.
        This makes the calendar useful both for quick overview and for detailed administration.
      </p>

      <div class="help-note">
        Prices are important: dates without usable price data may not behave as expected in the public calendar or offer flow.
      </div>
    </section>

    <section class="help-section" id="reservations">
      <h2>Reservations</h2>

      <p>
        The Reservations page shows existing reservations across units, years, statuses and sources.
        Use it when you want to inspect confirmed bookings, cancelled reservations, external reservations or soft-holds.
      </p>

      <h3>What to check</h3>
      <ul>
        <li>Unit and date range of the reservation.</li>
        <li>Guest name, email and phone if available.</li>
        <li>Status: confirmed, cancelled, soft-hold, external or ICS.</li>
        <li>Available actions such as cancel or re-send accept link.</li>
      </ul>

      <h3>External reservations</h3>
      <p>
        External reservations are useful for direct bookings or reservations that were created outside the public inquiry flow.
      </p>
    </section>

    <section class="help-section" id="inquiries">
      <h2>Inquiries</h2>

      <p>
        Inquiries are guest requests that have not yet become final reservations.
        This page is where you review requests, accept them, reject them, or mark them for visual tracking.
      </p>

      <h3>Inquiry flow</h3>
      <ul>
        <li>A guest sends an inquiry from the public offer flow.</li>
        <li>The inquiry appears in the admin list.</li>
        <li>You review guest data, dates, nights and price information.</li>
        <li>If you accept it, CM creates a soft-hold and sends the guest a confirmation link.</li>
        <li>When the guest confirms, the reservation becomes a hard reservation.</li>
      </ul>

      <h3>Calendar connection</h3>
      <p>
        Pending and marked inquiries can be reflected on the admin calendar.
        This helps you visually track important requests before they become final bookings.
      </p>
    </section>

    <section class="help-section" id="integrations">
      <h2>Integrations</h2>

      <p>
        Integrations connect CM with external platforms and calendars.
        The main idea is simple: CM can export its availability and import external availability.
      </p>

      <h3>Units and Base URL</h3>
      <p>
        Integrations are configured per unit. The Base URL must point to your public CM installation,
        because it is used to generate external links such as ICS URLs.
      </p>

      <h3>ICS Export</h3>
      <p>
        ICS export links allow external systems to read availability from CM.
        You can copy these links into platforms that support calendar import.
      </p>

      <h3>Channels / ICS Import</h3>
      <p>
        Channels let CM read external calendars.
        Imported external bookings are merged into the internal availability layer.
      </p>

      <h3>Autopilot</h3>
      <p>
        Autopilot is a Plus feature. It can automatically confirm safe inquiries when rules and availability checks pass.
        In CM Free it can be shown as a locked feature, so users understand the upgrade path.
      </p>

      <div class="help-note">
        Soft-holds are internal and should not be exported to external platforms. Confirmed reservations and hard locks are the safe export layer.
      </div>
    </section>

    <section class="help-section" id="email">
      <h2>E-mail / SMTP / Gmail App Password</h2>

      <p>
        CM Free / Plus sends e-mails through the system <span class="inline-code">sendmail</span> interface.
        On Ubuntu/Debian installations this is usually provided by <span class="inline-code">msmtp</span>.
      </p>

      <p>
        The basic chain is:
      </p>

      <pre class="help-code">CM Free / Plus → PHP sendmail → msmtp → Gmail/SMTP server → guest/admin</pre>

      <div class="help-note">
        The DEB package installs <span class="inline-code">msmtp</span> and <span class="inline-code">msmtp-mta</span>.
        You still need to configure the SMTP account.
      </div>

      <h3>1. Check that msmtp is installed</h3>

      <pre class="help-code">which msmtp
which sendmail
php -i | grep -i sendmail_path
ls -l /usr/sbin/sendmail</pre>

      <p>
        A typical working result looks like this:
      </p>

      <pre class="help-code">/usr/bin/msmtp
/usr/sbin/sendmail
sendmail_path => /usr/sbin/sendmail -t -i
/usr/sbin/sendmail -> ../bin/msmtp</pre>

      <h3>2. Gmail: create an App Password</h3>

      <p>
        For Gmail, do not use your normal Gmail password. Use a Gmail <strong>App Password</strong>.
      </p>

      <p>
        Fast link:
        <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener">https://myaccount.google.com/apppasswords</a>
      </p>

      <ol>
        <li>Log in to the Gmail account that will send e-mails.</li>
        <li>Create a new App Password.</li>
        <li>You can name it something like <span class="inline-code">CM Free msmtp</span>.</li>
        <li>Google shows a 16-character password.</li>
        <li>Copy it into <span class="inline-code">/etc/msmtprc</span> without spaces.</li>
      </ol>

      <div class="help-warning">
        If App Passwords are not available, enable 2-Step Verification for the Google account first.
      </div>

      <h3>3. Create system-wide msmtp configuration</h3>

      <pre class="help-code">sudo nano /etc/msmtprc</pre>

      <p>
        Minimal Gmail example:
      </p>

      <pre class="help-code"># CM Free / Plus system-wide msmtp configuration
# Used by Apache/PHP through /usr/sbin/sendmail -> msmtp

defaults
auth           on
tls            on
tls_starttls   on
tls_trust_file /etc/ssl/certs/ca-certificates.crt

account gmail
host smtp.gmail.com
port 587
from nekdo@gmail.com
user nekdo@gmail.com
password TVOJE_16_MESTNO_APP_PASSWORD_BREZ_PRESLEDKOV

account default : gmail</pre>

      <p>
        Replace <span class="inline-code">nekdo@gmail.com</span> with the Gmail account that sends mail.
        Replace the password placeholder with your Gmail App Password.
      </p>

      <div class="help-warning">
        The <span class="inline-code">from</span> and <span class="inline-code">user</span> values should normally be the same Gmail account.
        The password must be the App Password for that same account.
      </div>

      <h3>4. Set safe permissions</h3>

      <p>
        The file contains a password. It should be readable by Apache/PHP, but not public.
      </p>

      <pre class="help-code">sudo chown root:www-data /etc/msmtprc
sudo chmod 640 /etc/msmtprc</pre>

      <h3>5. Test as Apache/PHP user</h3>

      <p>
        This is the most important test, because Apache/PHP usually runs as <span class="inline-code">www-data</span>.
      </p>

      <pre class="help-code">printf "Subject: CM Free mail test\n\nTest from CM Free via msmtp.\n" | sudo -u www-data sendmail nekdo@gmail.com</pre>

      <p>
        If there is no error and the e-mail arrives, the system mail setup is working.
      </p>

      <h3>6. Configure e-mail in CM</h3>

      <p>
        After the system test succeeds, configure e-mail in CM settings.
      </p>

      <pre class="help-code">Email enabled: true
From name: CM Free Demo
From email: nekdo@gmail.com
Admin email: your-admin-address@example.com</pre>

      <p>
        The <span class="inline-code">From email</span> should match the sending account from
        <span class="inline-code">/etc/msmtprc</span>. The <span class="inline-code">Admin email</span>
        is where you want to receive inquiry notifications.
      </p>

      <h3>7. Optional logging</h3>

      <p>
        For a first setup, it is usually better to keep msmtp logging disabled.
        E-mail can work perfectly even if a logfile causes permission problems.
      </p>

      <p>
        Do not add this line unless you know how to set log permissions:
      </p>

      <pre class="help-code">logfile /var/log/msmtp.log</pre>

      <p>
        If you see this together with a successful SMTP status:
      </p>

      <pre class="help-code">smtpstatus=250
exitcode=EX_OK
cannot log ... Permission denied</pre>

      <p>
        then the e-mail was sent successfully. The problem is only logging.
        The simplest solution is to remove or comment out the <span class="inline-code">logfile</span> line.
      </p>

      <h3>8. Common errors</h3>

      <h3>Gmail rejects the password</h3>

      <pre class="help-code">535-5.7.8 Username and Password not accepted</pre>

      <p>Common causes:</p>

      <ul>
        <li>You used the normal Gmail password instead of an App Password.</li>
        <li>The App Password belongs to another Google account.</li>
        <li>The password was copied with spaces.</li>
        <li>The <span class="inline-code">user</span> or <span class="inline-code">from</span> value is wrong.</li>
        <li>2-Step Verification is not enabled.</li>
      </ul>

      <h3>No default account</h3>

      <p>
        If <span class="inline-code">sendmail</span> does not know which account to use, make sure this line exists at the end of
        <span class="inline-code">/etc/msmtprc</span>:
      </p>

      <pre class="help-code">account default : gmail</pre>

      <h3>Short version</h3>

      <pre class="help-code">1. Create Gmail App Password:
   https://myaccount.google.com/apppasswords

2. Create /etc/msmtprc with account gmail and account default : gmail

3. Set permissions:
   sudo chown root:www-data /etc/msmtprc
   sudo chmod 640 /etc/msmtprc

4. Test:
   printf "Subject: CM Free mail test\n\nTest.\n" | sudo -u www-data sendmail nekdo@gmail.com</pre>
    </section>

    <section class="help-section" id="first-use">
      <h2>Recommended first setup</h2>

      <ol>
        <li>Open Calendar and learn the unit selector, date selection and layers.</li>
        <li>Add or check prices for your first unit.</li>
        <li>Open Reservations and understand where confirmed bookings appear.</li>
        <li>Open Inquiries and review how guest requests become reservations.</li>
        <li>Open Integrations and set your Base URL.</li>
        <li>Set up E-mail / SMTP if you want CM to send guest and admin messages.</li>
        <li>Copy ICS export links only after the system URL is correct.</li>
        <li>Add another unit only after the first unit is clear and working.</li>
      </ol>
    </section>
  </main>
</body>
</html>
