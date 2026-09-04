# Product Requirement Document (PRD)

## Document Status

- Product: AI Question Bank SaaS
- Version: 0.15.0
- Updated: 3 September 2026
- Status: Phase 0 through Phase 5 are `COMPLETE`. Phase 5 Question Bank is MCQ-only. Phase 5.7 is `IN PROGRESS`. Phase 5.7A (upload-only Material creation) is complete; legacy text Materials remain readable and editable. Phase 6 Admin Dashboard remains `PLANNED`.
- MVP boundary: Phase 0-6 dengan subscription manual dan admin minimum

## Product Vision

AI Question Bank SaaS membantu pendidik membuat, meninjau, dan menyimpan bank soal dari materi pembelajaran. Sistem menggunakan Google Gemini untuk menghasilkan soal yang konsisten berdasarkan topik, tujuan assessment, tipe soal, dan tingkat kesulitan yang dipilih pengguna.

## Problem Statement

- Pembuatan soal manual membutuhkan waktu dan pengulangan kerja.
- Kualitas, tingkat kesulitan, dan cakupan materi sering tidak konsisten.
- Pendidik sulit membuat variasi assessment dari satu sumber materi.
- Hasil AI tanpa validasi dan audit sulit dipercaya atau ditelusuri.

## Target User

### User

Pendidik, tutor, atau pembuat materi yang mengelola materi dan bank soal miliknya sendiri.

### Admin

Pengelola platform yang memonitor user, question bank, penggunaan AI, dan subscription. Pada Phase 7, admin juga mengelola WhatsApp broadcast. Admin menggunakan Google OAuth yang sama dengan user dan dibedakan melalui role.

## Architecture Decisions

- Authentication: Google OAuth only.
- Frontend: Laravel Blade + Livewire.
- AI provider MVP: Google Gemini.
- Question types MVP: multiple choice, true/false, dan essay.
- AI generation diproses secara asynchronous melalui queue.
- Database domain canonical: `docs/database/AI_QUESTION_BANK.dbml`.
- Phase 2 menggunakan Blade/controller tanpa Livewire component.

## MVP Scope

### In Scope

1. Login dan pembuatan akun otomatis melalui Google.
2. Sinkronisasi profil dasar dan role user/admin.
3. Input materi melalui unggah file PDF, DOCX, atau TXT. Pembuatan materi teks baru tidak lagi tersedia; baris teks lama tetap ada.
4. Pengelolaan topik, bab, sub-bab, dan fokus materi.
5. Konfigurasi assessment, difficulty, question type, dan jumlah soal.
6. Pemeriksaan quota sebelum generation.
7. Generation soal melalui Google Gemini.
8. Validasi output AI berdasarkan tipe soal.
9. Review dan edit hasil oleh user sebelum disimpan.
10. Penyimpanan question set dan question bank.
11. Admin dashboard untuk user, question bank, AI generation, AI usage, dan subscription.

### Post-MVP

- Institution workspace, organization, membership, dan shared question bank.
- Payment gateway dan invoice otomatis.
- WhatsApp CRM dan broadcast production.
- Export format lanjutan atau integrasi LMS.
- Multi-provider AI.

## Core User Journey

1. User membuka landing page dan login melalui Google.
2. Sistem memvalidasi OAuth, membuat atau memperbarui profil, lalu membuka dashboard.
3. User memilih materi ready (bukan draft question set) dan menentukan assessment, difficulty, question type, serta jumlah soal.
4. Sistem memvalidasi materi, konfigurasi, dan quota, lalu mereservasi satu generation credit.
5. Sistem mengantrekan generation dan memanggil Google Gemini. Automatic retry memakai Generation dan reservation yang sama.
6. Output valid ditagihkan (`charged`) dan disimpan sebagai hasil runtime generation (preview Phase 4). Invalid/partial output bukan success; credit di-release pada terminal failure.
7. Phase 5: owner dapat mengimpor generation completed MCQ ke Question Set `draft`, mengedit MCQ pada satu halaman, lalu menerbitkan (`draft → published`). Published bersifat read-only. Pembuatan manual, TF/essay, unpublish, dan archive belum.

