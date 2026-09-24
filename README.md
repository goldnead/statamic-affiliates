# Statamic Affiliates

A partner programme for Statamic 6. Partners send buyers through a link or their own coupon code,
earn a commission per product (on the first payment, on subscription renewals, on order bumps and
upsells), refunds take it back, a hold period keeps it until the withdrawal period is over, and a
payout list says who is owed what. Joint-venture contracts share the revenue of chosen products
with a partner without any link at all.

The addon moves no money. It keeps the books; you pay by bank transfer or PayPal from the CSV and
mark the list as paid.

Commercial.

## Requirements

- PHP 8.2+, Laravel 12.40+ or 13, Statamic 6
- goldnead/statamic-brand-context 1.14+ (brand scoping, per-brand settings with their own tab,
  sender identity)
- goldnead/statamic-payments 1.24+ for anything to be attributed (optional, `suggest`)
- goldnead/statamic-offers for partner coupons (optional; nothing of offers is read but the code
  on the payment)
- goldnead/statamic-consent to ask before the referral cookie is written (optional)

## Install

```bash
composer require goldnead/statamic-affiliates
php artisan migrate
php artisan affiliates:install   # the promotional material collection
```

Add a service with the handle `affiliates` to `config/statamic-consent.php` if you use
statamic-consent, so the banner can ask for it. Put the partner area on a page:

```antlers
{{ affiliates:dashboard }}
```

## How attribution works

The hard part of a partner programme is not the commission but the question *whose sale is this?*
It is answered from two places, and never from a field the buyer could fill in:

1. **The link.** Any page of the site with `?ref={code}` (or `/!/affiliates/go/{code}?to=/path`
   for places that cannot carry a query string). A middleware in the `web` group notes the partner
   in an encrypted cookie, **only with consent**, and in the visitor's existing session for the
   rest of the visit. When statamic-payments' checkout creates the payment in that same visitor's
   request, the referral is written down against the payment id.
2. **The coupon.** A partner can own offers coupon codes. When the paid payment carries one
   (`discount_code`, or `meta.coupon.code`), the sale is the partner's, cookie or not. By default
   the coupon beats another partner's link (`commissions.coupon_wins`).

`PaymentPaid` then books the commission. Renewals and follow-up offers inherit the referral of the
payment they follow; a cookie the buyer carries today does not re-attribute them.

| Setting | Default | |
|---|---|---|
| `tracking.cookie_days` | 30 | How long a click counts, from the click. 0: only the visit. |
| `tracking.attribution` | `last` | `first` keeps the first partner while the cookie lives. |
| `consent.mode` | `auto` | `auto` asks statamic-consent (no addon, no cookie), `always`, `never`. |
| `consent.session_fallback` | `true` | Without consent, remember the click in the existing session. |

Clicks are counted once per visit, with the landing path only: no IP address, no user agent.

**Static caching.** With Statamic's full static caching (`strategy: full`) a cached page is served
by the web server without PHP, so `?ref=` on it is never seen. Point partner links at
`/!/affiliates/go/{code}?to=/page` instead: a dynamic route, always handled by PHP, which sets the
cookie before redirecting. Alternatively add a web-server rule that bypasses the static cache for
URLs carrying `ref=`. The half-measure strategy runs PHP on every request and needs nothing.

## Commissions

Per product under **Affiliates → Commission Rates**, otherwise the default from the settings:

- percent of the net amount, or a fixed amount once per sale;
- renewals: none, the first *n*, or all, with their own percentage if wanted. "The first *n*"
  counts paid renewals that were not refunded; a plan switch earns within that period but does
  not use up a renewal;
- as an order bump and as an upsell, each switched on per product, bumps with their own percentage;
- a partner can have an own percentage. By default it replaces only the main rate of a sale (and
  of an upsell); bump and renewal rates stay the product's. `commissions.partner_rate: all` makes
  it replace every rate.

Net is the amount paid, less `commissions.vat_percent` (0 for prices without VAT). Partners do not
earn on purchases with their own address unless `commissions.self_referral` is on.

A new commission waits `commissions.hold_days` (30) before it is payable. A refund inside that
time reverses it; a partial refund takes back the same share. A refund or chargeback after the
commission was paid out becomes a negative row the next payout list deducts.

## Joint ventures

**Affiliates → JV Contracts**: a partner, the products (or all), a percentage of the net amount,
separate percentages for bumps and upsells, whether renewals count, and a term. Every covered sale
books a JV share. A JV partner who also sent the buyer through their own link does not earn the
referral commission on top (`jv.stack_with_referral`, off by default); other partners' referrals
are booked as usual. Settled and paid like a commission.

A plan switch in statamic-payments (the difference charge, `meta.subscription_change`) counts as a
renewal of the subscription's first payment and inherits its referral; it is never attributed by
the cookie the buyer carries at that moment.

## Payouts

**Affiliates → Payouts → Create Payout List** gathers every payable commission per partner and
currency; partners below `payouts.minimum_cent` wait for the next list. Export the open lists as
CSV (semicolon, UTF-8 with BOM; names are escaped against spreadsheet formulas), pay, and mark each
line as paid. A refund or a cancellation between creating the list and marking it paid recomputes
the open line; marking it paid sums it afresh, and the CSV carries that sum. A refund after the
payout takes back exactly what went out. CSV amounts use a decimal comma in German.

