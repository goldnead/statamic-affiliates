# Changelog

## Unreleased

First version.

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
