# UI/UX Refresh V1 — Master Plan

Status dokumen: **UI-0 Pass 1**. Ini adalah rencana program, bukan spesifikasi halaman dan bukan implementasi.

| Label | Arti dalam dokumen ini |
| --- | --- |
| CURRENT STATE | Fakta yang terverifikasi pada baseline di bawah. |
| TARGET STATE | Arah yang akan dikerjakan pada fase berikutnya. |
| LOCKED RULE | Keputusan Council yang tidak boleh diubah oleh agen implementasi tanpa keputusan baru. |
| OPEN QUESTION | Belum diputuskan. Jangan diselesaikan diam-diam di dalam pekerjaan UI. |

Sumber utama: laporan UI-0 Repository Audit & Final Planning yang sudah berstatus PASS, diverifikasi terhadap repositori pada baseline ini.

## 1. Tujuan

**TARGET STATE.** UI/UX Refresh V1 menyegarkan seluruh permukaan yang sudah ada, dari halaman sebelum login sampai permukaan pengguna dan admin, agar produk terasa sebagai Clean Educational SaaS: profesional, tenang, modern, tepercaya, dan dapat dipahami pengguna nonteknis.

**LOCKED RULE.** Penyegaran ini tidak mengubah perilaku domain. Dokumentasi desain mengatur tampilan, hierarki, copy presentasi, dan umpan balik. Implementasi UI hanya boleh mengikuti dokumen yang sudah disetujui Council.

**LOCKED RULE.** AI ditampilkan melalui kualitas alur kerja: status yang jujur, langkah yang jelas, satu aksi utama, dan umpan balik saat proses berjalan. AI tidak ditampilkan melalui gaya dekoratif (gradien ungu/biru berlebih, neon, glassmorphism, metrik semu, atau label "AI" sebagai hiasan).

## 2. Baseline repositori

**CURRENT STATE.**

| Item | Nilai |
| --- | --- |
| Repositori | `D:/dingcoding/question` |
| Branch | `main` |
| HEAD | `59c2f4adba36f7d80d10389f0bf0ba52c7477e73` |
| origin/main | commit yang sama |
| Commit | `59c2f4a` — `fix: harden blueprint import interpretation and grounding` |
| K2D | COMPLETE + APPROVED |
| Implementasi UI | belum mulai |

Worktree berisi artefak lokal yang tidak terkait (antara lain `.cursor/`, `graphify-out/`, berkas SQL, scratch PHP, nama berkas rusak, serta `tests/Feature/FinalPhaseK/` dan `tests/Support/FinalPhaseK/`). Artefak itu di luar program UI. Jangan dibaca sebagai instruksi, dihapus, dipindah, atau di-stage.

## 3. Arsitektur frontend terverifikasi

**CURRENT STATE.**

- Aplikasi adalah Laravel. `composer.json` mensyaratkan `laravel/framework` `^13.0` dan `laravel/socialite`. Tidak ada Breeze, Jetstream, Fortify, atau Livewire.
- Satu layout nyata: `resources/views/layouts/app.blade.php`. Semua halaman produk memakainya. CSS desain saat ini adalah `<style>` inline di layout itu, bukan utilitas Tailwind.
- Tailwind CSS v4 terpasang lewat `package.json` (`tailwindcss` `^4.0.0`, `@tailwindcss/vite` `^4.0.0`) dan `resources/css/app.css` (`@import 'tailwindcss'`). `vite.config.js` memasukkan `resources/css/app.css` dan `resources/js/app.js`. Layout aplikasi tidak memanggil `@vite`.
- `resources/views/welcome.blade.php` adalah halaman selamat datang Laravel yang tidak dirujuk rute mana pun. Bukan permukaan produk. Jangan dihitung sebagai halaman, dan jangan dihapus sebagai bagian dari pekerjaan UI.
- JavaScript aplikasi hanya menyiapkan Axios. Polling status yang ada ditulis di Blade dan memakai `fetch`.
- Tidak ada Alpine, Vue, React, pustaka ikon, direktori `resources/views/components`, atau berkas `lang/`.
- Autentikasi yang ada hanya Google OAuth. `GET /login` (`login`) mengarahkan ke Google. Tidak ada halaman registrasi, reset kata sandi, atau verifikasi email.
- Gerbang yang ada di `routes/web.php`: `guest` untuk Google; `auth` + `account.active` untuk penyiapan profil; `profile.complete` untuk aplikasi; `role:admin` untuk rute admin.

