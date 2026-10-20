# Lotline — open questions for the client

Version 1 was built from the client mockup (`docs/mockup-original.html`) as-is.
Each question below needs a client decision. **Interim behaviour** is what v1 does today, so it can be demoed without blocking.

Status: ☐ open · ☑ answered (record the answer and date)

## A. Must clarify (affects the core design)

| # | Question | Interim behaviour in v1 |
|---|----------|--------------------------|
| A1 ☐ | **Open or sealed bidding?** The tagline says "Sealed-ledger bidding", but the mockup shows the current bid, the leader and the full bid history to everyone (open auction). | Open auction, as the mockup shows. The tagline text is unchanged. |
| A2 ☐ | **Anti-sniping:** should a late bid extend the closing time (e.g. +2 min if a bid lands in the last 2 min)? | No extension. The lot closes at its fixed end time. |
| A3 ☐ | **Reserve price:** a hidden minimum below which the lot doesn't sell? | No reserve. The highest bid at close wins, however low. |
| A4 ☐ | **Scheduled start times:** should lots open at a set date/time? | Lots go live as soon as the admin creates them. |
| A5 ☐ | **Bidder registration and vetting:** email / phone / company required? Admin approval or verification before bidding? | Username + password only, no approval. Anyone can sign up and bid immediately. |

## B. Lots

| # | Question | Interim behaviour in v1 |
|---|----------|--------------------------|
| B1 ☐ | Can lots be **edited** after listing? Cancelled after bids exist? | No editing. Admin can **delete** a lot at any time. Deleting is a soft delete: the lot leaves the floor but stays in Reports. Deleting an open lot cancels it (bids void); deleting a closed lot keeps its sold/unsold result. |
| B7 ☐ | Closed lots stay on the bidder floor and the admin Lots list indefinitely, which will get long. Hide closed lots after some time (e.g. 24 h), now that Reports holds the history? | Closed lots stay listed until an admin deletes them. |
| B2 ☐ | **Photos / attachments** (spec sheets, certificates)? | None. |
| B3 ☐ | Structured fields: quantity, unit (t, L), grade, location, category? | Free-text name + description. |
| B4 ☐ | **Currency** — USD correct? Decimals allowed? | USD. Amounts stored to 2 decimals; the bid box steps in whole dollars. |
| B5 ☐ | Auction lengths: the mockup offers 1/2/5/10 min "(demo)", 1 h, 1 day. What's needed for production (e.g. custom end date)? | Same six options (`LOT_DURATIONS` in `config.php`). |
| B6 ☐ | Bid ceiling / sanity limits (e.g. reject bids more than 10× the current price)? | No ceiling beyond the database limit. |

## C. Bidders

| # | Question | Interim behaviour in v1 |
|---|----------|--------------------------|
| C1 ☐ | Should bidders see **each other's usernames** in the bid history, or should they be anonymised ("Bidder #4")? | Usernames are visible to all logged-in users, as in the mockup. |
| C2 ☐ | **Password reset** / forgot password? (Needs email.) | Not available. |
| C3 ☐ | "**My bids**" / "Lots I won" page? | Not available. Bidders see all lots on one page. |
| C4 ☐ | Can a bidder **retract** a bid? | No. |

## D. After the auction

| # | Question | Interim behaviour in v1 |
|---|----------|--------------------------|
| D1 ☐ | What happens when a lot is won: email winner/admin, invoice, payment deadline, what if the winner doesn't pay? | Only the on-screen "X wins this lot" banner. |
| D2 ☐ | **Notifications** (email/SMS) when outbid or a lot is ending? | None. Page refreshes bids every 3 seconds. |

## E. Admin

| # | Question | Interim behaviour in v1 |
|---|----------|--------------------------|
| E1 ☐ | More than one admin? Admin user management (block/delete bidders, reset passwords)? | One admin account, created by `setup.php`. No user management screen. |
| E2 ◐ | Reports / exports — **partly done 2026-10-19** (raised by the developer, not yet confirmed by the client). Still open: which other reports does the client want (per-bidder history, full bid log per lot, revenue by month, PDF)? | Admin **Reports** tab: totals (finished, sold, unsold, cancelled, total sold value, bids), a results table filterable by finish date, outcome and product/winner search, and CSV export. |
| E3 ☐ | Should admins be able to bid? | No — the server rejects admin bids. |

## F. Decisions made by the developer (tell the client)

These differ from the mockup on purpose; confirm the client is OK with them.

- Passwords are **hashed** (the mockup stored plain text) and the minimum is **8 characters** (mockup: 4).
- Demo admin credentials are **no longer printed** on the login screen.
- Login errors say "Incorrect username or password" without revealing which one was wrong.
- Lot numbers ("LOT 001") are the permanent database id, not the position in the list, so a lot keeps its number.
- Closing times use the **server clock**. Bids are validated on the server with row locking, so two simultaneous bids can't both win.

## G. Technical to-do before going live

- Change the admin password (`setup.php` only sets it on first run — reset it in the database or re-create the user).
- Use a dedicated MySQL user with a password instead of `root` (env vars in `config.php`).
- Serve over HTTPS (the session cookie is marked Secure automatically when HTTPS is on).
- Add login rate limiting / lockout against password guessing.
- Live updates are polling every 3 s; for many concurrent users consider Server-Sent Events or WebSockets.
- The htdocs-root `.htaccess` (shared with the `ndc` app) redirects `*.php` GET URLs to extensionless ones; the front end calls `api` (no extension) because of this. If that root file changes, check the API still works.
