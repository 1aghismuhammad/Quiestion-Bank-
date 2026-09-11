# Database Reference

## Source of Truth

Schema domain canonical tersedia dalam format DBML:

`docs/database/AI_QUESTION_BANK.dbml`

DBML tersebut dapat dibuka di dbdiagram.io atau dikompilasi menjadi SQL. Dokumen ini menjelaskan aturan bisnis yang tidak dapat dijamin hanya oleh diagram.

- Version: 0.15.9
- Domain entities: 32 domain entities documented in the canonical DBML
- Target implementation: Laravel 13 / MySQL 8+
- Primary key style: Laravel `id` untuk entitas Phase 1; `plan_id`, `subscription_id`, `offer_id`, `upgrade_request_id`, `material_id`, `topic_id`, `generation_id`, `usage_id`, `question_set_id`, `question_id`, `option_id`, `blueprint_series_id`, `blueprint_id`, `blueprint_row_id`, `generation_run_id`, dan PK custom Profile mengikuti custom PK
- Timestamp style: `created_at`, `updated_at`, dan `deleted_at` jika diperlukan

Tabel bawaan Laravel seperti sessions, cache, jobs, job batches, dan failed jobs tidak dihitung sebagai domain entity.

## Core Pipeline

```mermaid
flowchart LR
    U[users] --> M[materials]
    M --> T[material_topics]
    M --> PV[material_profile_versions]
    PV --> PC[material_profile_chunks]
    PV --> PS[material_profile_steps]
    PV --> PE[material_profile_elements]
    PS --> PA[material_profile_attempts]
    U --> Sub[subscriptions]
    Pl[plans] --> Sub
    Pl --> Off[plan_offers]
    U --> Req[subscription_upgrade_requests]
    Off --> Req
    Req -.-> Sub
    U --> G[ai_generations]
    M --> G
    U --> L[ai_usage_logs]
    Pl --> L
    Sub -.-> L
    G -.-> L
    R[ai_generation_runs] --> L
    U --> S[question_blueprint_series]
    M --> S
    S --> BP[question_blueprints]
    PV --> BP
    BP --> BR[question_blueprint_rows]
    BR --> BC[question_blueprint_row_contexts]
    BP --> BA[question_blueprint_attempts]
    BP --> R
    R --> RI[ai_generation_run_items]
    RI --> RS[ai_generation_run_item_spans]
    R --> G
    G --> QS[question_sets]
    QS --> Q[questions]
    Q --> O[question_options]
```

`ai_usage_logs` always belongs to a user and a Plan. Subscription is optional: Free usage is User + Free Plan with `subscription_id` null; Pro usage is User + Pro Plan + the effective Pro window captured at reservation time. Phase 5.7D subjects are XOR: exactly one of `generation_id` (legacy) or `generation_run_id` (Run). Occupancy is `SUM(credits)`. `prompt_versions` remains planned and is **not** implemented. Prompt identity in 4.3+4.4 is `ai_generation_attempts.prompt_version` (config/builder string).

## Entity Catalog

### User Management

#### `users`

Menyimpan identitas Google, profil, consent, status, dan aktivitas login.

Key constraints:

- `google_id` dan `email` wajib serta unique.
- Tidak ada password lokal.
- Status: active, suspended, inactive.
- Migration OAuth Phase 1 hanya berjalan otomatis ketika tabel legacy users dan password reset masih kosong.
- Rollback migration OAuth ditolak ketika user OAuth sudah ada agar identitas login tidak terhapus.

#### `roles`

Daftar role aplikasi. Seed Phase 1: `USER` dan `ADMIN`. `role_name` wajib unique.

#### `role_user`

Pivot many-to-many user-role dengan composite primary key `(user_id, role_id)`.

### Subscription

#### `plans`

Catalog entitlement Free dan Pro. Plan bukan harga komersial.

Kolom:

- `code` unique (`free`, `pro`)
- `name` tampilan (`Free`, `Pro`)
- `storage_limit_bytes` (Free `52428800`, Pro `524288000`)
- `generation_limit` (Free `2`, Pro `100`)
- `generation_reset_strategy` (`lifetime` atau `monthly`)
- `status` (`active` atau `inactive`)

Tidak ada `price`, `currency`, `billing_period`, atau `storage_limit_mb` pada `plans`. Durasi dan harga komersial (1 bulan / 3 bulan) ada di `plan_offers`. Institution Plan tidak di-seed.

Free adalah fallback entitlement. User tanpa Pro window yang efektif memakai batas Free. Free **tidak** disimpan sebagai baris subscription.

