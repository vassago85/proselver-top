# File Storage

## What gets stored

| Kind | Source | Category in `job_documents.category` | Path convention |
|---|---|---|---|
| Vehicle photos (collection, in-transit, POD) | Driver PWA | `photo`, `damage_photo`, `proof_of_delivery`, `collection_note` | `jobs/{job_uuid}/documents/...` |
| Purchase orders | Dealer / OEM / customer upload | `purchase_order` | `jobs/{job_uuid}/po/...` |
| Driver licence & PDP scans | Admin driver form | _(on `driver_profiles` columns, not job_documents)_ | `drivers/{user_id}/license/...`, `drivers/{user_id}/pdp/...` |
| Petty-cash slips (fuel, food, toll, parking) | Driver PWA | `fuel_slip`, `food_slip`, `toll_slip`, `parking_slip`, `other` | `jobs/{job_uuid}/documents/...` |
| Database dumps | `php artisan backup:database` | n/a | `r2-backup` disk, `db-backups/YYYY-MM-DD/...` |

Every row in `job_documents` carries the exact disk it was written to (the `disk` column), so swapping `FILESYSTEM_DISK` later does not break old files — they are still read from whichever disk they originally landed on.

## Choosing a backend

| Backend | When to use | Keys needed |
|---|---|---|
| `local` | Dev + single-server demos. Uses the Docker volume `app-storage`. | None. |
| `r2` (recommended for prod) | Production. Cloudflare R2 — zero egress fees, S3-compatible. | `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET`, `R2_ENDPOINT`. |
| `s3` | If the org standardised on AWS. | `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET`, `AWS_DEFAULT_REGION` (+ optional `AWS_ENDPOINT`). |

When `FILESYSTEM_DISK` points at a remote disk whose credentials are missing, the app logs a warning to `storage/logs/laravel.log` and transparently falls back to `local`. It will NOT 500 on upload — but subsequent rebuilds of the container lose the files. Always run `storage:check` after changing env.

## Setting up Cloudflare R2

1. Cloudflare dashboard → **R2** → **Create bucket** (`trident-documents` or similar).
2. **Manage R2 API Tokens** → **Create token**.
   * Permissions: **Object Read & Write**
   * Bucket: the bucket you just created (do NOT grant account-wide access)
   * TTL: unlimited (or rotate quarterly — see `docs/BACKUPS.md`)
3. Copy the four values into `.env` on the server:
   ```dotenv
   FILESYSTEM_DISK=r2
   R2_ACCESS_KEY_ID=<access key id>
   R2_SECRET_ACCESS_KEY=<secret>
   R2_BUCKET=trident-documents
   R2_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com
   ```
   The account endpoint is shown on the bucket overview page; do **not** append the bucket name.
4. Restart the app so the new env values take effect:
   ```bash
   docker compose up -d --force-recreate app scheduler queue
   ```
5. Verify end-to-end:
   ```bash
   docker compose exec app php artisan storage:check --disk=r2
   ```
   Expected output:
   ```
   → Testing disk r2
     driver: s3
     ✓ write  (120 ms) → storage-check/.../xxx.txt
     ✓ read   (80 ms)
     ✓ exists
     ✓ delete (60 ms)
     OK — disk 'r2' is healthy.
   ```

## Setting up a backup bucket

Database dumps use a **separate** `r2-backup` disk with its own token, so a compromised primary storage key cannot destroy your backups.

Configure independently:
```dotenv
R2_BACKUP_ACCESS_KEY_ID=...
R2_BACKUP_SECRET_ACCESS_KEY=...
R2_BACKUP_BUCKET=trident-db-backups
R2_BACKUP_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com
```

Verify:
```bash
docker compose exec app php artisan storage:check --disk=r2-backup
```

See `docs/BACKUPS.md` for the scheduled job details.

## Viewing photos in the admin UI

`/admin/orders/{id}` splits `job_documents` into two blocks:

* **Vehicle photos & paperwork** — visible to anyone who can see the job (dealer, OEM, customer, internal ops).
* **Driver expenses** — only visible to internal staff (`ops_manager`, `super_admin`, `developer`) and platform-owner users (FAW admins).

Files are streamed through `GET /documents/{document}/view`, which is guarded by `JobDocumentPolicy`. Never link directly to the underlying R2 object — the public URL would bypass authorisation.

## Health-check cheat sheet

```bash
# the disk currently in use
docker compose exec app php artisan storage:check

# a specific disk
docker compose exec app php artisan storage:check --disk=r2
docker compose exec app php artisan storage:check --disk=r2-backup

# every disk (skips r2-backup — run that one explicitly)
docker compose exec app php artisan storage:check --all
```

The command exits non-zero on any failure, so you can chain it into deploy pipelines:
```bash
docker compose exec -T app php artisan storage:check --disk=r2 || exit 1
```

## Migrating from `local` to `r2`

Old files remain readable on `local` because `job_documents.disk` pins each file to its original home. New uploads after the env flip go to `r2`. If you want the old files on R2 too, mirror them once:

```bash
# inside the app container
rclone copy /var/www/html/storage/app/private/jobs r2:trident-documents/jobs --progress
```

Then a one-off DB update rewrites the disk column:
```bash
docker compose exec app php artisan tinker
>>> \App\Models\JobDocument::where('disk', 'local')->update(['disk' => 'r2']);
```

Only run the DB update **after** confirming the rclone copy completed and all files exist in R2, otherwise the admin UI will 404 on those rows.
