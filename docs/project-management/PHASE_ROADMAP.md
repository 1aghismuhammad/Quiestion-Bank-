# Development Phase Roadmap

## Roadmap Rules

Status:

- `IN PROGRESS`: sedang disiapkan.
- `PLANNED`: belum dimulai.
- `DEFERRED`: sengaja ditunda dari MVP.
- `COMPLETE`: seluruh Definition of Done terpenuhi.

MVP mencakup Phase 0-6. Phase 7 adalah post-MVP. Optimization dimulai sejak awal dan diperdalam pada Phase 8.

Urutan diperbarui agar quota tersedia sebelum AI generation dan admin flow tersedia sebelum MVP dirilis.

## Phase 0 - Foundation

Status: `COMPLETE`

Scope:

- PRD dan MVP acceptance criteria.
- Architecture decisions.
- User dan admin flow.
- DBML, ERD, relationship, index, dan migration order.
- Prompt output contract.
- Development rules.

Definition of Done:

- Semua dokumen menggunakan istilah dan scope yang sama.
- DBML dapat dikompilasi menjadi SQL.
- Google-only OAuth, Blade+Livewire, Gemini, dan tiga question type terdokumentasi.
- Keputusan arsitektur yang memblokir Phase 1 sudah diselesaikan.
- Open decision lain memiliki target phase sebelum implementasinya dimulai.

## Phase 1 - Authentication and Authorization

Status: `COMPLETE`

Scope:

- Laravel Socialite dan Google OAuth.
- User provisioning dan profile sync.
- Roles USER/ADMIN.
- First-login WhatsApp contact setup.
- User status enforcement.
- Route middleware; policy resource mulai diterapkan saat modul ownership tersedia pada Phase 2.
- Logout dan session termination.

Definition of Done:

- Tidak ada email/password registration.
- User baru menerima role USER.
- User tanpa nomor telepon diarahkan ke profile setup.
- Admin route ditolak untuk non-admin.
- OAuth success/failure, suspended user, profile setup, relationship, dan admin authorization memiliki feature test.

## Phase 2 - Material Management

Status: `COMPLETE`

Technical slices complete:

- Material domain foundation (schema, models, enums).
- Material creation flow (text and upload actions).
- Upload MIME and extension validation (10 MB per-file safety limit; remains in MVP).
- Private storage (`storage/app/materials`, unserved disk).
- SHA-256 metadata and internal UUID file paths.
- Duplicate protection and upload failure compensation.
- Storage usage accounting (integer bytes; account quota enforced in Phase 3.3 + 3.4).
- Content extraction queue (`COMPLETE`): PDF, DOCX, and TXT via `material-extraction`; after-commit upload dispatch; unique and overlap locks; guarded `pending|failed` → `processing` → `completed`/`failed`.
- Topic management (`COMPLETE`): chapter, sub-chapter, topic, focus area, sort order, and optional page range via Material Topic Actions.
- Material ownership / authorization (`COMPLETE`): owner-only `MaterialPolicy`; ADMIN does not receive global Material access.
- Archive / restore lifecycle (`COMPLETE`): owner `draft|ready -> archived` and `archived -> ready`; archive is Material status, not soft delete.
- Material web management (`COMPLETE`): authenticated Blade/controller Material UI with owner-scoped listing, detail, edit, topics, archive, and restore. Phase 5.7A retired HTTP/UI text creation; new create is upload-only. Legacy text rows remain.
- Phase 2 final integration / QA / documentation closure (`COMPLETE`).

Current enhancement program: Phase 5.7 (`IN PROGRESS`). Phase 5.7A (upload-only Material creation) is `COMPLETE`. Phase 5.7B1 (Material Profile foundation) is `COMPLETE`. Phase 5.7B2 (sequential map/reduce provider calls) is `COMPLETE`. Phase 5.7B3 (owner activation, progress, review, and regeneration UI) is `COMPLETE`. Phase 5.7C has not started. The next numbered main phase remains Phase 6 Admin Dashboard (`PLANNED`). Phase 5 Question Bank is `COMPLETE`. Phase 3 and Phase 4 are `COMPLETE`.

Scope:

- Material menu berdiri sendiri dari dashboard tanpa dependency question set.
- Material upload PDF, DOCX, dan TXT dengan batas 10 MB per file (tetap berlaku; terpisah dari quota storage akun).
- Legacy text rows (`source_type=text`) remain readable/editable. New HTTP text creation was retired in Phase 5.7A.
- File validation dan private storage.
- Content extraction queue.
- Chapter, sub-chapter, topic, dan focus.
- Ownership policy dan storage usage.
- Lifecycle `draft|ready -> archived` dan owner restore `archived -> ready`.

Definition of Done:

- Upload dan text source tervalidasi.
- Extraction success/failure dapat dipantau.
- User tidak dapat mengakses material user lain.
- Storage usage dapat dihitung dari seluruh upload non-deleted, termasuk archived dan extraction failed.
- Duplicate file milik user yang sama ditolak melalui `(user_id, file_hash)`.

## Phase 3 - Subscription and Quota Foundation

Status: `COMPLETE`

Technical slices:

- Phase 3.1 + 3.2 Plan and Subscription domain (`COMPLETE`): `plans` catalog; canonical Free and Pro via idempotent `PlanSeeder`; finite Pro windows; no Free subscription rows.
- Phase 3.3 + 3.4 (`COMPLETE`):
  - active entitlement resolver (`ResolveUserEntitlement`)
  - account storage quota enforcement (`Plan.storage_limit_bytes`; Free 50 MiB / Pro 500 MiB total)
  - 10 MB per-file upload limit remains a separate MVP safety control
- Phase 3.5 + 3.6 (`COMPLETE`):
  - generation quota definition (`ResolveGenerationQuota`; no consumption)
  - subscription/quota user UI (`/account/subscription`)
  - Plan Offers (`pro_1m` / `pro_3m`)
  - static QRIS on the Laravel public disk
  - WhatsApp payment confirmation
  - minimum manual admin verification (`/admin/subscription-upgrades`)
  - no payment gateway

Generation credit reservation, generation usage enforcement, failed-generation credit release, and `ai_usage_logs` runtime are **Phase 4.1+4.2 (`COMPLETE`)**. Gemini structured MCQ generation and async orchestration are **Phase 4.3+4.4 (`COMPLETE`)**. Generation UI and stale recovery are **Phase 4.5+4.6 (`COMPLETE`)**.

Scope:

- Seed Free dan Pro plan as entitlement catalog (not Free subscription rows).
- Subscription lifecycle for paid Pro windows.
- Account storage quota from Plan (enforced in 3.3 + 3.4).
- Generation quota foundation, user quota UI, and manual upgrade/payment (3.5 + 3.6).

Definition of Done:

- Entitlement efektif: Pro window valid atau fallback Free.
- Concurrent file-upload request tidak dapat melewati quota storage. (verified on local MySQL)
- User dapat melihat paket, storage, dan allowance generation. Phase 4.5 menampilkan Terpakai (charged), Diproses (reserved), dan Tersedia (`max(0, available)`) tanpa DTO kuota kedua.
- Upgrade manual dapat disetujui, ditolak (alasan wajib), atau dibatalkan admin; approval menulis window Pro.

Catatan: payment gateway dan invoice otomatis tidak termasuk MVP. Phase 4 generation runtime, UI, dan stale recovery sudah `COMPLETE`. Question Bank Phase 5 is `COMPLETE` (MCQ-only MVP).

## Phase 4 - AI Question Engine

Status: `COMPLETE`

Technical slices:

- Phase 4.1 AI Generation Domain Foundation (`COMPLETE`): `ai_generations` lifecycle, enums, owner-only `MaterialPolicy::generate`, eligibility after authorization.
- Phase 4.2 Generation Usage & Quota Runtime (`COMPLETE`): stateful one-row-per-Generation `ai_usage_logs` (`reserved|charged|released`); Start reserves; Consume/Release finalize the stored reservation; Free lifetime and Pro window accounting.
- Phase 4.3 + 4.4 Gemini + structured output + async orchestration (`COMPLETE`)
- Phase 4.5 Generation Web UI / Result Preview (`COMPLETE`): owner Blade create/show/history; JSON status poll ~5s; completed MCQ preview; failed Retry.
- Phase 4.6 Reliability / Stale Recovery / Phase Closure (`COMPLETE`): `generations:recover-stale` every minute `withoutOverlapping(10)`; runtime stale floor 1800s; `stale_recovery`; no schema change.

Scope (full Phase 4):