Detail alur dan kegagalan tersedia di `docs/architecture/FLOW.md`.

### Phase 2 Material Journey

1. User membuka menu Material Management langsung dari dashboard.
2. User membuat material baru hanya melalui unggah PDF, DOCX, atau TXT. Materi teks yang sudah ada tetap dapat dilihat dan diedit; pembuatan teks baru tidak tersedia.
3. Sistem memvalidasi input; setiap file upload maksimal 10 MB. Batas itu tetap berlaku di MVP. Quota storage akun Plan (Free 50 MiB / Pro 500 MiB total) adalah kontrol terpisah dan tidak menggantikan batas per file. Karena unggah adalah satu-satunya jalur pembuatan, user di atas kuota penyimpanan efektif tidak dapat membuat Material baru.
4. Upload diproses melalui extraction queue. Materi teks lama tetap siap tanpa ekstraksi.
5. Automatic Laravel queue retry is implemented for extraction jobs. Manual user extraction retry is not part of the current implementation: there is no `RetryMaterialExtraction` Action or Material UI retry control. Manual retry remains deferred until a later explicitly authorized lifecycle/UI decision.
6. User mengatur chapter, sub-chapter, topic, focus area, serta optional page range.
7. User dapat mengarsipkan material draft/ready dan memulihkan material archived menjadi ready.

Flow Phase 2 berdiri sendiri dan tidak memerlukan `question_sets`. Question Bank Phase 5 mengimpor Generation completed MCQ, bukan membuat set dari Material journey.

## Functional Requirements

### Authentication

- FR-AUTH-01: Sistem hanya menyediakan login Google OAuth.
- FR-AUTH-02: Login pertama membuat user dengan role `USER`. Entitlement default adalah Plan Free sebagai fallback; sistem tidak membuat baris subscription Free.
- FR-AUTH-03: Login berikutnya memperbarui nama, avatar, email Google, dan waktu login terakhir.
- FR-AUTH-04: Halaman admin hanya dapat diakses user dengan role `ADMIN`.
- FR-AUTH-05: User berstatus suspended atau inactive tidak dapat mengakses aplikasi.
- FR-AUTH-06: User tanpa WhatsApp contact wajib melengkapi nomor telepon sebelum mengakses dashboard.

### Material Management

- FR-MAT-01: User dapat membuat materi baru hanya dari unggah file (PDF, DOCX, TXT). Pembuatan materi teks baru melalui HTTP/UI tidak tersedia. Materi `source_type=text` yang sudah ada tetap dapat dilihat dan diedit (judul dan konten). Penggantian file unggahan tidak didukung.
- FR-MAT-02: Sistem menyimpan metadata file, ukuran, MIME type, hash, dan status ekstraksi.
- FR-MAT-03: User dapat menentukan bab, sub-bab, topik, serta focus area.
- FR-MAT-04: User hanya dapat melihat dan mengubah materi miliknya.
- FR-MAT-05: Sistem menghitung seluruh upload yang belum dihapus terhadap storage usage, termasuk material archived dan extraction failed.
- FR-MAT-06: Phase 2 hanya menerima PDF, DOCX, dan TXT. Setiap file upload maksimal 10 MB (batas keselamatan MVP yang tetap berlaku). Quota storage akun memakai `Plan.storage_limit_bytes` (Free 50 MiB / Pro 500 MiB total) dan ditegakkan pada upload file.
- FR-MAT-07: Upload wajib memiliki file path internal, file size, MIME type, SHA-256 file hash, dan extraction status.
- FR-MAT-08: Kombinasi user dan file hash wajib unique untuk menolak upload duplikat milik user yang sama.
- FR-MAT-09: User dapat mengubah material draft/ready menjadi archived dan memulihkan material archived menjadi ready.
- FR-MAT-10: Phase 2 Material Management dapat digunakan langsung dari dashboard tanpa membuat question set.
- FR-MAT-11: Jika Pro berakhir dan counted storage melebihi limit Free: data yang sudah ada tetap ada; akses Material existing tetap; archive dan restore tetap diizinkan; upload FILE baru ditolak sampai usage kembali di bawah limit entitlement efektif. Karena pembuatan baru hanya melalui unggah, user di atas kuota tidak dapat membuat Material baru.