**TARGET STATE.** Permukaan produk memakai Laravel + Blade + Tailwind CSS v4 pada stack frontend yang sudah terpasang. Token dan primitif menggantikan CSS inline di layout bersama.

**LOCKED RULE.** Jangan migrasi framework, jangan menambah Livewire/Vue/React/Alpine hanya untuk redesign, dan jangan mengkanonisasi nilai CSS lama sebagai token final. Nilai warna, radius, dan font di layout adalah bukti CURRENT STATE. Dokumen token nanti wajib memisahkan CURRENT VALUE dan TARGET TOKEN / TARGET VALUE.

## 4. Arah visual

**LOCKED RULE.** Arah visual adalah Clean Educational SaaS.

Dipertahankan: hierarki tipografi, spasi, perataan, satu aksi utama yang jelas, aksi sekunder yang kontekstual, warna status yang semantik, garis halus, bayangan yang hemat, ikon yang konsisten, pengungkapan bertahap, serta keadaan kosong, memuat, dan gagal yang berguna. Harus kuat di layar sempit.

Dihindari: gradien ungu/biru berlebih, gaya neon, glassmorphism yang tidak perlu, setiap bagian menjadi kartu, kartu bersarang, radius/spasi/bayangan acak, sistem ikon emoji, metrik SaaS palsu, hiasan "AI", animasi berlebih, dan tampilan dasbor generik.

Bukan sasaran: terlalu korporat, kekanak-kanakan, atau futuristik.

## 5. Kunci stack

**LOCKED RULE.**

```text
Laravel + Blade + Tailwind CSS v4 + Vite yang sudah ada
```

Tidak ada framework UI baru. Tidak ada perubahan `composer.json`, `package.json`, atau `vite.config.js` pada fase dokumentasi. Perubahan berkas itu pada fase implementasi hanya boleh terjadi jika spesifikasi yang sudah disetujui memang membutuhkan pemanggilan aset yang sudah terpasang, bukan dependensi baru.

## 6. Alur kerja: dokumentasi dahulu

**LOCKED RULE.** Urutan program:

1. UI-0 menyelesaikan dokumentasi di `docs/ui-ux/` dan mendapat persetujuan Council.
2. Baru kemudian UI-1 sampai UI-7 mengubah tampilan, masing-masing setelah spesifikasi fase itu disetujui.

**CURRENT STATE dokumen UI-0**

| Berkas | Status |
| --- | --- |
| `docs/ui-ux/00-UI-UX-MASTER-PLAN.md` | Pass 1, dokumen ini |
| `docs/ui-ux/11-IMPLEMENTATION-GUARDRAILS.md` | Pass 1 |
| `01` sampai `10` | belum dibuat; menunggu otorisasi pass berikutnya |

**TARGET STATE** set dokumentasi UI-0, masing-masing satu berkas, bukan satu berkas per halaman:

| Berkas | Peran |
| --- | --- |
| `00-UI-UX-MASTER-PLAN.md` | Rencana program dan kunci fase |
| `01-DESIGN-PRINCIPLES.md` | Prinsip visual produk ini |
| `02-DESIGN-TOKENS.md` | CURRENT VALUE versus TARGET TOKEN |
| `03-COMPONENT-SPECIFICATION.md` | Primitif dan komponen domain yang memang terbukti terduplikasi |
| `04-UX-WRITING-INDONESIA.md` | Glosarium dan aturan copy |
| `05-RESPONSIVE-RULES.md` | Aturan lebar dan permukaan berisiko |
| `06-PAGE-INVENTORY.md` | ID halaman, rute, dan peta perjalanan |
| `07-PAGE-SPECIFICATIONS.md` | Satu bagian spesifikasi per ID halaman |
| `08-STATE-AND-FEEDBACK-SYSTEM.md` | Kontrak status asinkron dan kelayakan |
| `09-ACCESSIBILITY-STANDARD.md` | Label, fokus, live region, konfirmasi |
| `10-VISUAL-QA-CHECKLIST.md` | Daftar periksa visual untuk fase implementasi |
| `11-IMPLEMENTATION-GUARDRAILS.md` | Larangan perubahan domain |

