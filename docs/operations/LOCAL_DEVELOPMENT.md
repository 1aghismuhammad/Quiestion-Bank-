# Local Development & Topology

This document describes the canonical end-to-end topology required to run the AI Question Bank locally for full QA.

## Canonical E2E Topology

You must run the following separate processes for the complete application lifecycle.

### 1. Web Server
Run the local development server:
```bash
php artisan serve
```

### 2. Material Extraction Worker
Extraction processes heavy files (PDF, DOCX) locally.
```bash
php -d memory_limit=512M artisan queue:work --queue=material-extraction,default --tries=3
```
> [!IMPORTANT]
> The `512M` memory limit is a current local QA workaround (observed requirement for `smalot/pdfparser` handling large PDFs) and is NOT a proven production sizing requirement.

### 3. AI / Profile / Generation Worker
This worker handles Gemini HTTP calls, Material Profiles, and AI question generation runs. It must run on the dedicated generation connection.
```bash
php artisan queue:work database-generation \
  --queue=material-intelligence,question-generation \
  --timeout=270 \
  --tries=3
```

### 4. Scheduler
The scheduler manages stale job recovery and automated polling.
```bash
php artisan schedule:work
```

### 5. Frontend Asset Compilation (Optional)
If modifying Blade/JS/CSS assets:
```bash
npm run dev
```

## Composer Dev Issue
> [!WARNING]
> Do NOT rely solely on `composer dev`. It is NOT currently canonical for full E2E QA because its worker topology was previously observed to omit `material-intelligence`. You must run the workers individually as documented above.

## Troubleshooting Workflows

If an AI workflow or extraction appears stuck:
1. **Never recommend or run `migrate:fresh`, `db:wipe`, or database resets as a troubleshooting step.**
2. Inspect worker output for exceptions.
3. Check `storage/logs/laravel.log`.
4. Inspect pending `jobs` table.
5. Inspect `failed_jobs` table. Note: `failed_jobs = 0` does not imply no domain workflow failed, as some workflows (e.g. Generation, Profiles) terminalize failures internally to domain tables.
6. Inspect domain status tables (`ai_generations`, `material_profile_versions`).
7. Inspect attempt audit tables (`ai_generation_attempts`, `material_profile_steps`).

## Continuous Documentation Rule
Any operational discovery that affects successful execution must be documented in the repository. Do not leave operational knowledge isolated in chat history or single environments. Examples include:
- Queue names and topologies
- Worker connections and timeouts
- PHP memory requirements
- External binaries
- DB behaviors and migration prerequisites
- Recovery procedures and known failure symptoms
