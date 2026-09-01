# Change Log

Perubahan dicatat berdasarkan keputusan dan artefak dokumentasi. Status `Added` pada fase perencanaan tidak berarti fitur sudah diimplementasikan.

## Entry Format

```text
Date:
Version:
Phase:
Type:

Added:
-

Changed:
-

Fixed:
-

Database Impact:
-

Notes:
-
```

## v0.12.1 Phase 4.5 + 4.6 Architect source-audit corrective pass

- Date: 1 September 2026
- Version: 0.12.1
- Phase: Phase 4 - AI Question Engine
- Type: Fix

Added:

- Runtime stale floor `max(1800, configured stale_after_seconds)` in `RecoverStaleGenerations`. Operators may raise the threshold; values below 1800 cannot recover healthy jobs.
- Start-transaction parent lineage: same user, `failed`, stored Usage `released` before inserting a child Generation.

Changed:

- Scheduler overlap mutex is `withoutOverlapping(10)` (10-minute expiry) in `routes/console.php`.
- Generation show poll reloads the page when `generation_status` changes (queued → processing) as well as on terminal status. Payload remains `{ generation_status, terminal }`.
- `DEVELOPMENT_RULES` / `DESIGN` rate-limit wording matches Phase 4: no dedicated generation/retry HTTP limiter.
- `FLOW` current-runtime diagram no longer shows `queued|processing → cancelled` as live behavior (Cancel remains deferred).

Fixed:

- Config `stale_after_seconds=30` can no longer recover a 120-second-old queued or processing row.
- Foreign, completed, failed+reserved, failed+charged, and missing-usage parents cannot create a child Generation.

Database Impact:

- None. No schema migration. Recovery lock order remains User → Generation → Usage.

Notes:

- Intended documentation reconciliation may still say Phase 4 `COMPLETE`. That is not staging authorization.
- Owner real-browser QA is still pending.
- No Git commit from the corrective-pass agent.

## v0.12.0 Phase 4.5 + 4.6 Generation UI and Stale Recovery

- Date: 1 September 2026
- Version: 0.12.0
- Phase: Phase 4 - AI Question Engine
- Type: Feature implementation

Added:

- Owner Blade generation flow: `GET/POST /materials/{material}/generations`, `GET /generations`, `GET /generations/{generation}`, `GET /generations/{generation}/status`, `POST /generations/{generation}/retry`.
- `GenerationController`, `StoreGenerationRequest`, `AiGenerationPolicy` (owner-only; no Admin bypass). Cross-user Generation IDs 404 via owner-scoped lookup.
- Create form exposes implemented runtime choices only (formative/summative/diagnostic, easy/medium/hard/hots, multiple_choice, count 1..max, id/en). Defaults question_count=5 and output_language=id.
- Quota summary Terpakai/Diproses/Tersedia from existing `GenerationUsageSnapshot` (`ResolveCurrentGenerationUsage` / coherent entitlement→quota→usage). Display clamp `max(0, available)`.
- Queued/processing poll (~5s vanilla JS, `aria-live="polite"`, noscript reload). Completed preview renders validated MCQ. Failed shows safe `error_message` and Retry. Partial `result_json` is not rendered before completed.
- `RetryFailedQuestionGeneration`: failed only; new Generation + new reservation; `parent_generation_id` written in the Start transaction; copies assessment/difficulty/type/count/language; re-checks ownership, eligibility, and quota.
- `RecoverStaleGenerations` + `generations:recover-stale` scheduled every minute `withoutOverlapping(10)` from `routes/console.php`. Config `GENERATION_STALE_AFTER_SECONDS` (1800 is the minimum safe floor; operators may configure a higher threshold) and optional batch 50.
- `GenerationErrorCode::StaleRecovery` (`stale_recovery`), permanent, safe user message.

Changed:

- Phase 4.5, Phase 4.6, and Phase 4 overall are `COMPLETE`. Next is Phase 5 Question Bank.
- `StartQuestionGeneration` accepts optional `parentGenerationId` and writes it in the same create transaction.
- Account subscription page shows Terpakai/Diproses/Tersedia from the same snapshot (Indonesian labels; English `used`/`remaining` remain hidden).
- Dashboard Generate Question links to materials; History links to `/generations`. Question Bank stays a placeholder.