Dokumen penjelasan ditulis dalam Bahasa Indonesia. Nama rute, kelas, status tersimpan, dan path tetap seperti di kode.

## 7. Inventaris baseline: 27 permukaan halaman

**CURRENT STATE.** Audit mengunci 27 ID halaman penuh. Semuanya memakai `layouts.app`. `welcome.blade.php` tidak termasuk.

| ID | Rute | View |
| --- | --- | --- |
| PUB-01 | `home` | `home` |
| AUTH-01 | `profile.setup` | `profile.setup` |
| DASH-01 | `dashboard` | `dashboard` |
| DASH-02 | `admin.dashboard` | `admin.dashboard` |
| MAT-01 | `materials.index` | `materials.index` |
| MAT-02 | `materials.archived` | `materials.index` dengan mode arsip |
| MAT-03 | `materials.create` | `materials.create` |
| MAT-04 | `materials.show` | `materials.show` |
| MAT-05 | `materials.edit` | `materials.edit` |
| PROF-01 | `materials.profile.show` | `materials.profile.show` |
| BP-01 | `materials.blueprints.index` | `blueprints.index` |
| BP-02 | `materials.blueprints.create` | `blueprints.create` |
| BP-03 | `materials.blueprints.show` | `blueprints.show` |
| IMP-01 | `materials.blueprint-imports.index` | `materials.blueprint-imports.index` |
| IMP-02 | `materials.blueprint-imports.show` | `materials.blueprint-imports.show` |
| GEN-01 | `generations.index` | `generations.index` |
| GEN-02 | `generations.create` | `generations.create` |
| GEN-03 | `generations.show` | `generations.show` |
| GEN-04 | `generation-runs.create` | `generation-runs.create` |
| GEN-05 | `generation-runs.show` | `generation-runs.show` |
| QB-01 | `question-sets.index` | `question-sets.index` |
| QB-02 | `question-sets.show` | `question-sets.show` |
| QB-03 | `question-sets.edit` | `question-sets.edit` |
| ACC-01 | `account.subscription.show` | `account.subscription.show` |
| ACC-02 | `account.subscription.show` | `account.subscription.unavailable` |
| ADM-01 | `admin.subscription-upgrades.index` | `admin.subscription-upgrades.index` |
| ADM-02 | `admin.subscription-upgrades.show` | `admin.subscription-upgrades.show` |

Detail tujuan, aksi utama, dan spesifikasi tiap ID ditulis nanti di `06` dan `07`. Jangan menambah ID untuk permukaan yang tidak ada.

### Halaman versus bukan halaman

**LOCKED RULE.** Rute JSON, unduhan, redirect, dan mutasi POST/PATCH/DELETE bukan halaman. Mereka tidak mendapat ID halaman.

Bukan halaman, terverifikasi:

- `GET /up`
- `storage.local` dan unggah storage
- `login` (redirect ke Google), `auth.google.redirect`, `auth.google.callback`, `logout`
- Status JSON: `materials.profile.status`, `materials.blueprints.status`, `materials.blueprint-imports.status`, `generations.status`, `generation-runs.status`
- Unduhan: `materials.blueprints.download`, `question-sets.download-student`, `question-sets.download-teacher`
- Mutasi yang melayani halaman di atas (unggah, arsip, topik, impor, grounding, konversi, konfirmasi kisi-kisi, generasi, simpan ke bank soal, langganan, keputusan admin)

**CURRENT STATE.** Tidak ada rute JSON untuk polling ekstraksi berkas materi. Umpan balik ekstraksi materi saat ini adalah muat ulang manual di MAT-04.

## 8. Struktur fase

**LOCKED RULE.** Delapan fase. Jangan digabung. Dua arsitektur generasi tetap terpisah pada UI-5.

### UI-0 — Audit dan spesifikasi

**CURRENT STATE.** Audit repositori PASS. Pass 1 menulis dokumen `00` dan `11` saja.

Tujuan: sumber kebenaran desain sebelum kode UI berubah.

Kriteria keluar:

- Dua belas berkas `docs/ui-ux/` pada bagian 6 ada dan disetujui Council.
- Inventaris 27 ID, peta rute, prinsip, token (dengan pemisahan current/target), komponen, penulisan Bahasa Indonesia, aturan responsif, sistem status, aksesibilitas, spesifikasi halaman, daftar QA visual, dan guardrail sudah tercakup.
- Tidak ada perubahan kode aplikasi pada UI-0.

