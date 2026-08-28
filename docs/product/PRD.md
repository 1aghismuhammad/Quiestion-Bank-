# Product Requirement Document (PRD)

## Document Status

- Product: AI Question Bank SaaS
- Version: 0.8
- Updated: 28 August 2026
- Status: Phase 1 and Phase 2 are complete. Phase 3.1 through 3.4 are implemented (Plan/Subscription domain, entitlement resolver, account storage quota). Phase 3 overall remains in progress. Generation quota and payment are not started.
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
3. Input materi melalui upload file atau teks manual.
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
3. User membuat draft question set dan memilih materi lama atau materi baru.
4. User menentukan topik/fokus, assessment, difficulty, question type, dan jumlah soal.
5. Sistem memvalidasi materi, konfigurasi, dan quota subscription.
6. Sistem mengantrekan generation dan memanggil Google Gemini.
7. Output valid disimpan dan question set berpindah ke status review.
8. User meninjau, mengedit, menyimpan, lalu mempublish question set.

Detail alur dan kegagalan tersedia di `docs/architecture/FLOW.md`.

### Phase 2 Material Journey

1. User membuka menu Material Management langsung dari dashboard.
2. User membuat material melalui upload PDF, DOCX, TXT, atau input teks manual.
3. Sistem memvalidasi input; setiap file upload maksimal 10 MB. Batas itu tetap berlaku di MVP. Quota storage akun Plan (Free 50 MiB / Pro 500 MiB total) adalah kontrol terpisah dan tidak menggantikan batas per file.
4. Text material langsung siap, sedangkan upload diproses melalui extraction queue.
5. Automatic Laravel queue retry is implemented for extraction jobs. Manual user extraction retry is not part of the current implementation: there is no `RetryMaterialExtraction` Action or Material UI retry control. Manual retry remains deferred until a later explicitly authorized lifecycle/UI decision.
6. User mengatur chapter, sub-chapter, topic, focus area, serta optional page range.
7. User dapat mengarsipkan material draft/ready dan memulihkan material archived menjadi ready.

Flow Phase 2 berdiri sendiri dan tidak memerlukan `question_sets`. Pemilihan material dari flow question set baru digunakan ketika Phase 5 diimplementasikan.

## Functional Requirements

### Authentication

- FR-AUTH-01: Sistem hanya menyediakan login Google OAuth.
- FR-AUTH-02: Login pertama membuat user dengan role `USER`. Entitlement default adalah Plan Free sebagai fallback; sistem tidak membuat baris subscription Free.
- FR-AUTH-03: Login berikutnya memperbarui nama, avatar, email Google, dan waktu login terakhir.
- FR-AUTH-04: Halaman admin hanya dapat diakses user dengan role `ADMIN`.
- FR-AUTH-05: User berstatus suspended atau inactive tidak dapat mengakses aplikasi.
- FR-AUTH-06: User tanpa WhatsApp contact wajib melengkapi nomor telepon sebelum mengakses dashboard.

### Material Management

- FR-MAT-01: User dapat membuat materi dari upload file atau teks manual.
- FR-MAT-02: Sistem menyimpan metadata file, ukuran, MIME type, hash, dan status ekstraksi.
- FR-MAT-03: User dapat menentukan bab, sub-bab, topik, serta focus area.
- FR-MAT-04: User hanya dapat melihat dan mengubah materi miliknya.
- FR-MAT-05: Sistem menghitung seluruh upload yang belum dihapus terhadap storage usage, termasuk material archived dan extraction failed.
- FR-MAT-06: Phase 2 hanya menerima PDF, DOCX, dan TXT. Setiap file upload maksimal 10 MB (batas keselamatan MVP yang tetap berlaku). Quota storage akun memakai `Plan.storage_limit_bytes` (Free 50 MiB / Pro 500 MiB total) dan ditegakkan pada upload file.
- FR-MAT-07: Upload wajib memiliki file path internal, file size, MIME type, SHA-256 file hash, dan extraction status.
- FR-MAT-08: Kombinasi user dan file hash wajib unique untuk menolak upload duplikat milik user yang sama.
- FR-MAT-09: User dapat mengubah material draft/ready menjadi archived dan memulihkan material archived menjadi ready.
- FR-MAT-10: Phase 2 Material Management dapat digunakan langsung dari dashboard tanpa membuat question set.

### AI Generation

- FR-AI-01: Sistem memeriksa subscription dan quota sebelum generation.
- FR-AI-02: User memilih assessment type: formative, summative, atau diagnostic.
- FR-AI-03: User memilih difficulty: easy, medium, hard, atau HOTS.
- FR-AI-04: User memilih satu question type per generation: multiple choice, true/false, atau essay.
- FR-AI-05: Generation dijalankan oleh queue dan memiliki status queued, processing, completed, failed, atau cancelled.
- FR-AI-06: Setiap request menyimpan user, material, topic, prompt version, provider, model, token, cost, status, raw response, dan parsed output.
- FR-AI-07: Output yang tidak sesuai schema hanya disimpan sebagai audit `ai_generations`; sistem tidak menyimpan generated questions dan mengembalikan question set ke status draft.
- FR-AI-08: Retry untuk generation gagal membuat generation baru yang menunjuk generation sebelumnya dan menghubungkan kembali draft question set ke generation baru.

