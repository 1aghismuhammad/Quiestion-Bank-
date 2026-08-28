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
- Material web management (`COMPLETE`): authenticated Blade/controller Material UI with owner-scoped listing, create text/upload, detail, edit, topics, archive, and restore.
- Phase 2 final integration / QA / documentation closure (`COMPLETE`).

Next phase: Phase 4 - AI Question Engine (`PLANNED`). Phase 3 is `COMPLETE`.

Scope:

- Material menu berdiri sendiri dari dashboard tanpa dependency question set.
- Material upload PDF, DOCX, dan TXT dengan batas 10 MB per file (tetap berlaku; terpisah dari quota storage akun).
- Text input.
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

Generation credit reservation, generation usage enforcement, failed-generation credit release, and `ai_usage_logs` runtime belong to **Phase 4**, together with Gemini integration.

Scope:

- Seed Free dan Pro plan as entitlement catalog (not Free subscription rows).
- Subscription lifecycle for paid Pro windows.
- Account storage quota from Plan (enforced in 3.3 + 3.4).
- Generation quota foundation, user quota UI, and manual upgrade/payment (3.5 + 3.6).

Definition of Done:

- Entitlement efektif: Pro window valid atau fallback Free.
- Concurrent file-upload request tidak dapat melewati quota storage. (verified on local MySQL)
- User dapat melihat paket, storage, dan allowance generation (tanpa used/remaining).
- Upgrade manual dapat disetujui, ditolak (alasan wajib), atau dibatalkan admin; approval menulis window Pro.

Catatan: payment gateway dan invoice otomatis tidak termasuk MVP. Runtime konsumsi generation adalah Phase 4.

## Phase 4 - AI Question Engine

Status: `PLANNED` (not started)

Scope:

- Prompt version management.
- Google Gemini adapter.
- Queue-based generation.
- Multiple choice, true/false, dan essay.
- Output schema validation.
- Token/cost audit.
- Retry lineage dan failure handling.
- `ai_usage_logs` runtime, reservation, charge, dan release.
- Penegakan limit generation dari `ResolveGenerationQuota`.

Definition of Done:

- Ketiga question type menghasilkan JSON valid.
- Raw response dan parsed output tercatat.
- Timeout, invalid JSON, dan provider failure tertangani.
- Retry tidak menimpa audit generation sebelumnya.
- Generation gagal tidak mengurangi credit permanen.
- Semua output validator memiliki unit test.

## Phase 5 - Question Bank

Status: `PLANNED`

Scope:

- Question set manual dan hasil AI.
- Review dan edit oleh user.
- Draft, publish, dan archive.
- Optional admin review.
- Private/public visibility.
- Export format pertama setelah format diputuskan.

Definition of Done:

- User dapat menyimpan dan mengedit ketiga tipe soal.
- Invariant options dan jawaban benar tervalidasi.
- Policy ownership aktif pada seluruh mutation.
- Lifecycle question set memiliki feature test.

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