#### `subscriptions`

Riwayat window entitlement Pro yang berbatas waktu. Bukan payment record.

Kolom:

- `starts_at` dan `ends_at` wajib (timestamp; window efektif `[starts_at, ends_at)`)
- `status`: `active`, `expired`, `cancelled`
- `cancelled_at` nullable

Aturan aplikasi:

- Hanya window Pro. Tidak ada baris subscription Free.
- Satu user boleh punya banyak baris historis.
- Paling banyak satu window efektif pada satu instant. Unique `(user_id, status)` **tidak** dipakai agar renewal berurutan dapat menyimpan dua baris `active` yang tidak overlap.
- Pencegahan overlap window efektif adalah application-layer (`ResolveUserEntitlement` fail-closed), bukan unique constraint database.
- FK `user_id` dan `plan_id` memakai `ON DELETE RESTRICT`.
- Tidak ada hard-delete lifecycle normal. Plan yang sudah direferensikan dinonaktifkan, bukan dihapus.
- Resolver entitlement: load semua row `status=active`; validasi seluruh antrian current/future (`ends_at > now` OR `starts_at >= now`) sebagai Plan Pro dengan `starts_at < ends_at`; window efektif `[starts_at, ends_at)`; 0 → Free; 1 → Pro; 2+ → error integritas. Data stale historis tidak mengunci akun. Plan Pro inactive tetap dihormati untuk window yang sudah dibayar.
- Approval menulis tepat satu baris Subscription `status=active` memakai durasi snapshot. Satu pembelian (1 atau 3 bulan) = satu baris. Tidak ada status Subscription `scheduled` atau `pending`. Tanpa antrian Pro current/future yang valid: `starts_at` = waktu approval. Jika antrian ada: `starts_at` = `max(ends_at)` antrian itu; `ends_at` = `starts_at` plus `duration_months` dengan no-overflow. Window masa depan tetap `active`.
- Jika Pro berakhir dan counted storage melebihi limit Free: data dan akses Material existing tetap; archive dan restore tetap; upload FILE baru ditolak. User di atas kuota tidak dapat membuat Material baru.

#### `plan_offers`

Offer komersial untuk satu Plan Pro.

- Seed kanonik: `pro_1m` (1 bulan, Rp10.000) dan `pro_3m` (3 bulan, Rp25.000), currency `IDR`, integer Rupiah.
- Unique `code` (`plan_offers_code_unique`). Index `(plan_id, status, sort_order)` (`plan_offers_plan_status_sort_idx`). FK `plan_id` (`plan_offers_plan_id_fk`) `ON DELETE RESTRICT`.
- `PlanOfferSeeder` memakai `firstOrCreate` on `code` dan tidak menimpa harga/status yang sudah diubah.
- Tidak ada offer Free. Status `inactive` menyembunyikan offer dari pembelian baru.

#### `subscription_upgrade_requests`

Audit permintaan pembayaran manual. Bukan status Subscription.

- Snapshot: `offer_code`, `offer_name`, `duration_months`, `price_amount`, `currency`, plus `plan_id` / `offer_id`.
- Status: `pending`, `approved`, `rejected`, `cancelled`.
- Unique `reference_code` (`upgrade_req_reference_unique`). Unique nullable `approved_subscription_id` (`upgrade_req_approved_sub_unique`).
- Index `(user_id, status)` **tidak unique**. Satu pending per user ditegakkan dengan kunci baris `users`.
- FK `restrictOnDelete`. Nama constraint pendek (`upgrade_req_*`) agar <= 64 karakter MySQL.
- Approval memakai snapshot; Plan/Offer inactive kemudian tidak membatalkan pending yang sudah ada.

### Material Management

#### `materials`

Materi milik user. Pembuatan baru hanya melalui unggah file. Baris `source_type=text` lama tetap valid.

Aturan aplikasi:

- Source upload Phase 2 hanya menerima PDF, DOCX, dan TXT. Setiap file maksimal 10 MB (batas keselamatan MVP yang tetap berlaku).
- Source upload mewajibkan internal file path, file size, MIME type, SHA-256 file hash, dan extraction status.
- Source text mewajibkan content pada baris lama. Pembuatan teks baru melalui HTTP tidak tersedia. Penggantian file unggahan tidak didukung.
- `materials.content` menggunakan LONGTEXT agar hasil extraction tidak dibatasi kapasitas MySQL TEXT.
- Source text memakai extraction status `not_required` dan dapat langsung berubah dari draft menjadi ready.
- Source upload berjalan pending, processing, completed/failed; status material menjadi ready setelah extraction completed.
- Seluruh upload yang belum dihapus dihitung sebagai storage usage, termasuk material archived dan extraction failed.
- Kombinasi `(user_id, file_hash)` unique untuk menolak upload duplikat milik user yang sama.
- Lifecycle owner: `draft|ready -> archived` dan `archived -> ready`.
- Material Management Phase 2 berdiri sendiri dari dashboard dan tidak memiliki dependency pada question set.
- Nilai quota storage akun didefinisikan pada catalog `plans` (`storage_limit_bytes`). Enforcement: counted upload usage + ukuran file baru harus `<=` limit Plan efektif (byte persis; sama dengan limit diizinkan). Batas 10 MB per file tetap terpisah. Upload file yang ditolak tidak membuat Material, file permanen, atau job ekstraksi. Archive/restore dan materi teks lama tidak memakai quota upload. Jika Pro berakhir dan counted storage melebihi limit Free: data tetap; akses Material existing tetap; archive dan restore tetap; upload FILE baru ditolak. User di atas kuota tidak dapat membuat Material baru karena unggah adalah satu-satunya jalur create. Definisi quota generation (limit + jendela) adalah Phase 3.5. Runtime reservation/charge/release `ai_usage_logs` adalah Phase 4.1+4.2.

#### `material_topics`

Bab, sub-bab, topik, focus area, urutan, dan rentang halaman yang berasal dari satu material. Input chapter dan sub-chapter yang kosong dinormalisasi menjadi empty string non-null.

`sort_order` adalah unsigned integer dengan default `0` untuk mempertahankan urutan topik pada pemrosesan AI/konten berikutnya. Kombinasi material, chapter, sub-chapter, dan topic tetap unique; urutan tidak menjadi bagian constraint unique.

Kombinasi material, chapter, sub-chapter, dan topic dibuat unique. Index `(material_id, sort_order)` dipakai untuk membaca topik sesuai urutan.

### Material Profile (Phase 5.7B1–B3)

Phase 5.7B1 menambahkan persistence dan lifecycle Material Profile. Phase 5.7B2 menambahkan pemanggilan Gemini sekuensial dan Job produksi di schema yang sama. Phase 5.7B3 menambahkan HTTP/UI owner tanpa tabel baru. v0.15.4 adalah hardening B2+B3 tanpa migration. v0.15.5 menambahkan kegagalan Attempt/workflow yang atomik dan topologi map immediate-next yang ketat, tetap tanpa migration. Lima tabel B1 tidak diubah. Phase 5.7C+D menambahkan tabel Blueprint dan Run plus alter `ai_generations` / `ai_usage_logs` pada migration dated `2026_09_07_150001`–`150011`.

Eligible materials:

- READY text dengan `extraction_status=not_required`
- READY upload dengan `extraction_status=completed`

Batas kanonis adalah 240.000 UTF-8 code point. Batas generation 80.000 karakter tidak dipakai. Owner isolation tetap; Admin tidak bypass.

#### `material_profile_versions`

Aggregate workflow. Satu `workflow_token` di-mint sekali oleh `QueueMaterialProfileAnalysis`. Status: `queued`, `processing`, `ready`, `failed`. Unique `(material_id, version)`. Paling banyak satu versi `queued` atau `processing` per Material.

#### `material_profile_chunks`

Struktur konten deterministik. Offset UTF-8 code point (`char_start` inklusif, `char_end` eksklusif). Overlap opsional di luar core. Tidak menyimpan status, token, atau lease.

#### `material_profile_steps`

Sumber kebenaran lifecycle. Unique `(profile_version_id, purpose, step_index)`. Unique nullable `profile_chunk_id` (paling banyak satu map step per chunk; reduce memakai `null`). Map: `purpose=map`, `profile_chunk_id` wajib. Reduce: `purpose=reduce`, `step_index=0`, `profile_chunk_id` null. Phase 5.7B2 men-mint `step_execution_token` saat dispatch Step; retry Job yang sama memakai token tersimpan. Claim B1 tetap dapat menerima token caller jika Step queued belum punya token.

#### `material_profile_elements`

Elemen `extracted` atau `suggested`. Kind B1: `topic`, `objective`, `indicator`, `other`. Extracted memerlukan `source_chunk_id` pada versi yang sama, `evidence_excerpt` non-null yang persis substring kanonis, locator `core-{chunkIndex}:{charStart}-{charEnd}`, dan kedua offset di dalam chunk pemilik. Suggested tidak membawa chunk, evidence, atau offset. Setiap extracted yang lolos persist map masuk reduce; tidak ada truncasi diam-diam.