- Prompt identity via config/`McqPromptBuilder::version()` persisted per provider attempt (no `prompt_versions` table in 4.3).
- Google Gemini adapter.
- Queue-based generation.
- Multiple choice runtime in 4.3+4.4; true/false and essay remain later types.
- Output schema validation.
- Token metadata where the provider returns it (no monetary cost column).
- Retry lineage dan failure handling (automatic retry = same Generation; manual retry = new Generation + `parent_generation_id` in Start).
- `ai_usage_logs` runtime, reservation, charge, dan release.
- Penegakan limit generation dari `ResolveGenerationQuota`.

Definition of Done (full Phase 4):

- Runtime 4.3+4.4 menghasilkan JSON MCQ valid (empat opsi A–D, satu jawaban, explanation). True/false dan essay belum dijalankan provider.
- Hasil terstruktur tervalidasi disimpan di `ai_generations.result_json`; raw prompt / full raw provider response tidak di-persist.
- Timeout, invalid JSON, dan provider failure tertangani (max 3 HTTP; Job tries terpisah).
- Automatic retry memakai Generation dan reservation yang sama; `execution_token` membedakan resume vs duplicate Job.
- Generation gagal (terminal) tidak mengurangi credit permanen (Release).
- Validator MCQ dan Job/attempt memiliki automated tests. MySQL races A–E passed on local MySQL.
- Phase 4 tidak membuat `question_sets`. Preview generation adalah read-only Phase 4.5. Question Bank persistensi adalah Phase 5 import eksplisit.
- Owner-only generation routes; cross-user Generation ID adalah 404; Material 403 tidak diubah.
- Stale queued/processing reserved orphans recover to `failed` + `released` without provider HTTP. Runtime TTL is `max(1800, configured)`; 1800s is the minimum safe floor and operators may raise it. Scheduler `generations:recover-stale` every minute `withoutOverlapping(10)`.
- No Phase 4.5/4.6 schema migration.

4.5+4.6 Definition of Done:

- Owner Blade flow: Material → create form (MCQ only; defaults `question_count=5`, `output_language=id`) → Start → show queued/processing/completed/failed.
- Global owner-only `GET /generations` (15 per page). Status JSON is exactly `generation_status` + `terminal`. Vanilla poll ~5s reloads on any status change. No Cancel.
- Manual Retry: failed only; new Generation + new reservation; `parent_generation_id` in Start TX after proving parent is same-user `failed` + Usage `released`; re-check ownership, eligibility, quota.
- Stale recovery for queued (`queued_at`) and processing (`updated_at`) reserved orphans; `error_code=stale_recovery`; leave processing `execution_token`; do not modify STARTED attempts.
- Quota UI reuses `GenerationUsageSnapshot`. Display clamp `max(0, available)` only.
- No schema migration. No Question Bank persist/import.

4.3+4.4 Definition of Done:

- Gemini HTTP `generateContent` with server-side MCQ validation and targeted repair.
- Dedicated `database-generation` connection; existing extraction `retry_after` 90 unchanged.
- `attempt_number` starts at 0; 1/2/3 means provider HTTP started; never 4.
- Nullable `output_language` for legacy rows; new Start requires `id`/`en`; Job fail-closed if missing.

4.1+4.2 Definition of Done:

- `ai_generations` and unique `ai_usage_logs.generation_id` exist with restrictive FKs.
- Start locks User then reloads Material; ownership is 403; ineligible owned Material is ValidationException.
- `available = live Plan.generation_limit - charged - reserved` for current Free lifetime or current Pro window.
- Consume/Release do not re-resolve current entitlement and nest inside a later outer transaction.
- No Gemini, no generation UI, no Question Bank.

## Phase 5 - Question Bank

Status: `COMPLETE`

Technical slices:

- Phase 5.1–5.3 Batch 1 (`COMPLETE`): schema + models + ownership; explicit import of a completed MCQ Generation into a draft Question Set; owner list/detail.
- Phase 5.4–5.6 Batch 2 (`COMPLETE`): draft MCQ edit; atomic whole-set save; publish `draft → published`; published read-only; integrity; QA/docs.

Delivered MVP scope (MCQ-only):