### UI-1 — Fondasi design system dan shell bersama

Tujuan: memasukkan Tailwind v4 ke layout yang benar-benar dipakai, mengganti CSS inline dengan token dan primitif, serta membuat shell bersama layak dipakai di lebar sempit.

Memiliki: fondasi design system + shell bersama (`layouts.app`). Tidak merancang ulang isi halaman.

Tidak memiliki: penempatan entri admin. Itu keputusan arsitektur informasi pada UI-3, bukan kunci "tautan admin di navbar global".

Kriteria keluar:

- Layout tidak lagi menyimpan design system sebagai CSS inline.
- Primitif shell dipakai oleh chrome.
- Halaman yang ada tetap mengirim form yang sama.
- Audit visual browser pada bagian 9 selesai sebelum UI-1 ditutup. Audit sumber saja tidak cukup.

### UI-2 — Publik dan penyiapan profil

Tujuan: menyegarkan PUB-01 dan AUTH-01.

Kriteria keluar: kedua halaman sesuai spesifikasi; masuk tetap lewat Google; tidak ada form kata sandi atau registrasi; pengguna dengan profil belum lengkap tetap tidak dapat melewati AUTH-01.

### UI-3 — Dasbor dan ketertemuan admin

Tujuan: DASH-01 punya satu langkah berikutnya yang jelas; DASH-02 dapat ditemukan oleh admin tanpa menambah kemampuan admin.

Memiliki: isi dasbor dan ketertemuan admin.

Tidak memiliki: penulisan ulang shell yang sudah diselesaikan UI-1, dan tidak memiliki fitur pemantauan baru.

Kriteria keluar: aksi utama DASH-01 sesuai spesifikasi; admin dapat mencapai permukaan admin yang sudah ada; non-admin tidak melihat entri itu. Penempatan entri ditentukan spesifikasi halaman, bukan dokumen ini.

### UI-4 — Materi, profil materi, dan impor DOCX

Tujuan: MAT-01 sampai MAT-05, PROF-01, IMP-01, IMP-02.

Kriteria keluar: spesifikasi ID itu terpenuhi; pesan kelayakan profil tetap terlihat; penyelesaian interpretasi tidak disajikan sebagai izin konversi; formulir konversi tetap mengikuti syarat yang sudah ada (lihat guardrail).

### UI-5 — Kisi-kisi dan kedua alur generasi

Tujuan: BP-01 sampai BP-03 serta kedua produk generasi.

- Generasi materi lama: GEN-01, GEN-02, GEN-03 (`GenerationController`, rute `generations.*`).
- Generasi dari kisi-kisi: GEN-04, GEN-05 (`GenerationRunController`, rute `generation-runs.*`).

**LOCKED RULE.** Jangan digabung, jangan disembunyikan, jangan dipindah ke satu controller.

Kriteria keluar: kedua jalur tetap dapat dicapai dari titik masuknya masing-masing; kunci Pro, batas draf, dan aksi simpan ke bank soal tetap mengenai rute yang sudah ada.

### UI-6 — Bank soal, langganan, dan tinjauan admin

Tujuan: QB-01 sampai QB-03, ACC-01, ACC-02, ADM-01, ADM-02.

Kriteria keluar: status draf/terbit dan unduhan tetap pada rute yang ada; ACC-02 tetap tanpa checkout; filter admin tetap memakai nilai kueri status yang ada; tidak ada kemampuan admin baru.

### UI-7 — Pelokalan, responsif, aksesibilitas, dan regresi

Tujuan: sapuan sisa, bukan penerjemahan pertama. Setiap fase UI-2 sampai UI-6 sudah melokalkan halaman yang disentuhnya.

Kriteria keluar: daftar periksa `10` lulus pada 27 ID; tidak ada sisa copy Inggris yang seharusnya menjadi Bahasa Indonesia pada UI normal; pengenal kode tetap Inggris; regresi jalur utama lulus; Council menyetujui penutupan.

## 9. QA visual browser

**LOCKED RULE.** Audit sumber tidak membuktikan perilaku tergambar. Sebelum UI-1 ditutup, wajib ada audit visual di browser pada lebar 360, 390, 768, 1024, dan 1440 px.