#### `material_profile_attempts`

Audit per step. Unique `(profile_step_id, attempt_number)`. Tidak menyimpan raw prompt atau raw provider body. Provider/model/prompt version/purpose yang ditulis saat Attempt mulai bersifat immutable; metadata pasca-panggilan hanya token/latency yang terbatas. Phase 5.7B2 memakai tabel ini sebagai satu-satunya audit pemanggilan provider. Analisis profil tidak menulis `ai_usage_logs` dan tidak memotong credit generation.

Lock order: User → Material → Profile Version → Steps ascending `profile_step_id` → Chunks ascending `profile_chunk_id`. Processing lease 120 detik terpisah dari queued abandonment 900 detik. Job `failed()` pada Step processing hanya berwenang selama lease masih `gt(now())`. `profiles:recover-stale` setiap menit `withoutOverlapping`. Recovery tidak menulis `ai_usage_logs`. Worker produksi `database-generation` harus mengonsumsi antrian `material-intelligence` (timeout 270, `retry_after` 360). Owner JSON tidak menampilkan `duplicate_worker`, `not_next_step`, `revoked`, atau `validation_failed` secara verbatim.

### Question Blueprint (Phase 5.7C)

Phase 5.7C menambahkan tabel Blueprint termasuk `question_blueprint_fill_events` (v0.15.7). Phase 5.7D menambahkan tiga tabel Run plus kolom pada `ai_generations` dan `ai_usage_logs`. v0.15.8 tidak menambah migrasi: span Run mensyaratkan kedua referensi element dan chunk.

#### `question_blueprint_series`

Lineage per owner Material. Beberapa Series per Material diizinkan. Paling banyak satu Blueprint `draft` per Series (validasi aplikasi, bukan partial unique index).

#### `question_blueprints`

Versi kisi-kisi. Unique `(blueprint_series_id, version)`. `lifecycle_status` `draft|confirmed`. `source` `manual|ai`. `ai_fill_status` `none|queued|processing|succeeded|failed`. `profile_version_id` nullable di schema; setiap jalur HTTP/Action C+D mensyaratkan Profil ready yang fingerprint-nya cocok. Confirm menyimpan `material_content_hash`, null-safe `material_file_hash`, dan `extractor_implementation`. Confirm idempotent. Header/rows/contexts confirmed immutable. Clone confirmed memakai draf existing jika ada.

#### `question_blueprint_rows`

Baris kisi-kisi. Unique `(blueprint_id, sort_order)`. Simple: 1–5 baris, MCQ, satu difficulty, `requested_count` total 1–10.

#### `question_blueprint_row_contexts`

Span konteks per baris. Unique `(blueprint_row_id, rank)`. ID, offset kanonis, dan `context_hash` server-owned. Dipakai untuk span Run (cap 16.000 UTF-8 di dalam mapping yang sudah ada; jangan first-N buku).

#### `question_blueprint_attempts`

Audit HTTP fill. Unique `(blueprint_id, attempt_number)`. Tidak ada raw prompt/body. Tidak menulis `ai_usage_logs`. Same-token + Attempt `started` = tidak ada panggilan provider kedua. `blueprints:recover-stale` setiap menit. Expired processing `failed()` adalah no-op.

#### `question_blueprint_fill_events`

Akuntansi throttle durable. Unique `queue_request_key` (`sha256` dari `workflow_token`). Setiap accepted AI-fill queue, termasuk retry draf yang sama, menulis satu event. Bukan credit generation dan bukan `ai_usage_logs`.

Confirmed DOCX: PhpWord 1.4.0, try/finally temp file, `deleteFileAfterSend`, filename `Kisi-Kisi-[safe-title].docx`. Stale confirmed tetap dapat diunduh dan dilabeli historis.

### AI Engine

#### `prompt_versions`

Snapshot aturan prompt dan output schema. Version number wajib unique dan hanya satu version boleh active. **Tidak diimplementasikan.** Phase 4.3+4.4 memakai string `generation.prompt_version` pada `McqPromptBuilder` dan menyimpannya per HTTP call di `ai_generation_attempts.prompt_version`.

Satu active prompt version berisi schema diskriminatif untuk ketiga question type.

Komponen:

- base prompt
- material rule
- assessment rule
- difficulty rule
- question rule
- answer rule
- explanation rule
- quality rule
- JSON output schema

#### `ai_generations`

Audit satu Generation request. Phase 4.1+4.2 created the table; Phase 4.3+4.4 added provider columns:

- actor (`user_id`) dan source material (`material_id`)
- assessment, difficulty, question type, dan `question_count` (integer 1..configurable max, default 10; runtime 4.3 is MCQ only)
- `output_language` (`id`/`en`, **nullable** for pre-4.3 rows; new Start requires a value; Job fail-closed if null/unsupported)
- `generation_status`: queued, processing, completed, failed, cancelled
- `execution_token` (nullable UUID; set on claim; DB-authoritative execution ownership)
- `error_message`, `error_code` (sanitized), `attempt_number` (default **0**; 1/2/3 = provider HTTP started)
- `result_json` (validated MCQ array; partial after each attempt; completed must equal `question_count`)
- `provider_name`, `model_name`, `input_tokens`, `output_tokens` (aggregates)
- nullable `parent_generation_id` (manual retry lineage; written in the Start transaction)
- nullable `generation_run_id`, `generation_run_item_id`, `child_index` (Phase 5.7D run children; unique `(generation_run_id, child_index)`; legacy rows remain null)
- `queued_at`, `started_at`, `completed_at` (success only), `failed_at` (terminal failure)

Ownership/history FKs use `restrict` delete. No `topic_id`, `prompt_version_id`, `prompt_version`, `raw_response`, or `parsed_output`. Do not persist raw prompt or full raw Gemini response.

Gemini dispatch, prompt builder, and output persistence are implemented in Phase 4.3+4.4. Owner Blade UI (create/show/history/status/retry) is Phase 4.5: `result_json` is rendered only when `generation_status=completed`. Automatic retry: same Generation, same Usage reservation, no extra credit. Competing Jobs with a different `execution_token` must not call the provider. Manual retry after terminal `failed`: old Generation stays `failed`, reservation `released`, new Generation + new reservation, `parent_generation_id` in the Start transaction. Cross-user Generation IDs 404 (owner-scoped lookup; no Admin bypass).

#### `ai_generation_attempts`

Per provider HTTP call. UNIQUE `(generation_id, attempt_number)`. Columns: provider, model, purpose (`initial|repair`), **`prompt_version` actually used**, requested/accepted counts, status (`started|succeeded|failed`), optional token/latency/finish/safe_error, `started_at`/`finished_at`. No raw payload. Inserted in a short transaction **before** HTTP while the Job still owns `processing` + `execution_token`.

#### `ai_usage_logs`

Stateful credit ledger. Phase 4.1+4.2 created one-row-per-Generation. Phase 5.7D adds `credits` (default 1) and XOR subject: exactly one of nullable unique `generation_id` or unique `generation_run_id`. Occupancy is `SUM(credits)` of `reserved` + `charged`. Released occupies/consumes zero. There is no `credit_used`, `usage_action`/`action_type`, or `reservation_expires_at`.

MySQL enforces XOR with CHECK `ai_usage_subject_xor_chk`. SQLite PHPUnit rebuilds the table without CHECK; application `UsageSubjectXor` plus tests enforce XOR. `migrate:rollback` is **not** safe after Run usage or `credits>1` exists. Forward-fix only.

Each row references a Plan. Subscription is nullable:

- Free: `plan_id` = catalog Free, `subscription_id` null, `window_start`/`window_end` null. Charged and reserved Free rows count toward lifetime capacity via `SUM(credits)`. Historical Free usage is not reset by Free → Pro → Free.
- Pro: `plan_id` = catalog Pro, `subscription_id` and exact `window_start`/`window_end` captured at Start from `ResolveGenerationQuota`. Current admission scopes to user + subscription + exact window + status. Live `Plan.generation_limit` is allowance. A queued future Subscription does not add current allowance.

Lifecycle:

1. Legacy `StartQuestionGeneration` inserts `ai_generations` (`queued`) and exactly one `ai_usage_logs` (`reserved`, `credits=1`, `generation_id` set, `generation_run_id` null) in the same transaction.
2. `StartGenerationRun` inserts the Run/Items/spans/first child and exactly one `ai_usage_logs` (`reserved`, `credits=ceil(n/10)`, `generation_run_id` set, `generation_id` null). Child Generations have no usage row.
3. `ConsumeGenerationCredit` / `ConsumeGenerationRunCredit`: `reserved` → `charged` (idempotent if already charged).
4. `ReleaseGenerationCredit` / `ReleaseGenerationRunCredit`: `reserved` → `released` (idempotent if already released). Opposite terminal transition is an integrity exception (no silent refund after charged).

