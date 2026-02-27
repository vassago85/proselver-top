# Backup Strategy

## Architecture
- **Operational storage**: Cloudflare R2 bucket (documents, PO uploads, POD scans)
- **Backup storage**: Separate Cloudflare R2 bucket (encrypted database dumps only)

## Daily Backup Process
1. Scheduled command `backup:run` executes at 02:00 daily
2. PostgreSQL dump created using `pg_dump` (custom format)
3. Dump encrypted with AES-256-CBC using APP_KEY
4. Encrypted file uploaded to R2 backup bucket under `daily/` prefix
5. Local temporary files cleaned up

## Retention Policy
- `backup:cleanup` runs at 03:00 daily
- Keeps 7 most recent daily backups
- Older backups automatically deleted

## Restore Procedure
1. Download encrypted backup from R2 backup bucket
2. Decrypt: `openssl enc -d -aes-256-cbc -salt -pbkdf2 -in backup.sql.enc -out backup.sql -pass pass:YOUR_APP_KEY`
3. Restore: `pg_restore -h HOST -U USER -d DATABASE backup.sql`

## Configuration
Set these environment variables:
- `R2_BACKUP_ACCESS_KEY_ID`
- `R2_BACKUP_SECRET_ACCESS_KEY`
- `R2_BACKUP_BUCKET`
- `R2_BACKUP_ENDPOINT`
- `R2_BACKUP_REGION`
