# Online Auction Module — requirements tracker

Source: `Requirements_doc.docx` (NDC questionnaire, received 2026-10-19).
Reading rule agreed with the developer: where one option is left, that option is the answer; where every option in a list is left, all of them apply.

Legend: ✅ built · 🟡 built with an assumption the client should confirm · ⏳ needs client input / external setup

## A. Auction creation & approval

| Q | Requirement | Status | How it works |
|---|---|---|---|
| 1 | Capture name, description, photos, quantity, location, minimum/reserve price, opening & closing date/time, terms & conditions, supporting documents | ✅ | All fields on the auction form. Photos: JPG/PNG/WebP ≤ 5 MB, up to 10. Documents: PDF/Word/Excel/images ≤ 10 MB, up to 10. Reserve price is optional and never shown to bidders (they only see "reserve met / not yet met"). |
| 2 | Administrator, Auction Officer and Manager can create auctions | ✅ | Roles `admin`, `auction_officer`, `manager`. |
| 3 | Auctions approved by the Administrator before publishing | ✅ | Draft → submit → administrator approves (published) or returns with a reason. |

## B. Participants

| Q | Requirement | Status | How it works |
|---|---|---|---|
| 4 | Individuals and companies | ✅ | Chosen at registration. |
| 5 | Full name, phone, email, NIDA, TIN, company name, BRELA, address | 🟡 | Individuals: NIDA (20 digits) required, TIN optional. Companies: company name, TIN (9 digits) and BRELA number required; full name is the contact person. Phone stored as +255…. **Confirm which fields are mandatory for each type.** |
| 6 | No verification before bidding | ✅ | Participants can bid right after registering. Administrators can still block/reactivate an account (with a reason) — see C1 below. |

## C. Fees & conditions

| Q | Requirement | Status | How it works |
|---|---|---|---|
| 8 | No registration fee / deposit / bid security for now | ✅ | None taken. |
| 9 | Accept terms & conditions before bidding | ✅ | Each auction has its own terms; a participant must tick acceptance before their first bid on it. Acceptance is stored with time and IP and appears in the audit trail. |

## D. Bidding

| Q | Requirement | Status | How it works |
|---|---|---|---|
| 10 | Starting price set by NDC | ✅ | Entered by staff on the auction. |
| 11 | Minimum increment, "5" | 🟡 | Increment is set per auction and **defaults to TZS 5**. **Confirm whether "5" means TZS 5, 5 %, or a per-auction amount.** |
| 12 | Unlimited bids | ✅ | |
| 13 | Bids cannot be edited or withdrawn | ✅ | No edit/withdraw function exists. A participant cannot outbid themselves. |
| 14 | Show current highest bid, number of bids, number of bidders, bidder name | 🟡 | Shown live (refreshes every 3 s). **Bidder names are shown to logged-in users only**; anonymous visitors see the amounts and counts. Companies are shown by company name. |

## E. Closing

| Q | Requirement | Status | How it works |
|---|---|---|---|
| 15 | Close automatically at the specified time | ✅ | Bids are refused from the closing second (server clock). The closing is processed by the scheduler (cron, or automatically on page visits). |
| 16 | Late bid extends time by 20 minutes | 🟡 | A bid placed in the **last 20 minutes** moves closing to **bid time + 20 minutes**, with no limit on repeats. Both numbers are in `config.php`. **Confirm the "shortly before closing" window.** |

## F. Winner

| Q | Requirement | Status | How it works |
|---|---|---|---|
| 17 | Highest bidder automatically becomes the winner | ✅ | At closing, the highest bid (if it meets the reserve) becomes the provisional winner. |
| 18 | No evaluation of the highest bid | 🟡 | No price evaluation; see Q19. |
| 19 | Winner verified/approved by Investment Unit, Management, Admin | 🟡 | **Any one** user with role Investment Unit, Manager or Administrator approves (or rejects with a reason). **Confirm whether one approval is enough or all three are needed in sequence.** |
| 20 | If the winner fails conditions, offer to the second-highest bidder | ✅ | Rejecting the winner offers the item to the highest remaining bidder (their own highest bid, if it meets the reserve), who then also needs approval. If nobody is left, the item is unsold and repeated. |

## G. Notifications

| Q | Requirement | Status | How it works |
|---|---|---|---|
| 21 | Email and website/user account | ✅ / ⏳ | Every notification appears in the user's Notifications page and is emailed. **Email needs NDC's SMTP server details** (`LOTLINE_SMTP_*` settings); until then emails are written to `storage/mail/`. |
| 22 | Registration, participant approval, opening, bid submitted, outbid, closing, winning, unsuccessful bid | 🟡 | All sent. Opening notices go to **all active participants**. "Participant approval" is sent when an administrator reactivates a blocked account (there is no approval step, per Q6). Staff also get notices: auction awaiting approval, winner awaiting approval, payment overdue. |