### Question Bank

- FR-QB-01: Question set dibuat sebagai draft sebelum generation, berubah menjadi generating ketika job diantrekan, lalu menjadi review setelah output valid.
- FR-QB-02: User dapat meninjau dan mengedit pertanyaan sebelum publish.
- FR-QB-03: User dapat membuat question set manual tanpa AI.
- FR-QB-04: Question set mendukung status draft, generating, review, published, dan archived.
- FR-QB-05: Question set bersifat private secara default dan hanya dapat dibuat public melalui aksi eksplisit pemilik.
- FR-QB-06: Admin dapat melihat, membuat, memperbarui, menghapus, dan mereview question bank sesuai policy.
- FR-QB-07: Submission admin review mengubah `review_status` menjadi pending tanpa mengubah lifecycle status `review`.

### Subscription and Quota

- FR-SUB-01: Plan mendefinisikan entitlement: `storage_limit_bytes`, `generation_limit`, dan `generation_reset_strategy` (`lifetime` atau `monthly`). Plan bukan harga atau durasi komersial.
- FR-SUB-02: Subscription menyimpan window Pro berbatas waktu. Paling banyak satu window efektif pada satu instant; unique `(user_id, status)` tidak dipakai. Free bukan baris subscription. Resolver entitlement memakai `[starts_at, ends_at)` dan memvalidasi seluruh antrian `active` current/future sebagai Pro.
- FR-SUB-03: Penggunaan generation dicatat pada `ai_usage_logs` dengan `plan_id` wajib. `subscription_id` nullable: Free lifetime usage memakai Plan Free tanpa baris subscription; Pro monthly usage memakai Plan Pro dan window subscription efektif.
- FR-SUB-04: Credit generation direservasi sebelum request AI, memiliki waktu kedaluwarsa, ditagihkan saat berhasil, dan dilepas saat gagal atau reservation expired. Fondasi quota generation dan `ai_usage_logs` adalah Phase 3.5 + 3.6; integrasi pemakaian Gemini dikoordinasikan dengan Phase 4.
- FR-SUB-05: Verifikasi pembayaran/aktivasi Pro oleh admin adalah milestone payment kemudian; bukan bagian domain foundation.

### Admin

- FR-ADM-01: Admin dapat mengelola user dan status akun.
- FR-ADM-02: Admin dapat mengelola question bank.
- FR-ADM-03: Admin dapat memonitor AI generation dan AI usage.
- FR-ADM-04: Admin dapat menyetujui atau menolak permintaan pembayaran/upgrade manual. Persetujuan menghasilkan pembuatan atau perpanjangan subscription Pro. Subscription itu sendiri tidak berstatus pending/rejected pembayaran.

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

Satu Plan entitlement. Storage 500 MiB (`524288000` bytes). Generation 100 per window bulanan yang mengikuti anniversary subscription, bukan kalender. Reset `monthly`. Window Pro selalu memiliki `ends_at` hingga. Harga dan durasi komersial (1 bulan / 3 bulan) adalah layer offer/payment kemudian.

### Institution Plan

Dicatat sebagai arah produk post-MVP. Dukungan organization, membership, seat, dan shared ownership belum termasuk dalam 16 entitas database saat ini.

## MVP Acceptance Criteria

- User dapat login hanya melalui Google dan masuk ke dashboard.
- Admin menggunakan login yang sama, tetapi aksesnya dibatasi role.
- User dapat membuat materi upload atau teks dan memilih topik/fokus.
- Quota diperiksa sebelum generation.
- Gemini dapat menghasilkan ketiga tipe soal dengan schema yang valid.
- Failure AI tidak mengurangi credit secara permanen.
- User dapat review, edit, save, dan publish question set.
- Admin dapat menjalankan modul Phase 6 pada flow admin; branch broadcast baru wajib pada Phase 7.
- Semua generation dan penggunaan credit dapat diaudit.

## Open Decisions

- Batas per file tetap 10 MB. Quota storage akun Plan sudah ditegakkan dan tidak menggantikan batas per file.
- Format export pertama: PDF, DOCX, XLSX, atau LMS.
- Provider payment otomatis dan invoice tetap post-MVP. Phase 3.5 + 3.6 MVP: opsi durasi Pro, QRIS statis, konfirmasi pembayaran via WhatsApp, verifikasi admin. Phase 7: WhatsApp CRM / broadcast.
