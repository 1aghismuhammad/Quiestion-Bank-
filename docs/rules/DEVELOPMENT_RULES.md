# Development Rules

## Scope

Aturan ini berlaku untuk pengembangan Laravel 13 AI Question Bank SaaS.

Keputusan stack:

- PHP 8.3+
- Laravel 13
- Blade + Livewire + Tailwind CSS
- Phase 2 Material Management wajib Blade/controller dan tidak menambahkan Livewire dependency atau component
- Google OAuth melalui Laravel Socialite
- Google Gemini melalui service/adapter internal
- Queue untuk AI generation, extraction, serta broadcast ketika Phase 7 dimulai

## Architecture

Gunakan pragmatic modular architecture:

1. Route atau Livewire component menerima interaksi.
2. Form validation dan policy memvalidasi input serta akses.
3. Action atau service menjalankan business use case.
4. Eloquent model mengelola persistence dan relasi.
5. Job menangani proses asynchronous.
6. Provider adapter menangani external API.

Rules:

- Phase 2 menggunakan route, controller, Form Request, policy, action/service, model, dan job tanpa Livewire component.
- Livewire component tidak boleh berisi query kompleks atau provider call.
- Controller/Livewire tidak mengelola transaction bisnis.
- Provider-specific response tidak boleh menyebar ke domain layer.
- Repository tidak wajib; gunakan hanya untuk query kompleks atau sumber data alternatif.
- Semua mutation multi-tabel menggunakan database transaction.

## Suggested Locations

```text
app/Actions/{Module}
app/Contracts/AI
app/Data
app/Jobs
app/Livewire/User
app/Livewire/Admin
app/Models
app/Policies
app/Services/AI
app/Services/Materials
app/Services/Subscriptions
```

## Authentication and Authorization

- Hanya Google OAuth; jangan menambahkan registration atau password login.
- Socialite callback harus memvalidasi state.
- User baru mendapatkan role `USER`.
- Admin memakai akun yang sama dengan role `ADMIN`.
- Route admin menggunakan middleware role; policy ditambahkan pada setiap aksi resource domain saat modulnya diimplementasikan.
- Suspended/inactive user ditolak setelah OAuth callback.
- Model dan migration users tidak menyimpan password lokal.
- User tanpa WhatsApp contact wajib menyelesaikan profile setup sebelum dashboard.
- Test OAuth menggunakan fake/mock provider; jangan memanggil Google sungguhan.

## Livewire

- Public property harus memiliki validation rule.
- Gunakan Form Object untuk form kompleks.
- Authorize mutation di server pada setiap action.
- Jangan percaya ID atau status dari browser.
- Gunakan loading/error state untuk queue dan provider operation.
- Hindari N+1 query pada list dan dashboard.
- Jangan menyimpan secret atau raw Gemini response dalam component state.

## Database

- Pengembangan lokal memakai MySQL 8+ melalui Laragon. Pastikan MySQL Laragon berjalan sebelum `php artisan migrate`, `migrate:status`, atau `migrate --pretend`.
- `.env` lokal wajib `DB_CONNECTION=mysql`. SQLite hanya untuk test otomatis melalui `phpunit.xml`.
- Semua perubahan schema menggunakan migration.
- `docs/database/AI_QUESTION_BANK.dbml` adalah desain canonical.
- Semua foreign key memiliki index.
- Setiap natural identifier yang unik harus memiliki unique constraint.
- Enum database dan PHP backed enum menggunakan nilai identik.
- Custom primary key pada DBML wajib didefinisikan melalui `$primaryKey` pada model.
- Gunakan `created_at`, `updated_at`, dan soft delete hanya sesuai lifecycle.
- Cascade delete hanya untuk child yang tidak memiliki nilai audit.
- Subscription, AI generation, AI usage, dan broadcast log tidak boleh terhapus karena cascade user.
- Migration harus memiliki rollback yang aman.

Checklist perubahan schema:

1. Update DBML.
2. Update database reference.
3. Buat migration.
4. Update model, casts, relations, dan enum.
5. Tambah atau update test.
6. Catat database impact di changelog.

## Domain Invariants

- Paling banyak satu subscription efektif untuk user pada satu instant. Beberapa row berstatus `active` boleh ada untuk renewal berurutan selama effective windows `[starts_at, ends_at)` tidak overlap. Unique `(user_id, status)` tidak dipakai. Resolver memvalidasi seluruh antrian `active` current/future sebagai Plan Pro dengan window well-formed; overlap efektif fail-closed. Data stale historis tidak mengunci akun.
- Credit harus reserved dengan expiry sebelum generation, charged hanya setelah valid, dan released ketika gagal/expired.
- Question count output harus sama dengan request.
- Multiple choice memiliki minimal empat options dan tepat satu benar.
- True/false memiliki tepat dua options dan satu benar.
- Essay tidak memiliki options serta wajib memiliki model answer dan rubric.
- User hanya mengubah resource miliknya kecuali admin policy mengizinkan.
- Mulai Phase 7, broadcast hanya dikirim ke contact yang memiliki consent dan belum opt-out.