Fixed:

- Candidate stale scan does not lock. Per-ID recovery locks User → Generation → Usage and re-checks status, usage, and timestamps (`queued_at` vs `updated_at`).
- Recovery leaves processing `execution_token` and does not modify STARTED attempt rows. Old workers cannot persist/finalize after recovery.
- Duplicate recovery is idempotent (`failed` + `released`). No provider HTTP and no Job redispatch.

Database Impact:

- None. No `reservation_expires_at`. No new tables or applied-migration rewrites.

Notes:

- SQLite PHPUnit does not prove row locks. Local MySQL races A–E passed: success vs recovery (one legal terminal pair); failure vs recovery (`failed`+`released`); two recovery workers (one transition); two retries at quota boundary (one child, quota reject); status/usage reads during finalization observed no illegal pair. Marker fixtures and the race script were deleted after QA.
- Real Gemini smoke (1 MCQ, short text Material, `database-generation` worker) completed, charged once, one attempt audit row, no API key in logs, no raw prompt/response columns.
- No Livewire/React/Vue/websockets. No Cancel. No Question Bank persist/import.
- No Git commit from the implementation agent.
- Owner real-browser QA is still pending. Do not treat documentation `COMPLETE` as staging authorization.

## v0.11.0 Phase 4.3 + 4.4 Gemini Provider and Async Orchestration

- Date: 31 August 2026
- Version: 0.11.0
- Phase: Phase 4 - AI Question Engine
- Type: Feature implementation

Added:

- Laravel HTTP Client Gemini `generateContent` provider (`GeminiQuestionGenerationProvider`) with JSON schema MCQ output. No Gemini Composer SDK.
- `McqPromptBuilder` (config `generation.prompt_version`) and `GeminiModelSelector` (attempt 1–2 primary, attempt 3 fallback when eligible).
- `GenerateQuestionsJob` on dedicated queue connection `database-generation` / queue `question-generation` (`retry_after` 360). Job `$tries=3`, `$timeout=270`, `$failOnTimeout=false`.
- DB-authoritative `execution_token` claim/resume. Same serialized token resumes `processing`; a different token exits without HTTP.
- `ai_generation_attempts` per-call audit including `prompt_version` actually used for that HTTP call.
- Validated `result_json` on `ai_generations` with partial persist across crash/resume. Targeted repair keeps valid slots.
- `FinalizeGenerationSuccess` / `FinalizeGenerationFailure` wrap stored Consume/Release (User → Generation → Usage). `failed_at` / `error_code`.
- `OutputLanguage` `id`/`en` required on new Start. Legacy `output_language` remains nullable; Job fail-closed with no HTTP.

Changed:

- Phase 4.3 and 4.4 are `COMPLETE`. Phase 4 overall remains `IN PROGRESS`. 4.5/4.6, generation UI, and Question Bank are not implemented.
- `StartQuestionGeneration` requires `OutputLanguage`, MCQ only, configurable max questions (default 10), `attempt_number=0`, and dispatches `GenerateQuestionsJob` after the reservation transaction.
- Existing `database` queue connection `retry_after` stays 90 (`DB_QUEUE_RETRY_AFTER`). Composer `dev` adds a separate generation listener; extraction listener is unchanged.
- Prompt version is not stored on `ai_generations` and there is no `prompt_versions` table.
- Production/example Gemini defaults are `gemini-3.5-flash-lite` (primary) and `gemini-3.7-flash` (fallback). Local runtime model remains environment-controlled.

Fixed:

- Provider HTTP is hard-capped at 3 attempts even if `generation.max_provider_attempts` is set above 3. The env knob was removed.
- Same-token Job resume restores the latest persisted attempt error (`GenerationErrorCode::isPermanent()` / fallback-eligible / `incomplete_output`) instead of resetting `previousError`.
- Successful provider responses persist attempt terminal metadata and merged `result_json` in one transaction (`FinishGenerationAttempt`). Persistence errors bubble.
- Gemini 3.x `generationConfig` no longer sends `temperature`, `top_p`, or `top_k`. Unexpected non-transport exceptions are not classified as transient provider errors.
- MCQ options A–D must be four distinct normalized texts. Stored `question_type` / `question_count` fail closed before HTTP. No backoff after attempt 3.