Payout details (IBAN, PayPal address) are stored encrypted and shown in the Control Panel only to
users with `manage affiliate payouts`; others see a masked line.

A list that a refund or cancellation drives to zero, below zero or below the minimum is dissolved,
and its commissions wait for the next list; only a positive list can be marked paid.

A partner's sales are paid first payments only, without refunded sales and own purchases; the conversion rate counts link sales per click
and never exceeds 100 %. A coupon code belongs to one partner per brand.

## Usage: the partner area

Signing up needs a signed-in user. Applications wait for approval (`signup.approval`), or are
active at once. Partners created in the Control Panel get an invitation link. It works for
`signup.invite_days` (14) and only for a signed-in user with the invited email address.

```antlers
{{ affiliates:dashboard }}                                 the whole area (overridable view)
{{ affiliates:link url="/kurse" }}                          a tracking link to any page
{{ affiliates:partner }}{{ clicks }} {{ sales }} {{ link }}{{ /affiliates:partner }}
{{ affiliates:commissions limit="20" }}…{{ /affiliates:commissions }}
{{ affiliates:payouts }}…{{ /affiliates:payouts }}
{{ affiliates:materials }}{{ title }} {{ copy }}{{ /affiliates:materials }}
{{ affiliates:apply_form }}…{{ /affiliates:apply_form }}
{{ affiliates:details_form }}…{{ /affiliates:details_form }}
```

`php artisan vendor:publish --tag=affiliates-views` copies the area and the two mails into
`resources/views/vendor/affiliates`. Promotional material lives in the `affiliate_materials`
collection; `{link}` in its copy becomes the partner's link.

## Mail

Partners get a mail per commission (switchable per partner and per brand), an invitation, and a
mail on approval. Sent through statamic-brand-context's sender identity of the partner's brand.
The commission mail never names the buyer.

## Events

`CommissionEarned`, `CommissionReversed`, `PartnerApplied`, `PartnerApproved`. The payments side is
listened to only when statamic-payments is installed; no listener ever throws into its fulfilment.

### As webhooks, through the Webhook Manager

With `goldnead/statamic-webhook-manager` installed, the four events appear there as triggers
("Affiliates: commission earned" / "Partner: Provision verdient"), under the handles the
automations addon uses. Nothing to switch on: offering a trigger sends nothing, data leaves only
through an outbound webhook somebody creates. `AFFILIATES_WEBHOOK_MANAGER=false`
(`affiliates.webhook_manager.enabled`) hides them. Each is delivered in the brand of the row, since a
commission is booked in a payment webhook where no brand is current. A row naming a brand that
cannot be set is **not delivered** and logged, rather than sent through the current brand's hooks.
A moment inside a database transaction (the ledger reverses in one) goes out after the commit,
never after a rollback.

**Duplicates and order.** `event_id` is the same every time the same moment is told again, so a
receiver can drop the repeat; a further partial reversal is a new moment. `occurred_at` is the
row's own time (`created_at`, `reversed_at`, `approved_at`), not the time of sending. **Order is
not guaranteed:** `affiliates.commission_reversed` can reach a receiver before the
`payments.refunded` that caused it. Sort by `occurred_at`, deduplicate by `event_id`.

```json
{
  "event": "affiliates.commission_earned",
  "event_id": "3c7a1e9f0b4d2a8c6e1f5b9d3a7c0e4f8b2d6a1c",
  "occurred_at": "2026-09-24T10:12:03+02:00",
  "brand": { "id": 2, "handle": "nordlicht" },
  "subject_type": "commission",
  "subject_id": 9,
  "commission": { "...": "see below" },
  "partner": { "...": "see below" }
}
```

| Trigger | Besides the common keys |
|---|---|
| `affiliates.commission_earned` | `commission`, `partner` |
| `affiliates.commission_reversed` | `commission` (with `reason`, `reversed_cent`), `partner` |
| `affiliates.partner_applied` | `partner` plus `message` (what the applicant wrote) |
| `affiliates.partner_approved` | `partner` |

**`commission`**: `id`, `kind`, `status`, `product`, `cycle`, `base_cent`, `amount_cent`,
`reversed_cent`, `currency`, `rate`, `payment_id`, `reverses_id`, `reason`, `sold_at`,
`available_at`, `approved_at`, `reversed_at`, `created_at`.

**`partner`**: `id`, `name`, `email`, `code`, `status`, `commission_percent`, `website`,
`created_at`, `approved_at`.

**Never in a body:** payout method and details (IBAN, PayPal), the invitation token, the operator's
notes, the linked user account, the buyer. Money is `*_cent` next to `currency`, times are ISO 8601.

## Control Panel

Partners, a partner's detail (link, figures, commissions, payouts, contracts), Commissions,
Payouts, Commission Rates, JV Contracts. Settings are a tab of the suite's settings screen
(statamic-brand-context). Permissions: `view affiliates`, `manage affiliates`,
`manage affiliate payouts`, `manage affiliates settings`.

## Multi-brand

Every table carries `brand_id`. Screens show the current brand. A payment attributes only to a
partner of its own brand; work triggered by a webhook runs in the partner's brand, so its settings
apply. Link codes are unique on the whole host.

## Commands

- `affiliates:install` creates the promotional material collection.
- `affiliates:release` approves commissions whose hold period is over (the screens do it too).
- `affiliates:book {payment}` books a paid payment again, idempotently.

## Testing

```bash
composer test
DB_DRIVER=mysql composer test:mysql
```