- Explicit import of a completed MCQ Generation into a draft Question Set (one Generation → at most one Question Set, `UNIQUE(generation_id)`).
- Owner list/detail.
- Draft MCQ edit on one page (title, stems, A–D, correct letter via `question_options.is_correct`, explanation).
- Atomic whole-set save of existing questions only.
- Publish `draft → published` after persisted MCQ integrity. Repeat publish is idempotent.
- Published sets are read-only.
- Owner-only; foreign IDs 404; Admin has no ownership bypass.
- Generation `result_json` unchanged.
- No additional generation quota charge.
- No Gemini calls during edit/publish.
- No Phase 5 Batch 2 migration.

Out of scope (deferred to a later explicitly scoped phase; not part of Phase 5 DoD):

- Manual question creation.
- Add/delete/reorder questions.
- True/false and essay Question Bank support.
- Unpublish / archive / delete / restore.
- Public visibility and admin review.

Definition of Done (delivered Phase 5 MVP):

- Owner can import a completed MCQ Generation into a draft Question Set.
- Owner can list and view owned Question Sets.
- Owner can edit draft MCQ content and save atomically.
- Owner can publish `draft → published`; published is read-only.
- Persisted questions must be `multiple_choice` with canonical A–D options and exactly one correct option before edit mutation and before publish.
- Ownership policy is active on mutations; foreign IDs including Admin are 404.
- Question Set lifecycle has feature tests. Automated tests, MySQL concurrency QA, and owner browser QA passed.

The original full-Phase-5 wording that a user can save and edit all three question types is **not** the delivered MVP. True/false and essay Question Bank remain later.

Current enhancement program: Phase 5.7 (`IN PROGRESS`). Phase 5.7A is `COMPLETE`. Phase 5.7B1, Phase 5.7B2, and Phase 5.7B3 are `COMPLETE`. Phase 5.7C has not started. The next numbered main phase remains Phase 6 Admin Dashboard (`PLANNED`).

## Phase 5.7 - Pre-Phase-6 enhancements

Status: `IN PROGRESS` (Phase 5.7A `COMPLETE`; Phase 5.7B1 `COMPLETE`; Phase 5.7B2 `COMPLETE`; Phase 5.7B3 `COMPLETE`; 5.7C not started)

Phase 5.7A — Upload-only Material Transition:

- New Material creation is upload-only (PDF, DOCX, TXT; 10 MB per file).
- `POST /materials/text` and `materials.store-text` are removed.
- Legacy `source_type=text` rows remain readable and editable (title and content); archive/restore and generation eligibility are unchanged.
- `CreateTextMaterial` and `SourceType::TEXT` remain for legacy/internal use. No file replacement. No schema migration.
- Users above effective storage quota cannot create a new Material because upload is the only creation path.

Phase 5.7B1 — Material Profile Foundation (`COMPLETE`):

- Five profile tables, models, factories, enums, hasher, UTF-8 splitter, eligibility, queue/claim/heartbeat/ready/failure/recovery Actions, and `profiles:recover-stale`.
- Foundation only: no Gemini/provider HTTP, no production profile job, no profile HTTP route or UI.
- Canonical content cap is 240,000 UTF-8 code points. The generation 80,000-character cap is not used for profile eligibility.
- One `workflow_token` per Profile Version. `step_execution_token` is supplied at claim. Processing lease 120s is separate from queued abandonment 900s.

Out of scope for 5.7B1: production jobs, Gemini, profile HTTP/UI, blueprint, generation Start changes, quota/usage writes, DOCX.

Phase 5.7B2 — Sequential Material Profile Map/Reduce Provider Calls (`COMPLETE`):

- Dedicated `MaterialProfileAnalysisProvider` contract; `identity()` supplies sanitized pre-call provider name. Gemini wire format stays inside `GeminiMaterialProfileProvider`.
- `StartMaterialProfileAnalysis` reuses a fingerprint-matching ready Version, rejects a queued or processing Version with `in_flight_exists`, and enforces three new Profile Versions per User per rolling hour with `throttle_exceeded`.
- `DispatchNextMaterialProfileStep` dispatches exactly one next Step: maps in ascending `step_index`, reduce only after every required map is ready. Production jobs are `AnalyzeMaterialProfileMapJob` and `ReduceMaterialProfileJob` (`tries = 3`, `timeout = 270`, `failOnTimeout = false`, `database-generation` / `material-intelligence`).
- One unchanged `workflow_token` per Version, one distinct `step_execution_token` per Step, and retries that retain the same serialized Step token. An expired processing `failed()` is a no-op; recovery writes `stale_recovery`.
- Map input is one canonical core plus at most 400 characters of labelled overlap; evidence is validated against the core as UTF-8 code-point offsets and one invalid candidate rejects the complete response.
- Reduce input includes every persisted extracted Element; `max_map_candidates * max_chunks <= max_reduce_summaries`. Reduce revalidates the Material fingerprint before Attempt/HTTP. Reduce output requires at least one topic, objective, and indicator.
- Started Attempt provider/model/prompt/purpose are immutable. Ready finalization independently proves extracted vs suggested Element invariants.
- Three provider Attempts per Step maximum, provider HTTP outside transactions, atomic map persistence, and atomic reduce-ready plus Version-ready finalization.
- No migration, no Composer dependency, no generation credit, and no `ai_usage_logs` write.