Database Impact:

- Alter `ai_generations`: `output_language` nullable, `execution_token`, `result_json`, provider/model/token aggregates, `failed_at`, `error_code`; backfill `attempt_number` to 0 and default 0.
- New table `ai_generation_attempts` with UNIQUE `(generation_id, attempt_number)`. Restrictive FK. No raw prompt/response columns.

Notes:

- SQLite PHPUnit does not prove row locks. Local MySQL races passed: (A) two different tokens → one owner; (B) same token resume preserves Generation/Usage/attempt budget; (C) two success finalizers → one completed+charged; (D) success vs failure → completed+charged; (E) concurrent begin-attempt → unique sequential identity, never 4. Marker fixtures and the race script were deleted after QA.
- No generation controller, route, or Blade. No `question_sets`.
- No new Composer dependencies.
- No Git commit from the implementation agent.

## v0.10.0 Phase 4.1 + 4.2 Generation Domain and Usage Runtime

- Date: 29 August 2026
- Version: 0.10.0
- Phase: Phase 4 - AI Question Engine
- Type: Feature implementation

Added:

- `ai_generations` and one-row-per-Generation `ai_usage_logs` (`UNIQUE generation_id`).
- Enums: `AssessmentType`, `DifficultyLevel`, `QuestionType`, `GenerationStatus`, `UsageStatus`.
- `StartQuestionGeneration` (inline reserve), `AssertMaterialEligibleForGeneration`, `ResolveGenerationUsage`, `ConsumeGenerationCredit`, `ReleaseGenerationCredit`.
- Owner-only `MaterialPolicy::generate` (ownership only, including soft-deleted owned rows). Cross-user including Admin is 403. Eligibility (`AssertMaterialEligibleForGeneration`) rejects owned trash/draft/archived/bad extraction with ValidationException.
- Nested-transaction-safe Consume/Release for future 4.3 atomic finalization.

Changed:

- Phase 4.1 and 4.2 are `COMPLETE`. Phase 4 overall is `IN PROGRESS`. Gemini, prompt builder, generation UI, and Question Bank remain `NOT IMPLEMENTED`.
- DBML reconciled to the implemented 4.1+4.2 subset (no `topic_id`, `prompt_version_id`, raw AI payload, `credit_used`, `usage_action`, or `reservation_expires_at`).
- `charged` is the business meaning of consumed. Capacity is `allowance - charged - reserved`.

Fixed:

- Consume/Release finalize the stored reservation window/plan/subscription and do not re-resolve current entitlement after rollover, upgrade, or expiry.
- `generate` does not treat owner soft-delete as 403; eligibility rejects it with ValidationException.
- Default `AiGenerationFactory` keeps `generation.user_id` aligned with `material.user_id` unless both keys are set explicitly.
- Docs: automatic retry = same Generation/Usage; manual retry = new Generation + optional `parent_generation_id`; no default raw payload persistence; Question Bank is Phase 5.

Database Impact:

- New tables `ai_generations` and `ai_usage_logs`. Restrictive FKs. Named MySQL-safe indexes. No speculative columns.

Notes:

- SQLite PHPUnit does not prove row locks. Local MySQL races A–D passed: same-user last credit (1 success + 1 quota reject); different users both succeed; double consume charges once; consume vs release leaves exactly one terminal state. Marker fixtures and race scripts were deleted after QA.
- No generation controller, route, or Blade.
- No new Composer dependencies.
- No Git commit from the implementation agent.

## v0.9.0 Phase 3.5 + 3.6 Generation Quota, Offers, and Manual Payment

- Date: 28 August 2026
- Version: 0.9.0
- Phase: Phase 3 - Subscription and Quota Foundation
- Type: Feature implementation

Added:

- `ResolveGenerationQuota` / `ResolvedGenerationQuota` (read-only; carries the same `ResolvedEntitlement` used by the subscription page).
- `CalendarMonths::addNoOverflow` from the original `starts_at` anchor; monthly windows are half-open and bounded by `ends_at`.
- `plan_offers` and `subscription_upgrade_requests` with short MySQL-safe index/FK names; unique nullable `approved_subscription_id`.
- Canonical offers `pro_1m` (Rp10.000 / 1 month) and `pro_3m` (Rp25.000 / 3 months) via `PlanOfferSeeder` `firstOrCreate`.
- Blade `/account/subscription` and `/admin/subscription-upgrades` (approve / reject with required reason / cancel).
- Static QRIS on the Laravel `public` disk (`SUBSCRIPTION_QRIS_PATH=payment/qris.png`) and WhatsApp `wa.me` redirect (`SUBSCRIPTION_WHATSAPP_NUMBER`).

Changed:

- New pending requests require an active Pro Plan, active Offer, valid QRIS file, and valid WhatsApp number. Existing pending requests remain approvable if Plan/Offer later become inactive, using the stored snapshot.
- Approval locks user then request then the active subscription queue, appends one `status=active` Pro window, and is idempotent.
- Phase 3.5 + 3.6 and Phase 3 overall are `COMPLETE`. Generation consumption, reservation, and `ai_usage_logs` runtime move to Phase 4.

Fixed:

- Confirm pending insert retries on UNIQUE(`reference_code`) around the actual insert (bounded, 8 attempts), not an `exists()` precheck.
- `RejectSubscriptionUpgrade` rejects blank or whitespace-only reasons without mutating status or reviewer fields.

Database Impact:

- New tables `plan_offers` and `subscription_upgrade_requests`. No changes to `subscriptions` status enum.

Notes:

- UI does not show generation used/remaining.
- SQLite PHPUnit does not prove row locks. Local MySQL: two confirm processes for the same user produced one pending request; two approve processes for the same pending request produced one Subscription. Two different users confirming concurrently produced two pending requests. Marker fixtures were deleted after QA.
- Real production QRIS is not committed. `php artisan storage:link` is required to serve the public disk.
- No new Composer dependencies.
- Phase 3 COMPLETE + APPROVED. Authenticated browser QA was completed by the Project Owner.

## v0.8.0 Phase 3.3 + 3.4 Entitlement Resolver and Storage Quota

- Date: 28 August 2026
- Version: 0.8.0
- Phase: Phase 3 - Subscription and Quota Foundation
- Type: Feature implementation

Added:

- `ResolveUserEntitlement` with readonly `ResolvedEntitlement` (live Plan limits, nullable Subscription).
- Integrity exceptions: ambiguous effective windows, missing/inactive canonical Free, invalid current/future Pro queue.
- `GuardUploadStorageQuota` reusing `MaterialUsageCalculator`.
- Account storage quota on file upload: Free 52428800 bytes, Pro 524288000 bytes; equality allowed; +1 byte rejected.

Changed:

- `CreateUploadMaterial` locks the owner `users` row, re-checks duplicates under the lock, then resolves entitlement and quota before storing the private file and inserting Material. Dispatch remains after commit.
- Inactive Pro Plan still honors an already-paid effective window. Admin follows the same entitlement rules. MaterialPolicy unchanged.
- Phase 3.3 + 3.4 are `COMPLETE`. Phase 3 overall remains `IN PROGRESS`.

Fixed:

- None.

Database Impact:

- None. No new tables or columns.

Notes:

- Effective rule: `status=active` AND `starts_at <= now < ends_at`. Time is source of truth; no scheduler required.
- Current/future active queue (`ends_at > now` OR `starts_at >= now`) must reference Plan Pro with `starts_at < ends_at`. Historical stale rows do not lock the account.
- Duplicate message is preferred over quota when the same hash already exists.
- Over-quota after Pro ends retains existing Materials; text/archive/restore remain allowed.
- SQLite PHPUnit does not prove row locks. Local MySQL race (database queue, worker stopped): same-user 45 MiB + two concurrent 4 MiB uploads produced one Material, one `jobs` row on `material-extraction`, quota ValidationException for the loser, usage 51380224 <= 52428800. Different-user concurrent uploads both succeeded.
- No new Composer dependencies.

