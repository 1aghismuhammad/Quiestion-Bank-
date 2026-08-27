# Development Phase Roadmap

## Roadmap Rules

Status:

- `IN PROGRESS`: sedang disiapkan.
- `PLANNED`: belum dimulai.
- `DEFERRED`: sengaja ditunda dari MVP.
- `DONE`: seluruh Definition of Done terpenuhi.

MVP mencakup Phase 0-6. Phase 7 adalah post-MVP. Optimization dimulai sejak awal dan diperdalam pada Phase 8.

Urutan diperbarui agar quota tersedia sebelum AI generation dan admin flow tersedia sebelum MVP dirilis.

## Phase 0 - Foundation

Status: `DONE`

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

Status: `DONE`

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

Status: `IN PROGRESS`

Technical slices complete (Phase 2 Definition of Done not met):

- Material domain foundation (schema, models, enums).
- Material creation flow (text and upload actions).
- Upload MIME and extension validation (temporary 10 MB per file).
- Private storage (`storage/app/materials`, unserved disk).
- SHA-256 metadata and internal UUID file paths.
- Duplicate protection and upload failure compensation.
- Storage usage accounting (integer bytes; quota deferred to Phase 3).
- Content extraction queue (`COMPLETE`): PDF, DOCX, and TXT via `material-extraction`; after-commit upload dispatch; unique and overlap locks; guarded `pending|failed` → `processing` → `completed`/`failed`.
- Topic management (`COMPLETE`): chapter, sub-chapter, topic, focus area, sort order, and optional page range via Material Topic Actions.
- Material ownership / authorization (`COMPLETE`): owner-only `MaterialPolicy`; ADMIN does not receive global Material access.

Remaining Phase 2 scope (not started or not complete):

- Archive / restore lifecycle (`draft|ready -> archived` and owner restore `archived -> ready`).
- Controllers, routes, and Blade Material Management UI.
- Phase 2 final integration / QA.

Scope:

- Material menu berdiri sendiri dari dashboard tanpa dependency question set.
- Material upload PDF, DOCX, dan TXT dengan batas sementara 10 MB per file.
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

Status: `PLANNED`

Scope:

- Seed Free dan Pro plan.
- Provision Free subscription untuk user yang belum memiliki subscription.
- Subscription lifecycle.
- Generation credit per billing period.
- Storage limit.
- Credit reservation dengan expiry, charge, dan release.
- Approval manual oleh admin.
- Notifikasi email untuk hasil approval.

Definition of Done:

- Maksimal satu subscription aktif per user.
- Concurrent request tidak dapat melewati quota.
- Generation gagal tidak mengurangi credit permanen.
- Upgrade manual dapat disetujui atau ditolak admin.

Catatan: payment gateway dan invoice otomatis tidak termasuk MVP.

## Phase 4 - AI Question Engine

Status: `PLANNED`

Scope:

- Prompt version management.
- Google Gemini adapter.
- Queue-based generation.
- Multiple choice, true/false, dan essay.
- Output schema validation.
- Token/cost audit.
- Retry lineage dan failure handling.

Definition of Done:

- Ketiga question type menghasilkan JSON valid.
- Raw response dan parsed output tercatat.
- Timeout, invalid JSON, dan provider failure tertangani.
- Retry tidak menimpa audit generation sebelumnya.
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
- Subscription approval/rejection.
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
- smoke test user flow dan admin flow lulus;
- nilai quota Free/Pro telah diputuskan.

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