Phase 5.7B3 — Owner Activation, Progress, Review, and Regeneration UI (`COMPLETE`):

- Authenticated owner routes: `GET /materials/{material}/profile`, `GET /materials/{material}/profile/status`, `POST /materials/{material}/profile`, `POST /materials/{material}/profile/regenerate`.
- One canonical read path (`ResolveMaterialProfileOwnerView`) distinguishing `none`, `queued`, `processing`, `ready`, `failed`, and `stale` using the same fingerprint contract as start.
- Bounded, sanitized status JSON with a fixed field allowlist, `Cache-Control: no-store`, no side effects, and polling only while queued or processing.
- Review page groups topics, learning objectives, indicators, and other constraints, distinguishes extracted from suggested items, and shows escaped evidence only for validated extracted elements.
- Explicit POST regeneration that creates a new Version, rejects an active workflow, respects the throttle, and never mutates terminal data.
- Centralized owner-safe Indonesian error mapping; internal authority/concurrency codes are never copied into owner JSON. No token, Attempt, provider payload, or raw exception text is exposed.

Out of scope for 5.7B2 and 5.7B3: profile element editing, approve/reject persistence, manual ordering, blueprint creation, generation-run integration, Advanced Mode, DOCX changes, admin profile management, notification workflow, and any credit or usage accounting.

## Phase 6 - Admin Dashboard

Status: `PLANNED`

Scope:

- User management.
- Question bank management dan review.
- AI generation monitoring.
- AI usage monitoring.
- Subscription monitoring.
- Full admin billing portal (minimum payment verification already exists in Phase 3.6).
- Admin authorization dan action confirmation.

Definition of Done:

- Seluruh modul Phase 6 pada admin flow tersedia; branch broadcast Phase 7 tidak termasuk.
- Admin action memiliki policy dan audit yang memadai.
- Non-admin tidak dapat mengakses endpoint atau Livewire action admin.
- Monitoring tidak mengekspos raw secret atau OAuth token.

## MVP Release Gate

MVP dapat dirilis setelah Phase 0-6 selesai dan:

- backup serta restore diuji;
- queue worker dan scheduler termonitor;
- upload dan AI endpoint memiliki rate limit;
- smoke test user flow dan admin flow lulus.

Nilai quota Free/Pro sudah diputuskan dan di-seed: Free 50 MiB storage dan 2 generation lifetime; Pro 500 MiB storage dan 100 generation per jendela bulanan entitlement.

## Phase 7 - WhatsApp CRM

Status: `DEFERRED`

Scope:

- WhatsApp contact verification.
- Marketing consent dan opt-out.
- Campaign composition dan segmentation.
- Queue broadcast.
- Delivery webhook dan log.

Definition of Done:

- Pesan hanya dikirim kepada contact yang consent.
- Duplicate recipient dicegah.
- Delivery, read, dan failure status dapat dipantau.
- Provider failure tidak menggagalkan seluruh campaign.

## Phase 8 - Optimization and Scaling

Status: `PLANNED`

Scope:

- Security hardening.
- Query dan index optimization.
- Cache dan queue scaling.
- Object storage.
- Monitoring, alerting, dan cost control.
- Load, resilience, dan recovery test.

Definition of Done:

- Bottleneck diukur sebelum optimization.
- Critical query memiliki index dan benchmark.
- Worker dapat diskalakan secara horizontal.
- Incident, backup, dan recovery procedure terdokumentasi.

## Future Product Tracks

- Institution organization, membership, seat, dan shared question bank.
- Automated payment, invoice, dan renewal.
- LMS export dan integration.
- Multi-provider AI.