Consume/Release finalize the **stored** reservation only. They must not re-resolve current entitlement/quota or move the row to the user's current Plan/Subscription/window. Stale queued/processing reserved **legacy** orphans are recovered by `RecoverStaleGenerations` (`generation_run_id` null) to `failed` + `released` with `error_code=stale_recovery`. Run orphans use `RecoverStaleGenerationRuns`. Runtime TTL is `max(1800, configured generation.stale_after_seconds)`: 1800 seconds is the minimum safe floor; operators may configure a higher threshold. Candidate scan is unlocked; each ID uses canonical locks and re-checks timestamps (`queued_at` for queued, `updated_at` for processing). There is no `reservation_expires_at`. `FinalizeGenerationSuccess` / `FinalizeGenerationFailure` wrap Consume/Release for **legacy** rows only and refuse run children (check `generation_run_id` before locking the child). Gemini HTTP is never inside that transaction. Recovery does not call the provider or redispatch Jobs. Blueprint AI fill writes zero usage rows.

### Generation Runs (Phase 5.7D)

#### `ai_generation_runs`

Orchestration aggregate. Unique `(user_id, idempotency_key)`. Snapshots Blueprint id/series/version, assessment, language, mode (`simple`), shuffle flags (always false in C+D), fingerprint, `total_requested_questions`, `credits_required`, nullable `parent_run_id`. Status `queued|processing|completed|failed`. Simple eligibility: confirmed current Blueprint, current matching ready Profile, MCQ, one difficulty, total 1–10.

Start idempotency: User lock first; same key + same fingerprint returns the original Run without Material lock; same key + different fingerprint rejects with no writes.

Lock order for a new key: User → Material → Profile → Series → Blueprint → Run/Items/children → Usage. Never child before Run, never Usage before Run, never Material after Run.

Worker, finalize, and recovery: User → Material → Run → items (`sort_order`) → children (`child_index`) → Usage → Attempts. Post-provider persistence continues with Profile Version → item spans → referenced elements/chunks, then rechecks token, live unexpired authority, fingerprints, span hashes, and topology before any Attempt/result/credit write.

#### `ai_generation_run_items`

Immutable row snapshots. Unique `(generation_run_id, sort_order)`. Simple: one child per item, `requested_count <= 10`.

#### `ai_generation_run_item_spans`

Bounded context spans. Unique `(generation_run_item_id, rank)`. Cap 16,000 UTF-8 code points including `\n\n` separators. Both `profile_element_id` and `profile_chunk_id` are required. Contexts or matching Profile chunks; never first-N of the book. Hash mismatch fails closed.

Sequential children: `ClaimRunChildExecution` then `RunGenerationRunChild`. Same-token + started Attempt is refused at Begin (stale/duplicate no-op) as well as at Claim resume. Future children stay queued with `queued_at` null until `DispatchQueuedRunChild` mints the first token and starts the abandonment clock. Success dispatches exactly one next child; the final child terminalizes once and charges once. Failure aborts later children and releases once. Retry of a failed Run creates a new Run, new key, `parent_run_id`, and a new reservation.

### Question Bank

#### `question_sets`

Phase 5 (`COMPLETE`). Container question milik user. **Tidak dibuat oleh job generasi Phase 4.** Persistensi hanya melalui import eksplisit generation completed MCQ. Satu `generation_id` paling banyak satu Question Set (`UNIQUE`, nullable untuk set manual di masa depan). Import menulis `status=draft`, `visibility=private`, `review_status=not_submitted`. Owner boleh mengedit draf MCQ (judul, teks, opsi A–D, jawaban benar via `is_correct`, penjelasan) dengan simpan atomik. Publish memvalidasi snapshot tersimpan lalu `draft → published` tanpa mengubah visibility atau review_status. Published read-only. Edit/publish tidak memanggil Gemini dan tidak menagih kuota generation. `result_json` generasi tidak diubah. Tidak ada migrasi Batch 2. Enum schema tetap memuat `generating` / `review` / `archived` tanpa transisi aktif ke nilai itu. True/false, essay, create manual, add/delete/reorder, unpublish, archive, visibilitas publik, dan admin review bukan Phase 5.

Admin review menggunakan `review_status` (default `not_submitted`). Tidak dijalankan di Phase 5.

#### `questions`

Menyimpan question text, type, difficulty, answer, explanation, rubric, dan points.

Question type (schema):

- `multiple_choice`
- `true_false`
- `essay`

Schema dapat menyimpan ketiga tipe. Question Bank Phase 5 hanya mengimpor, mengedit, dan menerbitkan **multiple choice**. True/false dan essay di Question Bank ditunda ke fase yang di-scope secara eksplisit.

Nomor question wajib unique dalam satu question set.

