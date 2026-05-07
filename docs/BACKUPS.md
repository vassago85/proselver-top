# Backup Strategy

## Architecture
- **Operational storage**: Cloudflare R2 bucket (documents, PO uploads, POD scans).
- **Backup storage**: Separate Cloudflare R2 bucket (encrypted database dumps only). Kept on a different account/credential so a compromise of the primary key cannot delete backups.

## Daily Backup Process
1. Scheduled command `backup:run` executes at 02:00 daily (`routes/console.php`).
2. PostgreSQL dump created using `pg_dump` (custom format, `-F c`) — pg_restore compatible.
3. Dump encrypted with **AES-256-CBC** using the **operator-set backup password** (see "Encryption password" below). Falls back to `APP_KEY` if no custom password is set, so legacy dumps remain decryptable.
4. Encrypted file uploaded to R2 backup bucket under `daily/` prefix.
5. Local plaintext + ciphertext files cleaned up (also cleaned up on crash, via the command's `finally` block).

## Encryption password

> **Critical**: store the password somewhere outside the server. Losing it means
> losing every backup encrypted with it.

Set or rotate it from **Admin → Settings → Storage → Backup encryption password**:

- The field is write-only — the page never re-renders an existing password into the form.
- Minimum length: 16 characters.
- Changing the password only affects **future** dumps. Existing encrypted backups must still be decrypted with whatever password was active at the time they were taken.
- Clearing the field reverts to the legacy `APP_KEY` behaviour. Any backup taken while in this state will require knowing `APP_KEY` to restore.

The password is stored in `system_settings` (key: `backup_encryption_password`) and is read by `backup:run` on every invocation, so a saved value applies on the next scheduled run with no restart required.

### Why decoupled from APP_KEY?

- Rotating `APP_KEY` (e.g. after staff changes) used to silently lock the team out of every historical backup. With a separate password, key rotation is a no-op for backups.
- A restore engineer can be handed a single password without ever seeing the live application secret.
- The password is passed to `openssl` via the environment (`-pass env:`), not the command line, so it never appears in `ps` or process audit logs.

## Manual backup (verify end-to-end)

Two equivalent paths:

**From the dashboard** — Admin → Settings → Storage → Backup encryption → **Run backup now**. Synchronous; the page reports success or the artisan exit code + first 240 chars of output. Use this to validate the full chain (DB reachable → encryption password set → R2 backup creds working) after any config change.

**From a shell on the server**:

```bash
docker compose exec app php artisan backup:run
```

Both paths invoke the same command, so the dashboard "Run backup now" is a faithful test of what the 02:00 scheduler will do.

## Retention Policy
- `backup:cleanup` runs at 03:00 daily.
- Keeps 7 most recent daily backups under `daily/`.
- Older backups automatically deleted from the R2 backup bucket.

## Restore Procedure

1. Download the encrypted backup from R2 backup bucket (any S3 client; aws-cli or rclone work).
2. Decrypt:

   ```bash
   openssl enc -d -aes-256-cbc -pbkdf2 -in backup_YYYY-MM-DD_HHMMSS.sql.enc \
     -out backup.sql -pass pass:'YOUR_PASSWORD'
   ```

   Use the password that was active **at the time the backup was taken**:
   - Dumps from before the dashboard field existed → `APP_KEY`.
   - Dumps after a custom password was set → that custom password.

3. Restore (custom format requires `pg_restore`, not `psql`):

   ```bash
   pg_restore -h HOST -U USER -d DATABASE --clean --if-exists backup.sql
   ```

## Configuration Summary

Settings live in `system_settings` (managed via Admin → Settings → Storage):

| Key | Purpose |
|---|---|
| `r2_backup_access_key_id`     | R2 backup bucket access key |
| `r2_backup_secret_access_key` | R2 backup bucket secret      |
| `r2_backup_region`            | Usually `auto` for R2        |
| `r2_backup_bucket`            | Backup bucket name           |
| `r2_backup_endpoint`          | R2 S3 endpoint URL           |
| `backup_encryption_password`  | AES-256-CBC password (defaults to `APP_KEY` when blank) |

Initial seeding via `.env` is supported via the corresponding `R2_BACKUP_*` keys (see `docker-compose.yml`); the dashboard takes precedence at runtime.
