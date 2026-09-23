# Changelog

## 0.1.0 — 2026-09-23

First version.

### Installing
- `composer require goldnead/statamic-affiliates`, then `php artisan migrate` and
  `php artisan affiliates:install` (the promotional material collection).
- Schedule `affiliates:release` (with `->withoutOverlapping()`): it approves commissions whose hold
  period is over. The Control Panel screens do it too when opened.
- With statamic-consent, add a service with the handle `affiliates` to
  `config/statamic-consent.php` so the banner can ask before the referral cookie is written.
- Give roles the permissions they need: `view affiliates`, `manage affiliates`,
  `manage affiliate payouts` (payout details and methods), and the settings permission.
- Requires statamic-brand-context 1.14+. Nothing is attributed without statamic-payments 1.24+
  (suggested, not required); statamic-offers (partner coupons) and statamic-consent are optional.

### Added
- Partners with sign-up (manual or automatic approval), invitation from the Control Panel, suspend
  and reject; link codes unique on the host; payout details stored encrypted.
- Tracking link `?ref={code}` on every page and `/!/affiliates/go/{code}`; referral in an
  encrypted cookie only with consent (statamic-consent or site-managed), session fallback for the
  visit; first or last click; configurable life; clicks counted once per visit without IP.
- Attribution from statamic-payments: the referral is written while the checkout request creates
  the payment; `PaymentPaid` books; renewals and follow-up offers inherit it.
- Coupons that belong to a partner count as a referral without a cookie, and beat another link by
  default (X3).
- Commission per product: percent of net or fixed, renewals none / first n / all with their own
  percentage, bumps and upsells per product, partner override, self-referral guard, VAT.
- Hold period, release command, refunds and chargebacks reverse proportionally, claw-back rows
  after payout.
- Joint-venture contracts: percentage of net per product set, own rates for bumps and upsells,
  renewals switchable, term (X2).
- Payout lists per partner and currency with minimum, CSV export, mark as paid.
- Control Panel: partners, partner detail, commissions, payouts, rates, JV contracts; settings in
  the suite settings screen; four permissions.
- Front end: `{{ affiliates:* }}` tags and an overridable partner area view; promotional material
  collection.
- Mails to partners on commission, invitation and approval through the brand's sender identity.
- Events `CommissionEarned`, `CommissionReversed`, `PartnerApplied`, `PartnerApproved`.

### Settlement and attribution rules
- A refund after payout takes back only what was paid out; open payout lists are recomputed on
  refunds and cancellations and summed afresh when marked paid.
- An open payout list that a refund or cancellation drives to zero, below zero or below the
  minimum is dissolved; its rows wait for the next list. Only a positive list can be marked paid.
- Sales count only paid first payments; conversion counts link sales per click, capped at 100 %.
- Coupon codes are unique per brand; an ambiguous code attributes to nobody. The partner screen
  flags codes offers does not know.
- Plan switches (`meta.subscription_change`) are renewals of the subscription's first payment
  and earn, but are no cycle: "the first n" renewals count paid, unrefunded renewals only.
- Refunded sales and own purchases do not count as sales in the partner's figures.
- `commissions.partner_rate` (`main` by default) and `jv.stack_with_referral` (off by default).
- Commissions carry the sale date (`sold_at`) apart from the booking date. CSV amounts with a
  decimal comma in German. Payout table in six columns.
- Invitations expire (`signup.invite_days`) and bind only to the invited address. Payout details
  in the CP only with `manage affiliate payouts`; without it the payout method is locked too, and
  PayPal addresses are masked as `cl•••@•••`.
- The `go` link notes the referral itself, so it works behind full static caching; the partner
  screen shows it with a note on static caching.
- Claw-back rows read "offset" instead of a zero base.
- `affiliates:install` gives the material image field an asset container
  (`materials.container`, or the site's first) and repairs an existing blueprint without one. An
  unreadable image is logged and left out instead of taking the partner area down.