## H. Payment

| Q | Requirement | Status | How it works |
|---|---|---|---|
| 23 | 48 hours to pay | ✅ | The deadline starts when the winner is approved (that is when they are told they won). |
| 24 | GePG | ⏳ | **Not integrated yet** — needs NDC's GePG registration (SP code, keys) and the GePG API specification. For now staff enter the GePG control number (sent to the winner) and record the receipt number when paid. |
| 25 | If the winner doesn't pay: second-highest bidder / repeat / forfeit deposit / management decision | 🟡 | After 48 h the auction becomes "Payment overdue" and management decides: **offer to the next bidder**, **repeat the auction**, or **give more time**, with a reason. "Forfeit deposit" is not applicable while no deposit is taken (Q8). |

## I. Results & reports

| Q | Requirement | Status | How it works |
|---|---|---|---|
| 26 | Publish winning price, winner's name, item, number of participants | 🟡 | Public **Results** page. Published once the winner is approved (not at closing, so a rejected bidder is never shown as the winner). **Confirm the timing.** |
| 27 | Auction summary, participants, bidding history, winners, payments, cancelled auctions, revenue, audit trail | ✅ | Reports tab, each with date filters, search and CSV export. Audit trail limited to Administrator, ICT and Manager. |

## J. Cancellation & exceptions

| Q | Requirement | Status | How it works |
|---|---|---|---|
| 28 | Administrator can suspend, cancel or extend | ✅ | Plus resume after suspension. |
| 29 | Reason mandatory and recorded | ✅ | Required for suspend, resume, extend, cancel, returning an auction, rejecting a winner, overdue decisions, blocking accounts. Stored on the record and in the audit trail; bidders are told the reason. |
| 30 | Reserve not reached → repeat the auction | ✅ | The auction closes as "Not sold" and a **new draft** with the same details and files is created automatically for staff to reschedule and submit. Also done when there are no bids or no eligible winner. |

## K. Security & audit

| Q | Requirement | Status | How it works |
|---|---|---|---|
| 31 | Audit: logins, registration, participant approval/rejection, auction creation, approval, every bid, changes, cancellation/extension, winner approval, payment status | ✅ | All recorded with user, time, IP and reason. Also: failed logins, logouts, 2FA set-up/reset, staff changes, terms acceptance, automatic extensions, report exports. |
| 32 | 2FA for administrative users | ✅ | All staff roles use an authenticator app (TOTP), set up at first login. ICT/Administrators can reset a lost device. |

## L. Roles

| Q | Requirement | Status | How it works |
|---|---|---|---|
| 33 | Admin side for ICT, PR (and others) | 🟡 | Staff roles: Administrator, Auction Officer, Manager, Investment Unit, ICT, PR. |
| 34 | Responsibilities per unit (left blank) | ⏳ | **Proposed** permissions (visible under Back office → Staff): <br>• Administrator — everything <br>• Auction Officer — create/edit auctions, reports <br>• Manager — create/edit auctions, approve winners & overdue decisions, reports, audit trail <br>• Investment Unit — approve winners, record payments, reports <br>• ICT — manage staff accounts & 2FA resets, reports, audit trail <br>• PR — reports only <br>**Client to confirm.** Defined in `lib/auth.php` → `PERMISSIONS`. |

## Contradictions in the questionnaire and how they were resolved

1. **Q6 (no verification) vs Q22/Q31 (participant approval/rejection)** — no approval step; administrators can block/reactivate, which is notified and audited.
2. **Q8 (no deposit) vs Q25 (forfeit deposit)** — no deposit; the option is not shown.
3. **Q17/Q18 (automatic winner, no evaluation) vs Q19 (approval by three units)** — highest bidder is the automatic *provisional* winner; one approval by any of the three units confirms it.
4. **Q25 (all four options kept)** — treated as "management decision" with offer-to-next / repeat / more time as the choices.

## Other open items

- **Integration with the NDC portal** (`/ndc`): the auction module has its own logins. Should staff use their NDC portal accounts instead?
- **Password reset by email** for participants (needs SMTP).
- **Closed lots on the catalogue**: open and upcoming auctions are listed; finished ones move to Results.
- **Hosting**: HTTPS, a dedicated database user, the cron job, and SMTP before going live (see README).
