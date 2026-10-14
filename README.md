# silvershop/abandoned-cart

Abandoned-cart recovery for [SilverShop](https://github.com/silvershop/silvershop-core). It detects carts left
behind, sends a short, timed recovery-email sequence, and restores the cart from a one-click tokenised link — with a
**self-contained snapshot** so recovery works even after a cart is cleaned up.

Native and out-of-the-box: no ESP account required. Every scheduled send is visible and auditable in
`QueuedJobAdmin`, every email can be logged by `silvershop/notification-log`, and recovered revenue is reported in the
CMS.

## Requirements

- `silverstripe/framework ^6.0`
- `silvershop/core ^6.2` — provides the shared email shell (`OrderEmailHeader` / `OrderEmailFooter`) the recovery
  email wraps itself in.
- `symbiote/silverstripe-queuedjobs ^6.0` — all scheduling is queued jobs, not opaque OS cron.

PHP 8.3 / 8.4.

## Install

```bash
composer require silvershop/abandoned-cart
```

Then `vendor/bin/sake db:build --flush` (or `dev/build?flush=1`), and arm the recurring scan once:

```bash
vendor/bin/sake tasks:SilverShop-AbandonedCart-Task-SendAbandonedCartRecoveryTask --schedule
```

## How it works

1. **Detection.** A cart in SilverShop *is* an `Order` with `Status = 'Cart'`. The recurring
   `ScanAbandonedCartsJob` finds carts whose `LastEdited` is older than the first step delay (and newer than a
   `max_age_mins` floor), that have at least one line item and a known customer email
   (`Order::getLatestEmail()` — a member's address, or a guest's once they entered it at checkout).

2. **Snapshot.** For each newly-eligible cart a `CartRecovery` row is written with a unique token, the recipient
   email, and a **JSON snapshot** of the cart's reconstructable identity (per line: buyable class/ID, quantity, and
   title/price for display). Recovery never depends on the live cart surviving.

3. **Sequence.** Each `CartRecovery` is sent up to three recovery emails on a configurable cadence (default
   **1 h / 24 h / 72 h**), one per due step, via a per-cart `SendCartRecoveryJob`. Sends are **claimed before
   sending** (the step is written *before* the email goes out), so a crashed or retried job never double-sends. The
   sequence stops the moment the cart is placed, emptied, unsubscribed, or fully sent.

4. **Restore.** The email's "Return to your cart" button hits `cart-recovery/resume/<token>`, which:
   - **live-order fast path** — if the original cart still exists and is still a cart, it is set as the visitor's
     current cart exactly (core reprices and prunes anything no longer purchasable); otherwise
   - **snapshot rebuild** — a fresh cart is rebuilt from the snapshot at **current price and stock**, skipping any
     product that is gone or unavailable (surfaced as "no longer available"). The snapshot price is display-only and
     is never used to sell.

5. **Attribution.** The resume click is stamped; when the order is subsequently placed, an `onPlaceOrder` hook marks
   the `CartRecovery` **Recovered** with the order value. The `RecoveredCartsReport` shows recovered count + value by
   period — the attribution core's count-only `AbandonedCartReport` lacks.

## Consent / lawful basis (GDPR, verify with your DPO)

Recovery email is marketing-adjacent, so the lawful basis is configurable. **This is a legal posture shipped with a
safe default — verify it with legal/DPO for your jurisdiction.**

```yaml
SilverShop\AbandonedCart\Service\CartRecoveryService:
  consent_mode: 'legitimate_interest' # or 'require_consent'
```

- **`legitimate_interest`** (default) — send to recipients who gave their email in the checkout flow (a
  pre-contractual step) or who are members, always with one-click unsubscribe in every email and our own suppression
  list (an unsubscribed row is never mailed again). A defensible legitimate-interest / soft-opt-in posture.
- **`require_consent`** — only send when the address has an explicit `NewsletterConsent` opt-in (requires
  `silvershop/newsletter`). With a strict counsel, flip this on.

Every email carries a one-click unsubscribe regardless of mode.

## Retention / GDPR

The `CartData` snapshot and the recipient email are personal data. When a row goes terminal (Recovered / Unsubscribed
/ Stopped / Completed) its **token is nulled immediately** (killing the live resume link), and the row is deleted
`retention_days` (default 30) after it ends. The recovery email is logged by `silvershop/notification-log` under the
**Marketing** category (key `cart.recovery`), so it participates in that module's split-retention and data-erasure
flows.

## The CartCleanupTask window (now optional)

Core's `CartCleanupTask` hard-deletes `Cart` orders older than `delete_after_mins` (default 2 hours). **Because
recovery is snapshot-based, this is no longer a blocker** — a deleted cart is rebuilt from the snapshot. Raising
`CartCleanupTask.delete_after_mins` above your sequence only keeps the exact live-order fast path available longer; it
is a nice-to-have, not a prerequisite.

## Configuration

```yaml
SilverShop\AbandonedCart\Service\CartRecoveryService:
  steps: [60, 1440, 4320]        # minutes after abandonment per recovery step
  consent_mode: 'legitimate_interest'
  max_age_mins: 20160            # ignore carts older than this (14 days); 0 disables the floor
  max_per_run: 100               # cap per inline CLI pass
  retention_days: 30             # delete terminal rows this long after they end
  attribute_on_place_without_click: false
  from_email: ''                 # falls back to store-profile / SiteConfig / admin email

SilverShop\AbandonedCart\Job\ScanAbandonedCartsJob:
  scan_interval_mins: 30         # the recurring scan re-queues itself this far ahead
```

Run the sequence from cron without a worker (dry-run by default):

```bash
vendor/bin/sake tasks:SilverShop-AbandonedCart-Task-SendAbandonedCartRecoveryTask --run
```

## Soft dependencies

All guarded — the module works standalone and lights these up when present:

- `silvershop/newsletter` — the `require_consent` gate (and the v2 ESP-export seam).
- `silvershop/notification-log` — logs + categorises each recovery email and provides the erasure seam.
- `silvershop/store-profile` — shop identity for the sender name/address.
- `silvershop/order-confirmation` / `silvershop/order-admin` — shared token idiom + admin surfaces.
- `silvershop/discounts` — v2 incentive coupons.

## Roadmap (v2)

ESP-export delivery seam (push cart-abandoned / cart-recovered events to the newsletter provider registry); a
discount incentive injected into later emails; A/B timing; open-tracking pixel; a core `updateCartCleanupQuery`
extension hook so carts with an active recovery are spared; product thumbnails in the email.

## License

BSD-3-Clause.