## AI Integration

External call hanya dilakukan melalui kontrak AI internal.

Setiap request AI wajib menyimpan:

- User
- Material dan topic
- Prompt version
- Assessment, difficulty, question type, dan count
- Provider dan model
- Token usage
- Estimated cost
- Raw response dan parsed output
- Status, error, attempt, dan timestamps

Additional rules:

- API key hanya dari environment.
- Request memiliki timeout, rate limit, dan retry terbatas.
- Retry membuat generation baru, tidak menimpa audit lama.
- Output divalidasi sebelum persistence ke question bank.
- Invalid output me-release credit.
- Log tidak boleh menyimpan OAuth token atau API key.

## File Upload

- Phase 2 hanya menerima PDF, DOCX, dan TXT.
- Setiap file upload maksimal 10 MB (batas keselamatan MVP). Batas ini tidak digantikan oleh quota Plan.
- Quota storage akun memakai `Plan.storage_limit_bytes` (Free 50 MiB / Pro 500 MiB total). Enforcement: counted upload usage + file baru harus `<=` limit efektif (byte persis). Terpisah dari batas per file.
- Upload file yang mengubah counted usage wajib `lockForUpdate` pada baris `users` pemilik, lalu duplicate re-check `(user_id, file_hash)` termasuk trash, baru entitlement/quota, store, dan insert. Jangan serialisasi global antar user.
- Pesan duplikat: `File yang sama sudah diunggah.` Pesan quota: `Penyimpanan paket Anda tidak mencukupi untuk file ini.`
- Gunakan allowlist MIME type dan extension; keduanya wajib sesuai.
- Upload wajib menyimpan internal file path, file size, MIME type, SHA-256 file hash, dan extraction status.
- Nama file asli tidak digunakan sebagai storage path.
- Kombinasi `(user_id, file_hash)` wajib unique untuk mencegah duplikasi per user.
- File private tidak boleh diakses melalui URL publik tanpa authorization.
- Content extraction dijalankan melalui queue.
- Storage usage menghitung seluruh upload non-deleted termasuk archived dan extraction failed.
- Archive mempertahankan file; owner dapat melakukan `draft|ready -> archived` dan `archived -> ready`.

## Security

- Gunakan CSRF protection untuk semua form.
- Gunakan policy untuk material, question set, subscription action, dan admin action.
- Escape output pada Blade; HTML harus disanitasi jika benar-benar diperlukan.
- Rate limit login callback, generation, retry, upload, dan broadcast.
- Jangan commit `.env`, credentials, token, atau provider response yang sensitif.
- Admin destructive action harus memiliki confirmation.

## Testing

Minimum test per feature:

- Happy path.
- Validation failure.
- Authorization/ownership failure.
- Database invariant.
- Provider or queue failure.

Minimum coverage khusus:

- Google OAuth provisioning dan suspended user.
- Role admin route protection.
- Quota reservation, charge, release, dan concurrency.
- Prompt validator untuk ketiga question type.
- Retry lineage dan audit AI.
- Material ownership, upload validation, entitlement resolution, dan storage quota.
- Phase 7: broadcast consent dan duplicate prevention.

Gunakan factory, fake queue, fake storage, dan fake HTTP/provider. Test tidak boleh bergantung pada koneksi internet.

## Documentation

- Setiap fitur wajib terdokumentasi.
- Perubahan scope memperbarui PRD dan roadmap.
- Perubahan alur memperbarui FLOW.
- Perubahan schema memperbarui DBML dan database reference.
- Perubahan prompt memperbarui prompt rules dan prompt version.
- Perubahan arsitektur dicatat dalam DESIGN dan changelog.
- Terminologi canonical: `question set` untuk satu kumpulan soal; `question bank` untuk fitur koleksi.

## Code Quality

- Gunakan strict types pada class domain baru bila konsisten dengan project.
- Jalankan Laravel Pint sebelum handoff.
- Gunakan named constants atau backed enum untuk status/type.
- Hindari magic string dan duplicated business rule.
- Exception eksternal diterjemahkan menjadi domain/application error yang aman.
- Perubahan besar harus melalui review dan memiliki test plan.