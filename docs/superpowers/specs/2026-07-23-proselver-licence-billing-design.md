# ProSelver platform licence billing (owner + developer)

**Date:** 2026-07-23  
**Status:** Implemented (page hidden until enabled)

## Purpose

Internal meter for what **ProSelver** owes for the Trident platform licence,
invoiced by a separate supplier company. Separate from Customer Invoicing
(freight). Other SaaS clients may use different pricing later.

## Access

- Visible and usable only when `isOwner()` or `isDeveloper()`.
- Route mount aborts 403 for everyone else.
- Sidebar link gated the same way.
- `proselver_licence_billing_enabled` (boolean, default **false**) — page and
  sidebar stay hidden until commercial agreement; flip to `true` to open.

## Billable unit

- `executor_type = proselver`
- Status in `delivered` or `completed`
- Month bucketed by `delivered_at` (cancelled never count)

## Formula

```
excl VAT = billable_count × per_move_fee
VAT (15%) = excl × 0.15
incl VAT  = excl + VAT
```

Default per-move fee: **R150** (excl. VAT). No monthly base fee.  
Rate stored in `SystemSetting` key `proselver_licence_per_move` (float),
editable on the page by owner/developer.

## Tax

15% VAT is calculated and shown (supplier company is VAT-registered).

## UI

- Route: `/admin/billing` (`admin.billing`)
- Month selector + headline (count, excl VAT, VAT, incl VAT)
- Rate editor + save
- Drill-down table of billable jobs (link to order)
- Recent months strip
- **Copy for invoice** — plain-text clipboard block for any invoicing system
  (not Invoice Ninja–branded; no API)

## Out of scope (v1)

- Invoicing-system API push
- PDF generation
- Payment tracking
- Multi-tenant SaaS pricing for other clients
