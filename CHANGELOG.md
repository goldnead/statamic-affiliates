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

### Settlement and attribution rules (review round 2)
- A refund after payout takes back only what was paid out; open payout lists are recomputed on
  refunds and cancellations and summed afresh when marked paid.
- Sales count only paid first payments; conversion counts link sales per click, capped at 100 %.
- Coupon codes are unique per brand; an ambiguous code attributes to nobody. The partner screen
  flags codes offers does not know.
- Plan switches (`meta.subscription_change`) are renewals of the subscription's first payment.
- `commissions.partner_rate` (`main` by default) and `jv.stack_with_referral` (off by default).
- Commissions carry the sale date (`sold_at`) apart from the booking date. CSV amounts with a
  decimal comma in German. Payout table in six columns.
- Invitations expire (`signup.invite_days`) and bind only to the invited address. Payout details
  in the CP only with `manage affiliate payouts`. The `go` link notes the referral itself, so it
  works behind full static caching.

### Review round 3
- An open payout list that a refund or cancellation drives to zero, below zero or below the
  minimum is dissolved; its rows wait for the next list. Only a positive list can be marked paid.
- "The first n" renewals count paid, unrefunded renewals only; a plan switch earns but is no cycle.
- Without `manage affiliate payouts` the payout method is locked too; PayPal addresses are masked
  as `cl•••@•••`.
- Claw-back rows read "offset" instead of a zero base.
- The partner screen shows the `go` short link with a note on static caching.
- Refunded sales and own purchases no longer count as sales in the partner's figures.
