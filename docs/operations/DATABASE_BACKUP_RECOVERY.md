# Database Backup & Recovery Policy

This document outlines the backup, migration, and recovery policies for the AI Question Bank persistent database (`ai_question_bank`).

## Persistent State Identity

Database backup alone is NOT a complete application backup. The persistent application state includes two inseparable components:
1. **MySQL Database** (`ai_question_bank`)
2. **Material Storage** (`storage/app/materials/`)

The database rows (`materials` table) and the stored files on disk must remain consistent. A restore of the database without a corresponding restore of the `materials` directory will corrupt the application state.

## Backup Policies

1. **Pre-Migration Snapshotting**: Before running any `migrate` command that alters schema, you MUST take a snapshot of both the database and the `storage/app/materials/` directory.
2. **Periodic Backups**: Regular automated backups should capture both components synchronously.
3. **Storage Format**: Store database dumps in compressed formats (`*.sql.gz`, `*.sql.zst`, or `*.dump`) inside the `/backups/` or `/database/backups/` directories, which are ignored by git.

## Recovery Constraints

1. Point-in-time recovery must roll back BOTH the database and the `materials` directory to the exact same snapshot timestamp.
2. **Do not use destructive reset commands** (`migrate:fresh`, `db:wipe`, `migrate:reset`) as a shortcut for recovery on `ai_question_bank`. They destroy all persistent data and leave the `materials` directory orphaned.

## Double Human Confirmation

Mutating database operations targeting `ai_question_bank` require double human confirmation. This applies especially to:
- `php artisan migrate` (Forward migrations)
- `php artisan db:seed` (Master seeding)

Before proceeding with these commands, operators must verify:
- Actual runtime DB identity (`DB::connection()->getDatabaseName()`)
- Explicit understanding of idempotency and data impacts
- Current backup and recovery readiness
