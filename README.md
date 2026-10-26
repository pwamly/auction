# NDC Auction — Online Auction Module

Timed online auctions for NDC assets: public catalogue, participant registration and bidding,
and a staff back office for approval, winner selection, payment tracking, reports and audit.
PHP 8 + MariaDB/MySQL on XAMPP, no framework, no Composer.

Requirements and every decision taken on them: [docs/REQUIREMENTS_TRACKER.md](docs/REQUIREMENTS_TRACKER.md).

## Install

1. Start Apache and MySQL in XAMPP.
2. Create the database and the first administrator:

   ```bash
   /opt/lampp/bin/php /opt/lampp/htdocs/auction/setup.php --admin-email=you@ndc.go.tz --admin-name="Your Name" [--demo]
   ```

   A temporary password is printed. At first login the administrator sets up two-factor
   authentication (authenticator app) and chooses a new password. `--demo` adds three sample auctions.
   Re-running `setup.php` is safe and applies schema changes.
3. Let the web server write uploads and mail (Apache runs as `daemon` on XAMPP Linux):

   ```bash
   setfacl -R -m u:daemon:rwX,d:u:daemon:rwX storage/uploads storage/mail
   ```

4. Add the scheduler to cron (`crontab -e`). It opens and closes auctions, flags overdue payments and sends email:

   ```
   * * * * * /opt/lampp/bin/php /opt/lampp/htdocs/auction/cron.php >> /opt/lampp/htdocs/auction/storage/cron.log 2>&1
   ```

   Without cron the same work runs at most every 15 seconds when someone visits the site, so it still works, just less promptly.
5. Open http://localhost/auction/ (public) and http://localhost/auction/admin/ (staff).

## Configuration

`config.php` reads environment variables, so secrets don't need to be in the file:

| Variable | Default | Purpose |
|---|---|---|
| `NDC_AUCTION_APP_URL` | `http://localhost/auction` | Public URL (used in emails) |
| `NDC_AUCTION_DB_HOST/PORT/NAME/USER/PASS` | `127.0.0.1` / 3306 / `ndc_auction` / `root` / empty | Database |
| `NDC_AUCTION_MAIL_TRANSPORT` | `log` | `log` writes emails to `storage/mail/`; `smtp` sends them |
| `NDC_AUCTION_SMTP_HOST/PORT/SECURE/USER/PASS`, `NDC_AUCTION_MAIL_FROM` | | SMTP server (`SECURE` = `tls`, `ssl` or `none`) |

Auction rules (late-bid extension, payment window, default increment, upload limits) are constants in `config.php`.
Staff roles: Administrator, Auction Officer, Manager. The administrator sets what the other two may do under Staff accounts → Access privileges (stored in `role_permissions`; labels and defaults in `lib/auth.php`).

## Layout

| Path | Purpose |
|---|---|
| `index.php`, `auction.php`, `results.php`, `help.php` | Public catalogue, auction page (sub-pages: details, documents, terms, bid history) with live bidding, published results, how-to-bid guide |
| `register.php`, `login.php`, `two_factor.php`, `account.php`, `notifications.php` | Account pages; `account.php` has sub-pages for bids, wins & payments, profile |
| `api.php` | JSON for the live bid panel (state + place bid) |
| `file.php` | Serves photos/documents from `storage/uploads` with access checks |
| `admin/` | Back office: dashboard, auctions, auction editor & workflow page, participants, staff, reports |
| `lib/auctions.php` | Auction lifecycle, bidding rules, closing, winner & payment workflow |
| `lib/tasks.php`, `cron.php` | Scheduler |
| `lib/auth.php`, `lib/totp.php` | Roles, permissions, login, lockout, two-factor |
| `lib/notify.php`, `lib/mailer.php` | In-app notifications, email outbox, SMTP |
| `lib/audit.php`, `lib/reports.php` | Audit trail, the 8 management reports |
| `schema.sql`, `setup.php` | Database |
| `storage/` | Uploads, emails (log mode), backups — not web-accessible |
| `storage/backups/` | The v1 prototype code and its database dump |

The htdocs-level `.htaccess` (shared with the `ndc` app) strips `.php` from URLs, so links in this module are extensionless (`auction?id=3`).

## Interface

- White layout with NDC green and gold; navigation is a left sidebar (a slide-out drawer on phones).
- Long screens are split into sub-pages (tabs), e.g. the staff auction page: Overview, Bids, Winner & payment, Activity.
- Every action form (approve, return, suspend, extend, cancel, winner and payment decisions, staff and participant changes, bid confirmation) opens as a pop-up (`<dialog>`), built with `modal_start()` / `modal_end()` in `lib/layout.php` and opened by any button with `data-open="<dialog id>"`.

## How an auction runs

1. An Auction Officer, Manager or Administrator creates a draft and submits it.
2. An Administrator approves it (published) or returns it with a reason.
3. Bidding opens at the opening time; all participants are notified.
4. Participants accept the auction's terms, then bid. Each bid must beat the current one by the minimum increment. A bid in the last 20 minutes extends closing to 20 minutes after that bid.
5. At closing: no bids or reserve not reached → not sold, and a repeat draft is created. Otherwise the highest bidder awaits approval.
6. Investment Unit, Manager or Administrator approves the winner (or rejects with a reason → next highest bidder).
7. The winner has 48 hours to pay through GePG. Staff record the control number and then the receipt.
8. If payment is late, management chooses: offer to the next bidder, repeat the auction, or give more time.

Every step is in the audit trail and triggers in-app + email notifications.

## Before going live

- Use HTTPS (session cookies become Secure automatically).
- Use a dedicated database user with a password instead of `root`.
- Configure SMTP and set `NDC_AUCTION_MAIL_TRANSPORT=smtp`.
- Install the cron job.
- Integrate GePG (needs NDC's GePG registration and API documents).
