# Assumptions

## Platform Scope
- This is the Proselver TOP (Transport Operations Platform) built by Charsley Digital.
- The platform replaces manual transport booking, PO verification, driver management, and invoicing workflows.
- No native mobile app; PWA (Progressive Web App) with offline capability is used for drivers.

## Authentication
- Username + password only. No self-registration. Users are created by Super Admin or Ops Manager.
- Password reset via email is available for users who have an email address on file.
- Fortify handles authentication with username field (not email).

## Multi-Tenancy
- Single-tenant deployment per Charsley Digital Architecture V1.
- Each Proselver instance gets its own app, database, and Redis.

## Roles
- Roles are stored in a database table and assigned via many-to-many pivot.
- A user can hold multiple roles simultaneously. Permissions are cumulative.
- Internal users (Super Admin, Ops Manager, Dispatcher, Accounts) access /admin routes.
- Dealer users (Dealer Admin, Scheduler, Accounts, Viewer) access /dealer routes.
- Drivers access /driver routes and the PWA-optimized mobile interface.

## PO Verification
- Every booking requires a PO upload before it can proceed.
- Admin verifies PO details match booking details in a split-screen view.
- PO verification is a prerequisite for job approval.

## Pricing & Financial Engine
- Sell prices visible to customers: base transport, delivery fuel, penalties, credits, VAT.
- Internal costs (fuel, tolls, driver, accommodation, other) are only visible to internal users.
- Margin and profit calculations are computed in real-time on the Job model.
- No automatic pricing adjustments; route margins inform human pricing decisions.

## Performance Scoring
- Only client-caused delays count toward performance scoring.
- Emergency jobs are excluded from performance calculations.
- Monthly credit notes (3% of base transport charges) require both accuracy >= 90% and minimum eligible jobs.

## Cancellation Penalties
- Late cancellation penalty = driver cost only (not fuel/tolls).
- Admin can override penalties with a mandatory logged reason.

## Backups
- Daily encrypted PostgreSQL dump uploaded to a separate R2 backup bucket.
- Retention: 7 daily backups (excess deleted automatically).
- Encryption uses AES-256-CBC with the APP_KEY.

## Storage
- S3-compatible storage (Cloudflare R2 preferred) for operational files.
- Separate bucket for database backups (write-only from app perspective).
- Local disk used for development; R2 for production.

## Invoicing
- Manual invoice generation (DomPDF) for MVP.
- Future: accounting system integration (not in scope for initial delivery).

## Currency
- All monetary values in South African Rand (ZAR).
- VAT rate configurable via system settings (default 15%).
