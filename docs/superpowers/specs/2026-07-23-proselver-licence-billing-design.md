# ProSelver platform licence billing (owner + developer)

**Date:** 2026-07-23  
**Status:** Approved for implementation

## Purpose

Internal meter for what **ProSelver** owes for the Trident platform licence.  
Separate from Customer Invoicing (freight). Other SaaS clients may use different pricing later; this page is ProSelver-only.

## Access

- Visible and usable only when `isOwner()` or `isDeveloper()`.
- Route mount aborts 403 for everyone else.
- Sidebar link gated the same way.

## Billable unit

- `executor_type = proselver`
- Status in `delivered` or `completed`
- Month bucketed by `delivered_at` (cancelled never count)

## Formula

```
total = base_fee + (billable_count × per_move_fee)
```

Defaults: base R3,500 / month, R50 per completed ProSelver move.  
Rates stored in `SystemSetting` and editable on the page by owner/developer.

Keys:
- `proselver_licence_base_fee` (float)
- `proselver_licence_per_move` (float)

## Tax

No VAT. Supplier is not VAT-registered. All amounts and Invoice Ninja copy
text are the charged total with an explicit “No VAT” note.

## UI

- Route: `/admin/billing` (`admin.billing`)
- Month selector + headline (count, base, per-move subtotal, total)
- Inline rate editors + save
- Drill-down table of billable jobs (link to order)
- Recent months strip
- “Copy for Invoice Ninja” — clipboard text with period, lines, total (no VAT, no API)

## Out of scope (v1)

- Invoice Ninja API push
- PDF generation
- Payment tracking
- Multi-tenant SaaS pricing for other clients