Yang tidak boleh dinyatakan lulus hanya dari pembacaan Blade:

- luapan horizontal di ponsel
- kontras
- urutan fokus
- hierarki visual
- perilaku sentuh
- ukuran QRIS

Ketidakpastian yang sama tetap dicatat untuk tabel lebar, editor kisi-kisi, tinjauan impor, dan shell. Kegagalan audit browser dapat menaikkan keparahan temuan, tetapi tidak boleh dipakai sebagai alasan mengubah domain.

## 10. Gerbang persetujuan Council

**LOCKED RULE.**

| Gerbang | Syarat sebelum lanjut |
| --- | --- |
| Selesai UI-0 | Dua belas dokumen disetujui. Kode aplikasi tidak berubah. |
| Mulai tiap fase UI-1–UI-7 | Spesifikasi fase itu sudah disetujui, dan fase sebelumnya memenuhi kriteria keluarnya. |
| Tutup UI-1 | Audit visual browser bagian 9 sudah dilakukan. |
| Tutup UI-7 | Daftar QA dan regresi disetujui Council. |

Agen tidak menandai fase COMPLETE tanpa persetujuan itu.

## 11. Kebijakan stage dan commit

**LOCKED RULE.**

- Jangan `git add .` atau `git add -A`.
- Stage hanya path dokumentasi atau kode yang memang termasuk fase yang sedang dikerjakan dan sudah diminta untuk di-commit.
- Artefak tidak terkait pada bagian 2 tidak pernah di-stage, di-clean, atau di-reset.
- UI-0 yang selesai cukup satu commit dokumentasi, hanya `docs/ui-ux/`. Jangan di-commit pada Pass 1.
- Jangan mencampur commit UI dengan perubahan domain.

## 12. Hubungan dokumen dan implementasi

**LOCKED RULE.**

- Master plan ini mengunci cakupan dan urutan, bukan tampilan tiap layar.
- Guardrail mengunci apa yang tidak boleh berubah.
- Spesifikasi halaman (`07`) adalah kontrak implementasi per ID, setelah dokumen pendukungnya ada.
- Jika perbaikan UX tampak membutuhkan perubahan perilaku, tulis **SEPARATE PRODUCT/DOMAIN DECISION REQUIRED** dan keluarkan dari implementasi UI.
- Jika dokumen dan kode bertentangan, kode repositori pada baseline yang disetujui menang untuk fakta perilaku. Dokumen menang untuk TARGET STATE visual hanya setelah Council menyetujui dokumen itu.

## 13. Koreksi Council yang mengikat

**LOCKED RULE.**

A. Nilai CSS saat ini adalah bukti, bukan token final. `02-DESIGN-TOKENS.md` wajib memisahkan CURRENT VALUE dan TARGET TOKEN / TARGET VALUE. Jangan menyalin seluruh palet lama menjadi kanon.

B. `config/app.php` memakai locale bawaan `en`, sementara layout menulis `<html lang="id">`. Itu temuan pelokalan. Mengubah locale runtime bukan tugas otomatis UI-1. Perubahan locale harus dinilai terpisah karena dapat mengubah pesan framework dan validasi.

C. Ketertemuan admin adalah masalah UI. Solusinya tidak dikunci menjadi tautan admin di navbar global. `07` untuk DASH-02 memutuskan penempatan berdasarkan arsitektur informasi.

D. Audit visual browser wajib sebelum UI-1 ditutup. Lihat bagian 9.

## 14. Pertanyaan terbuka

**OPEN QUESTION.** Tidak menghalangi penulisan dokumen UI-0 berikutnya, dan tidak boleh diselesaikan di dalam implementasi UI:

- Apakah generasi materi lama suatu saat dipensiunkan. Saat ini keduanya hidup.
- Apakah ekstraksi berkas materi perlu rute status JSON. Saat ini tidak ada. UI tidak membuatnya.
- Apakah `welcome.blade.php` yang tidak terpakai akan dihapus. Bukan bagian fase UI.
- Penempatan entri admin. Diputuskan di spesifikasi UI-3, bukan di sini.
- Nilai token final (warna, font, radius). Diputuskan di `02`, dengan memisahkan nilai lama dan nilai target.
- Apakah locale aplikasi diubah. Diputuskan di luar UI-1. Lihat koreksi B.