#### `question_options`

Options untuk multiple choice dan true/false.

- Multiple choice minimal empat options dan tepat satu benar.
- True/false memiliki dua options dan tepat satu benar.
- Essay tidak memiliki options serta memakai `correct_answer` dan `rubric`.

Validasi jumlah dan jawaban benar dilakukan pada domain service dalam transaction.

Saat persist essay, `parsed_output.questions[].model_answer` dipetakan ke `questions.correct_answer`.

### WhatsApp CRM

#### `whatsapp_contacts`

Satu contact WhatsApp per user, disimpan dalam format E.164. Contact menyimpan verification, consent, opt-out, dan provider ID.

`whatsapp_contacts.phone_number` menjadi sumber utama pengiriman WhatsApp. `users.phone_number` hanya profil umum.

Phase 1 mengimplementasikan identitas contact, country code, status verifikasi, consent, dan last message timestamp untuk profile setup. Provider ID dan opt-out workflow tetap Phase 7.

#### `broadcast_campaigns`

Campaign milik admin dengan message template, JSON target segment, schedule, aggregate result, dan status.

#### `broadcast_logs`

Snapshot pesan dan delivery status per campaign-user. Unique `(campaign_id, user_id)` mencegah pengiriman ganda.

## Relationship Summary

```mermaid
erDiagram
    USERS ||--o{ ROLE_USER : has
    ROLES ||--o{ ROLE_USER : assigned
    USERS ||--o{ SUBSCRIPTIONS : owns
    PLANS ||--o{ SUBSCRIPTIONS : selected
    PLANS ||--o{ PLAN_OFFERS : catalogs
    USERS ||--o{ SUBSCRIPTION_UPGRADE_REQUESTS : requests
    PLAN_OFFERS ||--o{ SUBSCRIPTION_UPGRADE_REQUESTS : terms
    SUBSCRIPTIONS |o--o| SUBSCRIPTION_UPGRADE_REQUESTS : approved_from
    USERS ||--o{ MATERIALS : owns
    MATERIALS ||--o{ MATERIAL_TOPICS : contains
    MATERIALS ||--o{ MATERIAL_PROFILE_VERSIONS : profiles
    USERS ||--o{ MATERIAL_PROFILE_VERSIONS : owns
    MATERIAL_PROFILE_VERSIONS ||--o{ MATERIAL_PROFILE_CHUNKS : splits
    MATERIAL_PROFILE_VERSIONS ||--o{ MATERIAL_PROFILE_STEPS : steps
    MATERIAL_PROFILE_CHUNKS |o--o| MATERIAL_PROFILE_STEPS : map_step
    MATERIAL_PROFILE_VERSIONS ||--o{ MATERIAL_PROFILE_ELEMENTS : elements
    MATERIAL_PROFILE_CHUNKS |o--o{ MATERIAL_PROFILE_ELEMENTS : evidence
    MATERIAL_PROFILE_VERSIONS ||--o{ MATERIAL_PROFILE_ATTEMPTS : attempts
    MATERIAL_PROFILE_STEPS ||--o{ MATERIAL_PROFILE_ATTEMPTS : attempts
    USERS ||--o{ PROMPT_VERSIONS : creates
    USERS ||--o{ AI_GENERATIONS : requests
    MATERIALS ||--o{ AI_GENERATIONS : sources
    MATERIAL_TOPICS o|--o{ AI_GENERATIONS : scopes
    PROMPT_VERSIONS ||--o{ AI_GENERATIONS : controls
    PLANS ||--o{ AI_USAGE_LOGS : entitles
    SUBSCRIPTIONS |o--o{ AI_USAGE_LOGS : billed
    AI_GENERATIONS ||--o{ AI_USAGE_LOGS : records
    USERS ||--o{ QUESTION_SETS : owns
    AI_GENERATIONS o|--o| QUESTION_SETS : produces
    QUESTION_SETS ||--o{ QUESTIONS : contains
    QUESTIONS ||--o{ QUESTION_OPTIONS : offers
    USERS ||--o| WHATSAPP_CONTACTS : registers
    USERS ||--o{ BROADCAST_CAMPAIGNS : administers
    BROADCAST_CAMPAIGNS ||--o{ BROADCAST_LOGS : delivers
    USERS ||--o{ BROADCAST_LOGS : receives
```

## Index and Constraint Rules