### AI Generation

- FR-AI-01: Sistem memeriksa subscription dan quota sebelum generation.
- FR-AI-02: User memilih assessment type: formative, summative, atau diagnostic.
- FR-AI-03: User memilih difficulty: easy, medium, hard, atau HOTS.
- FR-AI-04: User memilih satu question type per generation. Runtime Phase 4.3+4.4 mengeksekusi **multiple choice** saja (empat opsi A–D, satu jawaban, explanation wajib). True/false dan essay ditolak di Start sampai provider-nya ada.
- FR-AI-05: Generation dijalankan oleh queue (`database-generation` / `question-generation`) dan memiliki status queued, processing, completed, failed, atau cancelled.
- FR-AI-06: Generation menyimpan user, material, assessment, difficulty, question type, count, `output_language`, status, `execution_token`, attempt, `result_json`, provider/token aggregates, sanitized error, dan timestamps. Per-call audit ada di `ai_generation_attempts` termasuk `prompt_version` yang dipakai HTTP itu. Jangan persist raw prompt atau full raw Gemini/provider response. Jangan kolom `raw_response` / `parsed_output`.
- FR-AI-07: Output yang tidak sesuai schema bukan success. Phase 4 tidak menyimpan generated questions ke Question Bank. Credit di-release pada terminal failure. Tidak ada pengembalian question set ke draft karena generation tidak bergantung pada draft `question_sets`.
- FR-AI-08: Automatic provider/job retry memakai Generation dan reservation yang sama (`attempt_number` = HTTP yang sudah started, max 3). Job Laravel `$tries` tidak mengalikan kuota HTTP. Manual retry setelah terminal `failed`: Generation lama tetap `failed`, reservation `released`, user memulai Generation baru dengan reservation baru; `parent_generation_id` ditulis dalam transaksi Start yang sama.

### Question Bank

- FR-QB-01: Question Bank adalah Phase 5. Generation Phase 4 tidak membuat atau memerlukan draft `question_sets`. Owner mengimpor generation **completed** MCQ menjadi Question Set `draft` (satu Generation → paling banyak satu Question Set, `UNIQUE(generation_id)`). Persistensi adalah snapshot terpisah; `result_json` generasi tidak dipindah atau dihapus. Pembuatan manual tetap later.
- FR-QB-02: User dapat meninjau dan mengedit pertanyaan MCQ (judul, teks, opsi A–D, jawaban benar, penjelasan) pada Question Set `draft` sebelum publish. Satu halaman, satu simpan, atomik. Published read-only.
- FR-QB-03: User dapat membuat question set manual tanpa AI. (later; not Phase 5)
- FR-QB-04: Schema may store `draft`, `generating`, `review`, `published`, and `archived`. Locked product lifecycle is `draft → published`. Phase 5 writes `published` only via explicit publish. `generating` / `review` / `archived` are not active publish paths.
- FR-QB-05: Question set bersifat private secara default. Phase 5 publish does not change visibility.
- FR-QB-06: Admin dapat melihat, membuat, memperbarui, menghapus, dan mereview question bank sesuai policy. (not Phase 5; Admin has no ownership bypass)
- FR-QB-07: Submission admin review mengubah `review_status` menjadi pending tanpa mengubah lifecycle status `review`. (not Phase 5)

Phase 5 delivered constraints:

- Owner-only. Foreign Question Set IDs, including Admin, are 404. Admin has no ownership bypass.
- Edit and publish do not charge generation quota and do not call Gemini. `result_json` remains unchanged.
- No add, delete, or reorder of questions. No unpublish, archive, delete, or restore.
- True/false and essay Question Bank are not supported yet.
- Public visibility and admin review remain later.
- No Phase 5 Batch 2 migration.


### Subscription and Quota

- FR-SUB-01: Plan mendefinisikan entitlement: `storage_limit_bytes`, `generation_limit`, dan `generation_reset_strategy` (`lifetime` atau `monthly`). Plan bukan harga atau durasi komersial.
- FR-SUB-02: Subscription menyimpan window Pro berbatas waktu. Paling banyak satu window efektif pada satu instant; unique `(user_id, status)` tidak dipakai. Free bukan baris subscription. Resolver entitlement memakai `[starts_at, ends_at)` dan memvalidasi seluruh antrian `active` current/future sebagai Pro.
- FR-SUB-03: Penggunaan generation dicatat pada `ai_usage_logs` dengan `plan_id` wajib. `subscription_id` nullable: Free lifetime usage memakai Plan Free tanpa baris subscription; Pro monthly usage memakai Plan Pro dan window yang di-snapshot saat reservasi.
- FR-SUB-04: Credit generation direservasi saat Start (`reserved`), ditagihkan saat Consume (`charged` ≡ consumed), dan dilepas saat Release (`released`). Satu request = satu credit. `available = allowance - charged - reserved`. Tidak ada HTTP idempotency key. Stale queued/processing reservations are recovered to `failed` + `released` with `error_code=stale_recovery` (minimum safe floor 1800s; operators may configure a higher threshold; no `reservation_expires_at`). User cancellation remains deferred.
- FR-SUB-05: User memilih offer Pro 1 bulan atau 3 bulan, membayar via QRIS statis, dan mengonfirmasi via WhatsApp. Paling banyak satu permintaan upgrade `pending` total per user (bukan per Offer). User tidak dapat membatalkan permintaan pending miliknya. Admin menyetujui, menolak (alasan wajib), atau membatalkan permintaan. Setelah rejected atau cancelled, user boleh membuat pending baru.
- FR-SUB-06: Persetujuan menulis tepat satu baris Subscription `status=active` memakai snapshot permintaan. Tidak ada status Subscription `scheduled` atau `pending`. Satu pembelian 3 bulan (`pro_3m`) = satu baris yang mencakup 3 bulan kalender. Jika tidak ada antrian Pro current/future yang valid, `starts_at` = waktu approval. Jika antrian itu ada, `starts_at` = akhir antrian berbayar tersebut (`max(ends_at)`). `ends_at` = `starts_at` plus `duration_months` dengan aritmetika kalender no-overflow. Window masa depan tetap `active`.

### Admin

- FR-ADM-01: Admin dapat mengelola user dan status akun.
- FR-ADM-02: Admin dapat mengelola question bank.
- FR-ADM-03: Admin dapat memonitor AI generation dan AI usage.
- FR-ADM-04: Admin dapat menyetujui, menolak (alasan wajib), atau membatalkan permintaan pembayaran/upgrade manual. Persetujuan menghasilkan pembuatan atau perpanjangan subscription Pro. Subscription itu sendiri tidak berstatus pending/rejected pembayaran. Verifikasi pembayaran tidak memberi Admin akses global ke Material privat; `MaterialPolicy` tetap owner-only.

### Post-MVP WhatsApp CRM

- FR-CRM-01: Broadcast harus dikonfirmasi sebelum diproses.
- FR-CRM-02: Hanya contact yang consent dan belum opt-out yang dapat menjadi penerima.
- FR-CRM-03: Hasil pengiriman dicatat per penerima dan kegagalan satu penerima tidak membatalkan campaign.

## AI Output Contract

### Multiple Choice

Wajib memiliki question text, minimal empat options, tepat satu jawaban benar, dan explanation.

### True/False