## v0.7.0 Phase 3.1 + 3.2 Plan and Subscription Domain

- Date: 28 August 2026
- Version: 0.7.0
- Phase: Phase 3 - Subscription and Quota Foundation
- Type: Feature implementation / domain foundation

Added:

- `plans` catalog with canonical Free and Pro entitlement rows via idempotent `PlanSeeder`.
- `subscriptions` as finite Pro entitlement windows (`starts_at`, `ends_at`, `status`, `cancelled_at`).
- PHP backed enums: `PlanCode`, `PlanStatus`, `GenerationResetStrategy`, `SubscriptionStatus`.
- `User` hasMany `Subscription`; `Plan` hasMany `Subscription`.
- Schema, domain, and seed tests. No `PlanFactory`.

Changed:

- Plan is entitlement only (bytes + generation limit + reset strategy). Price/duration deferred to a future offer layer.
- Free is fallback Plan, not an endless subscription row. OAuth still does not provision subscriptions.
- Phase 3 overall is `IN PROGRESS`. Quota resolver, storage enforcement, payment, QRIS, and WhatsApp confirmation are not started.

Fixed:

- None.

Database Impact:

- New tables `plans` and `subscriptions`. Unique `plans.code`. Index `subscriptions(user_id, status)`. FKs restrict on user and plan delete.
- No `description`, `price`, `storage_limit_mb`, `subscription_code`, or payment/approval columns.
- Canonical seed: Free `52428800` bytes / 2 lifetime; Pro `524288000` bytes / 100 monthly.
- DBML and DATABASE_REFERENCE updated to 0.7.0.

Notes:

- Sequential non-overlapping `active` windows are allowed so later renewal can append after `ends_at`.
- Overlap prevention and entitlement resolver belong to Phase 3.3 + 3.4, together with account storage quota. Generation quota and `ai_usage_logs` belong to Phase 3.5 + 3.6.
- Future canonical `ai_usage_logs` (not implemented): required `plan_id`; nullable `subscription_id` so Free lifetime usage has no subscription row.
- No new Composer dependencies.

## v0.6.6 Phase 2 Closure

- Date: 27 August 2026
- Version: 0.6.6
- Phase: Phase 2 - Material Management
- Type: Documentation / integration closure

Added:

- None. Application code was already complete through Phase 2.8.

Changed:

- Phase 2.1–2.8 are `COMPLETE`. Phase 2 overall is `COMPLETE`. Next phase is Phase 3 Subscription & Quota Foundation (`PLANNED`; not started).

Database Impact:

- None. No migration. No DBML change.

Notes:

- Final integration/QA found no application defects requiring a code fix.
- Owner-scoped listing, owner-only policy, nested topic IDOR protection, private materials disk, archive-as-status (not soft delete), and after-commit extraction dispatch remain as implemented.
- Automatic Laravel queue retry remains implemented. No `RetryMaterialExtraction` Action or manual retry UI.
- ADMIN still does not receive global Material access.
- Phase 3 has not started. No subscription, quota, Gemini, or Question Bank implementation in this closure.

## v0.6.5 Phase 2.7–2.8 Archive/Restore and Material Web UI

- Date: 27 August 2026
- Version: 0.6.5
- Phase: Phase 2 - Material Management
- Type: Feature implementation

Added:

- `ArchiveMaterial` and `RestoreMaterial` Actions with owner authorization.
- `MaterialPolicy` abilities `archive` and `restore`.
- Blade/controller Material Management: owner-scoped index, archived index, create text/upload, show, edit, nested topic create/update/delete, archive, and restore.
- Named authenticated routes under the same `auth` / `account.active` / `profile.complete` middleware as the user dashboard.

Changed:

- Phase 2.7 archive/restore and Phase 2.8 Material web management are technically complete. Phase 2 status remains `IN PROGRESS`. Phase 2 Definition of Done is not met until final integration / QA.

Database Impact:

- None. No migration. No DBML change. Lifecycle uses existing `materials.status`.

Notes:

- Canonical transitions: `draft|ready -> archived` and `archived -> ready`. Archive is not Eloquent SoftDeletes.
- Archive/restore retain source metadata, extracted/text content, topics, and private files. They do not dispatch extraction.
- ADMIN still does not receive global Material access.
- Listing uses `$user->materials()` only. Soft-deleted Materials are not reachable by guessed URLs.
- Browser upload uses existing `CreateUploadMaterial` after-commit `ExtractMaterialContent` dispatch. No controller-level redispatch.
- No source-file download/preview. No `RetryMaterialExtraction`. No Livewire.
- Remaining Phase 2: final integration / QA / documentation closure.

## v0.6.4 Phase 2.5–2.6 Topic Management and Ownership

- Date: 27 August 2026
- Version: 0.6.4
- Phase: Phase 2 - Material Management
- Type: Feature implementation

Added:

- Material topic Actions: `CreateMaterialTopic`, `UpdateMaterialTopic`, `DeleteMaterialTopic`, and `ListMaterialTopics`.
- Domain validation for topic name, optional focus area, chapter/sub-chapter empty-string normalization, `sort_order`, and page range (`page_start >= 1`, `page_end >= page_start` when both are set).
- Owner-only `MaterialPolicy` abilities: `viewAny`, `create`, `view`, `update`, and `manageTopics`.
- Topic mutations authorize through the parent Material; cross-Material topic id reuse is denied.

Changed:

- Phase 2.5 topic management and Phase 2.6 Material ownership are technically complete. Phase 2 status remains `IN PROGRESS`. Phase 2 Definition of Done is not met.

Database Impact:

- None. Existing `material_topics` migration and canonical DBML were reused. No new migration. No DBML change.

Notes:

- Ownership derives from `materials.user_id`. Topic rows do not store `user_id`.
- ADMIN does not receive global Material access. Admin dashboard, user management, and later question-bank admin remain separate.
- Soft-deleted Materials cannot be viewed or have topics managed. Archived topic mutability is unchanged pending Phase 2.7–2.8 lifecycle work.
- Duplicate `(material_id, chapter, sub_chapter, topic_name)` is rejected as validation, not a raw query exception.
- No drag-and-drop reorder Action. List order remains `sort_order`, then `topic_id`.
- Remaining Phase 2: combined archive/restore plus controllers/routes/Blade UI, then Phase 2 final integration.

## v0.6.3 Phase 2.4 Content Extraction

- Date: 27 August 2026
- Version: 0.6.3
- Phase: Phase 2 - Material Management
- Type: Feature implementation

Added:

- PDF, DOCX, and TXT content extraction on the private `materials` disk.
- After-commit `ExtractMaterialContent` dispatch from `CreateUploadMaterial` onto queue `material-extraction`.
- Atomic `pending|failed` → `processing` claim, processing resume, and guarded success (`completed` + `ready`) / terminal (`failed` + `draft`) transitions.
- `ShouldBeUnique` (`uniqueFor` 900) and `WithoutOverlapping` (`releaseAfter` 120, `expireAfter` 180).
- DOCX ZIP/XML hardening (compression ratio, uncompressed and `document.xml` caps, encrypted archive rejection, `LIBXML_NONET`, DTD/entity substitution disabled).
- `ext-zip` platform requirement test.
- Local `composer dev` queue listener `--queue=material-extraction,default`.

Changed:

- Phase 2.4 content extraction is technically complete. Phase 2 status remains `IN PROGRESS`. Phase 2 Definition of Done is not met.
- Upload factory `file_path` uses `{user_id}/{uuid}.pdf`.

Database Impact:

- None. No migration. No DBML change.

Notes:

- SHA-256 hashing, duplicate protection, 10 MB upload limit, and upload compensation are unchanged.
- Extracted UTF-8 content limit is 10 MiB (`10 * 1024 * 1024` bytes) with no truncation.
- Job: `tries` 3, `timeout` 60, `failOnTimeout` true, `backoff` `[10, 30, 60]`, `afterCommit` true, payload `material_id` only.
- Queue `retry_after` remains 90 from `config/queue.php`.
- Dedicated worker direction: `php artisan queue:work --queue=material-extraction --timeout=60 --tries=3 --memory=256`.
- `ShouldBeUnique` and `WithoutOverlapping` require a shared production cache lock store (`database` or Redis). Array and per-host file cache are not production-safe for multi-host coordination.
- Dispatch failure after persist keeps the material row, source file, `draft`, and `pending`. It does not invoke upload compensation.
- Automatic Laravel queue retry is implemented. No `RetryMaterialExtraction` Action. Manual user retry is deferred to later Material UI/controller work.
- Remaining Phase 2: topic management, ownership/authorization, archive/restore, controllers/routes, Blade UI, and Phase 2 final integration.

## v0.6.2 Phase 2.3 Private Storage and Usage

- Date: 27 August 2026
- Version: 0.6.2
- Phase: Phase 2 - Material Management
- Type: Feature implementation

Added:

- Material domain foundation: `materials` / `material_topics` migrations, models, and enums.
- Text and upload creation actions with Form Request MIME, extension, and 10 MB validation.
- Dedicated private `materials` disk at `storage/app/materials` (`serve => false`, no public URL).
- SHA-256 inspection metadata and UUID internal storage paths `{user_id}/{uuid}.{extension}`.
- Duplicate protection aligned with UNIQUE `(user_id, file_hash)`, including `withTrashed()` pre-check.
- Independent UUID paths for concurrent same-user uploads; UNIQUE loser compensates only its own file.
- Post-store database failure compensation; cleanup failure is logged without replacing the original exception.
- `MaterialUsageCalculator` integer-byte SUM of non-deleted upload `file_size`.

Changed:

- Phase 2 status is `IN PROGRESS`. Phase 2.3 private storage and usage is technically complete. Phase 2 Definition of Done is not met.

Database Impact:

- No schema change during Phase 2.3.
- Canonical `materials` unique `(user_id, file_hash)` and nullable `file_size` remain as defined in Phase 2.1.

Notes:

- No new Composer dependency.
- Content extraction is not implemented (`NOT STARTED`).
- Quota enforcement remains deferred to Phase 3.
- Archived and extraction-failed uploads count toward usage; text, soft-deleted, and other-user rows do not.

## v0.6.1 Phase 2.1 Schema Refinement

- Date: 24 August 2026
- Version: 0.6.1
- Phase: Phase 2 - Material Management
- Type: Schema refinement

Added:

- `material_topics.sort_order` unsigned integer, default `0`, to preserve topic order for later AI/content processing.
- Local MySQL/Laragon development requirement with `DB_CONNECTION=mysql`.

Changed:

- Canonical DBML and database reference now include topic sort order and `(material_id, sort_order)` index.

Database Impact:

- Pending `create_material_topics_table` migration includes `sort_order` before the table is applied.
- Materials core columns are unchanged.
- Rollback still drops only `material_topics` then `materials`.

Notes:

- Phase 2.2 is not included.
- Automated tests continue to use SQLite in-memory via `phpunit.xml`.

## v0.6 Phase 2 Material Design Alignment

- Date: 24 August 2026
- Version: 0.6
- Phase: Phase 2 - Material Management
- Type: Canonical design decision

Added:

- Standalone Material Management flow from the user dashboard.
- Canonical PDF, DOCX, and TXT allowlist with a temporary 10 MB per-file limit.
- Owner restore transition from archived material back to ready.

Changed:

- Phase 2 remains Blade/controller based without Livewire.
- Material content uses LONGTEXT.
- Upload metadata requires internal path, size, MIME type, SHA-256 hash, and extraction status.
- Duplicate protection uses unique `(user_id, file_hash)`.
- Empty chapter and sub-chapter values normalize to non-null empty strings.
- Storage usage counts every non-deleted upload, including archived and extraction failed.
- Material lifecycle is `draft|ready -> archived` and `archived -> ready`.

Database Impact:

- Canonical `materials` and `material_topics` definitions are aligned before migrations are implemented.
- No migration or application code is included in this documentation-only decision.

Notes:

- Phase 2 remains `PLANNED`.
- PDF/DOCX extraction dependencies still require explicit approval before Composer changes.
- Plan-specific quota values remain deferred to Phase 3.