- Semua foreign key memiliki index.
- Natural identifier seperti email, google_id, role_name, version_number, plan `code`, dan phone number harus unique.
- Composite unique digunakan untuk nomor question, topic material, option label, option order, dan broadcast recipient.
- Delete cascade hanya dipakai pada child yang tidak memiliki makna tanpa parent, seperti options dan material topics.
- Data audit, subscription, generation, dan usage tidak dihapus secara cascade.
- Status dan type menggunakan enum database atau PHP backed enum yang memiliki mapping identik.

## Migration Order

Phase 1:

1. Prepare users for Google OAuth
2. roles
3. role_user
4. whatsapp_contacts

Phase 2:

1. materials, setelah users Phase 1
2. material_topics, setelah materials

Phase 5.7B1 (setelah materials):

1. material_profile_versions
2. material_profile_chunks
3. material_profile_steps
4. material_profile_elements
5. material_profile_attempts

Phase 3 (setelah materials):

1. plans
2. subscriptions, setelah users dan plans
3. plan_offers, setelah plans
4. subscription_upgrade_requests, setelah users, plans, plan_offers, dan subscriptions

Phase 3 tidak menjadi dependency migration untuk materials.

Phase 4.1+4.2 (setelah materials, plans, dan subscriptions):

1. ai_generations
2. ai_usage_logs

Phase 4.3+4.4:

1. alter `ai_generations` (language, execution_token, result_json, aggregates, failed_at; attempt_number default 0)
2. ai_generation_attempts

Phase 4.5+4.6: no schema migration.

Phase 5.1 (setelah `ai_generation_attempts`):

1. question_sets
2. questions
3. question_options

Phase 5.7C+D (additive; do not edit committed migrations):

1. question_blueprint_series
2. question_blueprints
3. question_blueprint_rows
4. question_blueprint_row_contexts
5. question_blueprint_attempts
6. question_blueprint_fill_events
7. ai_generation_runs
8. ai_generation_run_items
9. ai_generation_run_item_spans
10. alter `ai_generations` (nullable run/item/child_index)
11. alter `ai_usage_logs` (`credits` default 1, nullable `generation_id`, unique nullable `generation_run_id`, MySQL XOR CHECK)

Forward deploy in that order after Phase 5.1. Rollback of step 11 is **not** safe after Run usage or `credits>1` exists.

`prompt_versions` remains planned and is not a PHP migration.

Urutan target schema lengkap:

1. users
2. roles
3. role_user
4. plans
5. subscriptions
6. plan_offers
7. subscription_upgrade_requests
8. materials
9. material_topics
10. material_profile_versions
11. material_profile_chunks
12. material_profile_steps
13. material_profile_elements
14. material_profile_attempts
15. prompt_versions (planned; not migrated)
16. ai_generations
17. ai_usage_logs
18. ai_generation_attempts
19. question_sets
20. questions
21. question_options
22. question_blueprint_series
23. question_blueprints
24. question_blueprint_rows
25. question_blueprint_row_contexts
26. question_blueprint_attempts
27. question_blueprint_fill_events
28. ai_generation_runs
29. ai_generation_run_items
30. ai_generation_run_item_spans
31. whatsapp_contacts
32. broadcast_campaigns
33. broadcast_logs

Self-reference `ai_generations.parent_generation_id` dapat ditambahkan setelah tabel dibuat jika database membutuhkan langkah terpisah.

## Seed Data

Minimum seed:

- Phase 1 roles: USER, ADMIN.
- Phase 3 plans: canonical Free dan Pro via `PlanSeeder` (idempotent `updateOrCreate` on `code`). Tidak membuat baris subscription.
- Phase 3 offers: `pro_1m` / `pro_3m` via `PlanOfferSeeder` (`firstOrCreate` on `code`; tidak menimpa harga/status existing).
- Prompt identity: config `generation.prompt_version` / `McqPromptBuilder::version()`, audited per attempt (not seeded as `prompt_versions` rows).

Institution Plan tidak diaktifkan sebelum organization dan membership model dirancang.

## Implementation Notes

- Model Eloquent dengan custom primary key pada entitas future wajib mendefinisikan `$primaryKey`.
- Default migration `users` Laravel harus diselaraskan sebelum migration domain dibuat.
- Database constraints tidak menggantikan Form Request, Livewire validation, policy, dan domain invariant.
- Perubahan schema wajib memperbarui DBML, dokumen ini, migration, model, test, dan changelog.
- Pengembangan lokal memakai MySQL 8+ melalui Laragon dengan `DB_CONNECTION=mysql`. Jangan memakai SQLite sebagai database aplikasi lokal.
- Test otomatis tetap memakai SQLite in-memory melalui `phpunit.xml` dan tidak mengubah `DB_CONNECTION` di `.env`.