Wajib memiliki question text, tepat dua options dengan label canonical `TRUE`/`FALSE` dan teks tampilan `Benar`/`Salah`, tepat satu jawaban benar, dan explanation.

### Essay

Wajib memiliki question text, model answer, dan rubric. Essay tidak memiliki question options.

Kontrak JSON lengkap menjadi source of truth di `docs/ai/PROMPT_ENGINE_RULES.md`.

## Non-Functional Requirements

- NFR-01: Semua route aplikasi menggunakan session authentication dan CSRF protection.
- NFR-02: Policy wajib mencegah akses silang terhadap material dan question set.
- NFR-03: File upload divalidasi berdasarkan MIME type, ukuran, dan extension.
- NFR-04: Request AI memiliki timeout, retry terbatas, rate limit, dan audit trail.
- NFR-05: Secret OAuth dan Gemini hanya disimpan di environment.
- NFR-06: Operasi generation tidak memblokir HTTP request; aturan yang sama berlaku untuk broadcast pada Phase 7.
- NFR-07: Foreign key, unique constraint, dan index mengikuti DBML canonical.
- NFR-08: Data penting dapat dipulihkan melalui backup database dan storage.

## Monetization

### Free Plan

Fallback entitlement permanen. Storage 50 MiB (`52428800` bytes). Generation 2 untuk lifetime akun. Reset `lifetime`. Tidak kedaluwarsa. Tidak direpresentasikan sebagai baris subscription.

### Pro Plan

Satu Plan entitlement. Storage 500 MiB (`524288000` bytes). Generation 100 per window bulanan yang mengikuti anniversary `starts_at` subscription, bukan tanggal 1 kalender. Reset `monthly`. Window Pro selalu memiliki `ends_at` hingga. Offer komersial: Pro 1 bulan Rp10.000 dan Pro 3 bulan Rp25.000 (`plan_offers`). Satu pembelian 3 bulan menulis satu baris Subscription, bukan tiga baris.

### Institution Plan

Dicatat sebagai arah produk post-MVP. Dukungan organization, membership, seat, dan shared ownership belum termasuk dalam 18 entitas database saat ini.

## MVP Acceptance Criteria

- User dapat login hanya melalui Google dan masuk ke dashboard.
- Admin menggunakan login yang sama, tetapi aksesnya dibatasi role.
- User dapat membuat materi upload atau teks dan memilih topik/fokus.
- Quota diperiksa sebelum generation (definisi limit Phase 3.5; reservation/charge/release Phase 4.1+4.2; Gemini MCQ job Phase 4.3+4.4; owner UI Phase 4.5).
- Gemini menghasilkan MCQ terstruktur yang divalidasi server-side (4.3+4.4). True/false dan essay belum dijalankan provider. Owner dapat mengonfigurasi generasi, memantau queued/processing, melihat pratinjau completed, dan retry failed.
- Failure AI tidak mengurangi credit secara permanen (Release pada terminal failure via `FinalizeGenerationFailure` atau stale recovery).
- User dapat mengimpor generation completed MCQ ke Question Set `draft`, mengedit MCQ secara atomik, dan menerbitkan ke `published` (Phase 5 `COMPLETE`). Published read-only. Manual create, add/delete/reorder, TF/essay Question Bank, unpublish, archive, public visibility, and admin review are later.
- Admin dapat menjalankan modul Phase 6 pada flow admin; branch broadcast baru wajib pada Phase 7.
- Semua generation dan penggunaan credit dapat diaudit.

## Open Decisions

- Batas per file tetap 10 MB. Quota storage akun Plan sudah ditegakkan dan tidak menggantikan batas per file.
- Format export pertama: PDF, DOCX, XLSX, atau LMS.
- Provider payment otomatis dan invoice tetap post-MVP. Phase 3.6: opsi durasi Pro, QRIS statis pada public disk, konfirmasi WhatsApp, verifikasi admin. Phase 7: WhatsApp CRM / broadcast.