## v0.5 Phase 1 Authentication

- Date: 21 August 2026
- Version: 0.5
- Phase: Phase 1 - Authentication and User Management
- Type: Feature implementation

Added:

- Google OAuth-only authentication with Laravel Socialite.
- User provisioning and Google profile synchronization.
- USER and ADMIN roles with composite pivot relation.
- First-login phone setup backed by `whatsapp_contacts`.
- Account status, profile completeness, and role middleware.
- User and admin dashboard.
- Authentication, relationship, profile, and authorization tests.

Changed:

- Phase 1 uses Laravel conventional `id` primary keys.
- Free subscription provisioning is deferred to Phase 3.
- WhatsApp contact identity is created in Phase 1; CRM operations remain Phase 7.
- Socialite compatibility uses Guzzle 7 instead of the previous Guzzle 8 lock.

Database Impact:

- Added Google identity, profile, consent, status, and login fields to users.
- Removed local password and password reset storage.
- Added roles, role_user, and whatsapp_contacts.
- Framework sessions, cache, and jobs tables remain infrastructure tables.
- OAuth migration requires empty legacy authentication data and blocks rollback while users exist.

Notes:

- No material, subscription, AI, question bank, monitoring, or broadcast feature is implemented.
- Google credentials must be configured outside the repository.

## v0.4 System Design Alignment

- Date: 21 August 2026
- Version: 0.4
- Phase: Phase 0 - Foundation
Type: Documentation and database design

Added:

- Canonical DBML with 16 domain entities.
- Database enums, indexes, unique constraints, and relationship rules.
- Detailed user flow with OAuth, material, quota, queue, Gemini, review, and failure paths.
- Detailed admin flow with MVP management/monitoring plus the deferred Phase 7 broadcast branch.
- JSON output contract for multiple choice, true/false, and essay.
- Definition of Done for every development phase.

Changed:

- Authentication is explicitly Google OAuth only.
- Frontend is explicitly Blade + Livewire.
- Google Gemini is the MVP AI provider.
- AI generation is asynchronous and audited.
- Subscription/quota foundation is moved before AI generation.
- MVP boundary is Phase 0-6 with manual subscription handling.
- Admin uses the same OAuth flow and is authorized by role.
- Question output is discriminated by question type; essay no longer requires options.
- Institution features and WhatsApp CRM are post-MVP.

Fixed:

- Resolved conflict between essay questions and mandatory options.
- Resolved missing runtime quota source using `ai_usage_logs`.
- Resolved missing topic relation from generation to material topic.
- Resolved unclear distinction between assessment type and question type.
- Corrected Phase 0 status from implied completion to `IN PROGRESS`.

Database Impact:

- `users.google_id` and `email` are required and unique; no local password.
- Added enum-backed status/type domains.
- Added audit fields for Gemini response, token, cost, retry, and queue timestamps.
- Added subscription relation to AI usage for quota calculation.
- Added review fields to question sets.
- Added consent, opt-out, and provider tracking to WhatsApp/broadcast tables.

Notes:

- This release changes documentation and DBML only.
- Laravel migrations and application modules have not been implemented.
- Source design: `docs/database/AI_QUESTION_BANK.dbml`.

## v0.3 Documentation

- Date: Before 21 August 2026
- Version: 0.3
- Phase: Phase 0 - Foundation
Type: Documentation

Added:

- Initial PRD.
- Initial system design.
- Initial prompt engine rules.
- Initial development rules.

Notes:

- Documents were high-level and were expanded in v0.4.

## v0.2 Database Design

- Date: Before 21 August 2026
- Version: 0.2
- Phase: Phase 0 - Foundation
Type: Database planning

Added:

- List of 16 core entities.
- Subscription layer.
- CRM layer.

Notes:

- This version contained an entity inventory, not a complete executable schema.

## v0.1 Project Planning

- Date: Before 21 August 2026
- Version: 0.1
- Phase: Phase 0 - Foundation
Type: Product planning

Added:

- Product concept.
- Initial user flow.
- Initial admin flow.
- Initial database direction.