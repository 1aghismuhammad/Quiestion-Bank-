# Spesifikasi halaman UI/UX Refresh V1

Status dokumen: **UI-0 Pass 7**. Satu bagian per ID inventaris. Tepat 27. Bukan implementasi.

Rujukan: prinsip, token, komponen, bahasa, responsif, inventaris, keadaan, aksesibilitas, dan guardrail. Bagian ini tidak menyalin dokumen itu. Ia mengunci keputusan per halaman.

Layout semua ID: `resources/views/layouts/app.blade.php`.

**TARGET STATE entri admin.** Di DASH-01, pengguna yang sudah berperan admin melihat satu tautan tersier "Dasbor admin" ke `admin.dashboard`. Bukan di nav global, karena nav sudah padat dan pekerjaan admin jarang. Tidak ada rute baru dan tidak ada pelonggaran `role:admin`. Pengguna lain tidak melihat tautan itu. URL tetap ditolak middleware bila mereka bukan admin.

## PUB-01

ID PUB-01. Area publik. Rute `home`, GET `/`. Audiens tamu atau pengguna yang sudah masuk. Layout `resources/views/layouts/app.blade.php`. Tujuan: masuk ke produk. Sasaran pengguna: mulai dengan Google, atau lanjut ke dasbor.

Current state summary: satu kartu tengah, eyebrow Inggris, tombol Google atau Buka Dashboard.

Target experience: satu kolom baca tenang di tengah `--container-reading`. Judul halaman (`h1`) yang menjelaskan produk. Satu kalimat pendukung. Satu aksi primer. Tidak ada form kata sandi. Tidak ada Card atau Panel sebagai pembungkus hiasan.

Primary action by state:
- Tamu: Login dengan Google (primer, isi merek).
- Sudah masuk: Buka dasbor (primer, isi merek).
Nol aksi primer tidak berlaku di sini.

Secondary actions: tidak ada.

Information hierarchy:
1. Judul halaman (h1): nama produk.
2. Satu kalimat pendukung: menjelaskan nilai produk.
3. Aksi primer.

Section structure: satu bagian tunggal — PageHeader (judul + pendukung) diikuti slot aksi primer. Tidak ada bagian sekunder.

Components: PageHeader, Button.

View modes: tamu versus sudah masuk (controller menentukan).

Async contract: NONE.

Companion routes: `login` redirect ke Google (bukan halaman).

Empty state: tidak berlaku (halaman selalu menampilkan konten yang sama).

Loading / processing state: tidak berlaku.

Error state: tidak berlaku (Google OAuth menangani kegagalannya sendiri).

Success / terminal state: tidak ada flash wajib; redirect ke dasbor dilakukan server.

Desktop behavior: konten terpusat di `--container-reading` (40rem), padding vertikal `--space-10` ke atas/bawah. Primer di bawah pendukung.

Tablet behavior: sama seperti desktop, konten tetap di `--container-reading`.

Mobile behavior (360/390): satu kolom, primer terlihat tanpa gulir horizontal halaman. Padding horizontal `--page-padding-inline`.

UX writing direction: hilangkan eyebrow Inggris. Gunakan Bahasa Indonesia tenang: "Masuk ke [Nama Produk]", "Mulai dengan Google", "Buka dasbor". Label verba pada Button.

Accessibility requirements: satu `h1`. Tautan bermakna (bukan "klik di sini"). Fokus terlihat pada tombol. Tidak ada gambar dekoratif.

Domain behavior that must remain unchanged: hanya Google OAuth. Jangan membuat registrasi email/kata sandi, reset kata sandi, atau verifikasi email. Middleware `guest` pada rute `login` tetap.

Acceptance criteria:
- Pada 1440 px: konten berada di tengah, lebar tidak melebihi `--container-reading`, tidak ada kartu pembungkus.
- Pada 390 px: tidak ada gulir horizontal halaman; primer sentuh tercapai.
- Tamu tidak melihat form email/kata sandi.
- Sudah masuk melihat "Buka dasbor" sebagai primer.

Visual QA evidence required: desktop 1440 (tamu & sudah masuk), mobile 390 (tamu & sudah masuk).

## AUTH-01

ID AUTH-01. Area akun. Rute `profile.setup`, GET `/profile/setup`. Audiens `auth` + `account.active`, profil belum lengkap. Layout `resources/views/layouts/app.blade.php`. Tujuan: menyimpan nomor WhatsApp. Sasaran pengguna: mengisi WhatsApp lalu lanjut ke dasbor.

Current state summary: form satu field, label terikat, galat di dekat field.

Target experience: `--container-form`. Primer Simpan dan lanjutkan. Sekunder tidak ada. Tidak ada jalan pintas melewati `profile.complete`. Fokus pada field, galat server di dekat kontrol.

Primary action by state:
- Belum isi: Simpan dan lanjutkan (primer, isi merek).
- Isi valid: Simpan dan lanjutkan (primer, isi merek).
- Galat server: Simpan dan lanjutkan tetap primer, disabled sementara respons error.

Secondary actions: tidak ada.

Information hierarchy:
1. Judul halaman (h1): Lengkapi profil.
2. Penjelasan singkat: nomor WhatsApp untuk notifikasi.
3. Field nomor WhatsApp (label, input, galat).
4. Primer: Simpan dan lanjutkan.

Section structure:
- Header: PageHeader (judul + penjelasan).
- Form: TextInput + Button.
- Footer: tidak ada.

Components: PageHeader, TextInput, Button, Alert bila flash.

View modes: tunggal (profil belum lengkap).

Async contract: NONE.

Companion routes: POST `profile.setup.store`.

Empty state: tidak berlaku (halaman hanya diakses bila profil belum lengkap).

Loading / processing state: tidak berlaku (submit form standar, tidak polling).

Error state: galat validasi di dekat field nomor (server-side). Flash danger layout bila galat non-field.

Success / terminal state: redirect ke `dashboard` oleh server, bukan halaman ini. Flash success layout boleh tampil di dasbor.

Desktop behavior: satu kolom `--container-form` (28rem), field lebar penuh, primer di bawah field.

Tablet behavior: sama seperti desktop.

Mobile behavior (360/390): satu kolom, field lebar penuh `--page-padding-inline`, primer terlihat tanpa gulir.

UX writing direction: "Lengkapi profil", "Nomor WhatsApp", "Simpan dan lanjutkan". Bahasa Indonesia. Hilangkan istilah teknis.

Accessibility requirements: label terikat ke input (`for`/`id`). Galat di dekat field dengan `aria-describedby`. Fokus terlihat. Satu `h1`.

Domain behavior that must remain unchanged: normalisasi nomor tetap di server (`ProfileSetupRequest`). Middleware `profile.complete` menahan akses dasbor. Hanya Google OAuth untuk login.

Acceptance criteria:
- Pada 390 px: field dan primer terlihat, tidak ada gulir horizontal halaman.
- Galat validasi tampil di dekat field, bukan hanya flash.
- Pengguna belum lengkap tidak bisa mengakses dasbor via URL manipulasi.

Visual QA evidence required: mobile 390 (field kosong, isi valid, galat validasi).

## DASH-01

ID DASH-01. Area dasbor. Rute `dashboard`, GET `/dashboard`. Audiens aplikasi (`auth` + `account.active` + `profile.complete`). Layout `resources/views/layouts/app.blade.php`. Tujuan: satu langkah berikutnya yang jelas. Sasaran pengguna: masuk ke materi, karena pembuatan soal yang ada dimulai dari materi.

Current state summary: lima kartu setara. Dua kartu menuju `materials.index`. Tidak ada tautan admin.

Target experience: PageHeader "Dasbor". Primer Kelola materi ke `materials.index`. Sekunder: Bank soal (`question-sets.index`), Langganan (`account.subscription.show`). Tersier, hanya bila pengguna sudah admin: tautan "Dasbor admin" ke `admin.dashboard`. Jangan mengarang metrik. Status akun boleh satu StatusBadge, bukan kartu angka.

Primary action by state:
- Selalu: Kelola materi (primer, isi merek).

Secondary actions:
- Bank soal (sekunder, secondary).
- Langganan (sekunder, secondary).

Tertiary actions:
- Dasbor admin (tersier, tertiary, hanya admin).

Information hierarchy:
1. Sapaan singkat (opsional, satu baris).
2. Primer: Kelola materi.
3. Sekunder: Bank soal, Langganan.
4. Tersier (admin): Dasbor admin.

Section structure:
- Header: PageHeader (judul "Dasbor" + sapaan).
- Aksi: grup tombol primer + sekunder + tersier.
- Footer: tidak ada.

Components: PageHeader, Button, StatusBadge opsional (status akun).

View modes: pengguna biasa versus admin (tersier hanya admin).

Async contract: NONE.

Companion routes: tidak ada mutasi di halaman ini.

Empty state: tidak berlaku (halaman selalu memiliki aksi primer).

Loading / processing state: tidak berlaku.

Error state: tidak berlaku.

Success / terminal state: flash sukses dari aksi lain (mis. publikasi, pembayaran) lewat layout.

Desktop behavior: primer menonjol (isi merek), sekunder (secondary) di samping atau di bawah, tersier (tertiary) di bawah atau di samping sekunder. Tidak ada grid kartu.

Tablet behavior: sama seperti desktop, tata letak menyesuaikan lebar.

Mobile behavior (360/390): tumpuk vertikal, primer paling atas, lalu sekunder, lalu tersier. Admin tidak memenuhi nav global.

UX writing direction: "Dasbor" bukan "Dashboard". "Kelola materi", "Bank soal", "Langganan", "Dasbor admin". Satu `h1`.

Accessibility requirements: satu `h1`. Tombol punya label terlihat. Fokus terlihat. Tautan admin bermakna. StatusBadge punya teks.

Domain behavior that must remain unchanged: tidak menambah data dasbor baru. Middleware `profile.complete` tetap. `role:admin` middleware pada `admin.dashboard` tetap. Tidak ada analitik baru.

Acceptance criteria:
- Pada 390 px: hanya satu tombol isi-merek terlihat (primer), sekunder/tersier secondary/tertiary.
- Non-admin tidak melihat tautan "Dasbor admin".
- Tidak ada metrik hias (angka dalam kartu).

Visual QA evidence required: desktop 1440 (biasa & admin), mobile 390 (biasa & admin).

## DASH-02

ID DASH-02. Area admin. Rute `admin.dashboard`, GET `/admin/dashboard`. Audiens admin (`auth` + `account.active` + `profile.complete` + `role:admin`). Layout `resources/views/layouts/app.blade.php`. Tujuan: dua hitungan yang sudah ada dan jalan ke verifikasi pembayaran. Sasaran pengguna: membuka daftar permintaan upgrade.

Current state summary: dua angka dan tombol verifikasi plus materi. Kalimat "Phase 1". Tidak ditaut dari shell (nav global).

Target experience: ditemukan dari tautan tersier DASH-01. Primer: Verifikasi pembayaran ke ADM-01 (`admin.subscription-upgrades.index` dengan filter `pending`). Sekunder: kembali ke dasbor pengguna (`dashboard`). Hitungan tetap dua nilai yang controller kirim, ditampilkan sebagai teks biasa, bukan kartu metrik hias. Hapus kalimat "Phase 1". Jangan menambah analitik.

Primary action by state:
- Selalu: Verifikasi pembayaran (primer, isi merek).

Secondary actions:
- Kembali ke dasbor pengguna (sekunder, secondary).

Information hierarchy:
1. Judul halaman (h1): Dasbor admin.
2. Dua hitungan: "Permintaan tertunda: X", "Total pengguna Pro: Y" (teks, bukan kartu).
3. Primer: Verifikasi pembayaran.
4. Sekunder: Kembali ke dasbor pengguna.

Section structure:
- Header: PageHeader (judul "Dasbor admin" + hitungan).
- Aksi: grup tombol primer + sekunder.
- Footer: tidak ada.

Components: PageHeader, Button.

View modes: tunggal (hanya admin yang bisa mengakses).

Async contract: NONE.

Companion routes: tidak ada mutasi di halaman ini.

Empty state: tidak berlaku (halaman selalu memiliki hitungan dan aksi).

Loading / processing state: tidak berlaku.

Error state: tidak berlaku (middleware menolak non-admin sebelum halaman dimuat).

Success / terminal state: tidak ada flash wajib di halaman ini.

Desktop behavior: hitungan sebagai teks, primer dan sekunder berdampingan atau tumpuk.

Tablet behavior: sama seperti desktop.

Mobile behavior (360/390): tumpuk vertikal, hitungan di atas, primer di bawah, sekunder di paling bawah.

UX writing direction: "Dasbor admin", "Permintaan tertunda", "Total pengguna Pro", "Verifikasi pembayaran", "Kembali ke dasbor". Satu `h1`.

Accessibility requirements: satu `h1`. Tombol punya label terlihat. Fokus terlihat. Tautan kembali bermakna. Hitungan sebagai teks biasa (bukan hanya angka dalam kartu).

Domain behavior that must remain unchanged: `role:admin` middleware tetap. Non-admin tetap ditolak oleh middleware `role:admin` sesuai perilaku server yang ada. Tidak ada kemampuan baru. Tidak ada analitik baru. Hanya dua hitungan yang sudah ada di controller.

Acceptance criteria:
- Non-admin tetap ditolak oleh middleware `role:admin` sesuai perilaku server yang ada.
- Pada 390 px: hitungan terbaca, primer dan sekunder tumpuk, tidak ada gulir horizontal.
- Tidak ada kalimat "Phase 1".
- Hanya dua hitungan yang sudah ada.

Visual QA evidence required: desktop 1440 (admin), mobile 390 (admin). Non-admin tetap ditolak oleh middleware `role:admin` sesuai perilaku server yang ada. Jangan mensyaratkan tangkapan HTTP 403.

## MAT-01

ID MAT-01. Area materi. Rute `materials.index`, GET `/materials`. Audiens aplikasi (`auth` + `account.active` + `profile.complete`). Layout `resources/views/layouts/app.blade.php`. Tujuan: daftar materi aktif. Sasaran pengguna: membuat atau membuka materi.

Current state summary: tabel enam kolom, enum mentah (`source_type`, `extraction_status`), eyebrow Inggris ("Material Management"). Primer saat ini Buat materi.

Target experience: PageHeader "Materi saya". Primer Buat materi ke `materials.create` (isi merek). Sekunder Arsip ke `materials.archived` (secondary). Tabel atau ringkasan baris sesuai aturan responsif. Status lewat MaterialStatus dan StatusBadge, label Indonesia, tanpa enum mentah.

Primary action by state:
- Ada data: Buat materi (primer, isi merek).
- Kosong: Buat materi (primer, isi merek) — sama, karena EmptyState menyertakan primer.

Secondary actions:
- Arsip (sekunder, secondary).

Information hierarchy:
1. Judul halaman (h1): Materi saya.
2. Primer: Buat materi.
3. Sekunder: Arsip.
4. Daftar: tabel/ringkasan (judul, status ekstraksi, diperbarui, aksi).

Section structure:
- Header: PageHeader (judul + aksi primer/sekunder).
- Isi: Table/ringkasan ATAU EmptyState.
- Footer: Pagination bila ada.

Components: PageHeader, Table atau ringkasan baris, MaterialStatus, StatusBadge, EmptyState, Pagination.

View modes: ada data versus kosong.

Async contract: STATEFUL BUT NOT POLLING. Bukan polling. Status ekstraksi bersifat stateful (tersimpan di DB), muat ulang halaman untuk memperbarui.

Companion routes: tidak ada. Unggah, ubah, arsip, dan pulihkan bukan aksi halaman daftar ini.

Empty state: EmptyState + primer Buat materi (sama seperti ada data). Kalimat: "Belum ada materi. Mulai dengan mengunggah PDF, DOCX, atau TXT."

Loading / processing state: tidak berlaku (tidak ada polling).

Error state: tidak berlaku (daftar tidak memiliki error state sendiri; galat mutasi lewat flash layout).

Success / terminal state: flash sukses layout boleh muncul bila aksi di halaman lain baru saja selesai. Daftar ini sendiri tidak melakukan arsip, ubah, atau pulihkan.

Desktop behavior: tabel penuh (judul, status, diperbarui, aksi). StatusBadge di kolom status. Pagination di bawah.

Tablet behavior: tabel dengan gulir horizontal terkandung di wilayah tabel, atau ringkasan baris (judul, status, diperbarui) bila spesifikasi memilih.

Mobile behavior (360/390): ringkasan baris (judul, status, diperbarui) tanpa gulir halaman. Membuka materi adalah navigasi. Tidak ada aksi arsip atau ubah di daftar. Pagination membungkus.

UX writing direction: "Materi saya" bukan "Material Management". Status ekstraksi selesai: Selesai. Bukan Siap. Selesai tidak berarti materi boleh dibuat soalnya. Hilangkan enum `source_type` mentah di samping label.

Accessibility requirements: satu `h1`. Label tabel/header terikat. StatusBadge punya teks, bukan hanya warna. Fokus terlihat pada tautan baris dan pagination. Area sentuh cukup pada mobile.

Domain behavior that must remain unchanged: filter bukan arsip tetap di controller (`MaterialController@index` dengan `archived=false`). Enum `source_type` dan `extraction_status` tetap di DB; UI hanya menampilkan label. Tidak menambah jenis unggah baru.

Acceptance criteria:
- Pada 390 px: tabel tidak mendorong halaman; ringkasan baris terbaca; pagination tidak meluap.
- Enum `source_type` tidak tampil mentah di UI.
- Status ekstraksi label Indonesia, bukan enum.
- Kosong menampilkan primer Buat materi.

Visual QA evidence required: desktop 1440 (kosong & berisi), mobile 390 (kosong & berisi).

## MAT-02

ID MAT-02. Area materi. Rute `materials.archived`, GET `/materials/archived`. Audiens aplikasi (`auth` + `account.active` + `profile.complete`). Layout `resources/views/layouts/app.blade.php`. Tujuan: melihat arsip materi. Sasaran pengguna: menemukan materi yang sudah diarsipkan, lalu membuka detailnya bila perlu.

Current state summary: view yang sama dengan MAT-01, tetapi data arsip.

Target experience: PageHeader "Materi terarsip". Primer tidak ada. Tersier "Materi aktif" ke `materials.index`. Jangan merender tombol "Buat materi" di sini. Tabel atau ringkasan baris sesuai aturan responsif. Status lewat MaterialStatus dan StatusBadge.

Primary action by state:
- Selalu: tidak ada aksi primer.

Secondary actions: tidak ada.

Tertiary actions:
- Materi aktif (tersier, tertiary).

Information hierarchy:
1. Judul halaman (h1): Materi terarsip.
2. Tersier: Materi aktif.
3. Daftar: tabel/ringkasan (judul, status ekstraksi, diperbarui). Membuka baris adalah navigasi. Tidak ada aksi pulihkan atau hapus.

Section structure:
- Header: PageHeader (judul + aksi tersier).
- Isi: Table/ringkasan ATAU EmptyState.
- Footer: Pagination bila ada.

Components: PageHeader, Table atau ringkasan baris, MaterialStatus, StatusBadge, EmptyState, Pagination.

View modes: ada data versus kosong.

Async contract: STATEFUL BUT NOT POLLING.

Companion routes: tidak ada. Memulihkan materi bukan aksi halaman daftar arsip ini.

Empty state: EmptyState. Kalimat: "Tidak ada materi terarsip." Tidak ada tombol primer "Buat materi".

Loading / processing state: tidak berlaku.

Error state: tidak berlaku.

Success / terminal state: tidak ada aksi domain di halaman ini. Flash dari halaman lain boleh lewat layout.

Desktop behavior: tabel penuh (judul, status, diperbarui). Pagination di bawah. Tidak ada kontrol pulihkan atau hapus.

Tablet behavior: tabel dengan gulir horizontal terkandung, atau ringkasan baris.

Mobile behavior (360/390): ringkasan baris tanpa gulir halaman. "Materi aktif" adalah navigasi. Tidak ada kontrol pulihkan atau hapus. Pagination membungkus.

UX writing direction: "Materi terarsip". "Materi aktif". Jangan memakai "Pulihkan" sebagai aksi halaman ini.

Accessibility requirements: satu `h1`. Label tabel/header terikat. Fokus terlihat pada tautan buka materi dan "Materi aktif".

Domain behavior that must remain unchanged: kueri hanya status arsip (`MaterialController@archived`). Halaman ini tidak menambah aksi pulihkan atau hapus.

Acceptance criteria:
- Pada 390 px: tabel tidak mendorong halaman; ringkasan baris terbaca; pagination tidak meluap.
- Tidak ada tombol "Buat materi" di halaman arsip.
- Kosong tidak menampilkan primer.

Visual QA evidence required: desktop 1440 (kosong & berisi), mobile 390 (kosong & berisi).

## MAT-03

ID MAT-03. Area materi. Rute `materials.create`, GET `/materials/create`. Audiens aplikasi (`auth` + `account.active` + `profile.complete`). Layout `resources/views/layouts/app.blade.php`. Tujuan: unggah berkas sumber. Sasaran pengguna: mengirim PDF, DOCX, atau TXT untuk diekstraksi.

Current state summary: form unggah dengan judul opsional.

Target experience: `--container-form`. Primer Unggah materi. Sekunder kembali. FileInput dengan label terlihat.

Primary action by state:
- Selalu: Unggah materi (primer, isi merek).
- Saat submit: Unggah materi (disabled sementara).

Secondary actions:
- Batal/kembali ke daftar (sekunder, secondary atau tertiary).

Information hierarchy:
1. Judul halaman (h1): Unggah materi.
2. Field berkas (wajib).
3. Field judul (opsional).
4. Primer: Unggah materi.

Section structure:
- Header: PageHeader.
- Form: FileInput + TextInput + Button.
- Footer: tidak ada.

Components: PageHeader, TextInput, FileInput, Button.

View modes: tunggal (form unggah).

Async contract: NONE.

Companion routes: POST `materials.store-upload`.

Empty state: tidak berlaku.

Loading / processing state: tidak berlaku (submit standar).

Error state: galat validasi di dekat field (server-side, misal ukuran terlalu besar atau tipe salah).

Success / terminal state: redirect ke detail materi oleh server (`materials.show`).

Desktop behavior: satu kolom `--container-form` (28rem), field lebar penuh, primer di bawah.

Tablet behavior: sama seperti desktop.

Mobile behavior (360/390): satu kolom, padding halaman, primer terlihat tanpa gulir.

UX writing direction: "Unggah materi". Label berkas: "Berkas PDF, DOCX, atau TXT". Label judul opsional.

Accessibility requirements: satu `h1`. Label terikat ke input. Fokus terlihat. Galat `aria-describedby`. FileInput tidak menyembunyikan label asli untuk aksesibilitas.

Domain behavior that must remain unchanged: jenis berkas dan batas ukuran tetap di FormRequest. Jangan menambah unggah jenis lain (mis. gambar). Ekstraksi dipicu oleh controller setelah simpan.

Acceptance criteria:
- Pada 390 px: primer terlihat; form tidak memicu gulir horizontal.
- Label berkas terlihat.
- Galat validasi tampil di dekat field.

Visual QA evidence required: desktop 1440, mobile 390 (kosong, diisi, galat validasi).

## MAT-04

ID MAT-04. Area materi. Rute `materials.show`, GET `/materials/{material}`. Audiens aplikasi (`auth` + `account.active` + `profile.complete`). Layout `resources/views/layouts/app.blade.php`. Tujuan: memahami materi dan memilih langkah sah. Sasaran pengguna: melihat status ekstraksi, konten, topik, dan memulai aksi domain yang diizinkan.

Current state summary: banyak aksi setara, ekstraksi minta muat ulang, topik padat, "Generate Questions", "Focus area".

Target experience: PageHeader judul materi plus MaterialStatus. Primer hanya satu per keadaan. Jika ekstraksi belum selesai, tidak ada primer domain. Muat ulang sekunder. Label ekstraksi `completed` adalah Selesai, bukan Siap. Selesai tidak berarti `$canGenerate`. Primer Buat soal ke GEN-02 hanya bila `$canGenerate`. Jika tidak, primer tidak mengarah ke generasi. Sekunder: Edit, Profil materi, Kisi-kisi. Destruktif: Arsipkan, terpisah. Pulihkan hanya pada arsip.

Primary action by state:
- Ekstraksi belum selesai (`pending`/`processing`): tidak ada primer domain. Muat ulang sekunder.
- Ekstraksi gagal (`failed`): tidak ada primer domain. Muat ulang sekunder.
- Ekstraksi `completed` dan `$canGenerate` true: Buat soal ke GEN-02 (primer, isi merek). Label ekstraksi tetap Selesai.
- Ekstraksi `completed` dan `$canGenerate` false: tidak ada primer domain. Label ekstraksi tetap Selesai.

Secondary actions:
- Edit (`materials.edit`).
- Profil materi (`materials.profile.show`).
- Kisi-kisi (`materials.blueprints.index`).
- Muat ulang halaman (sekunder, bukan polling).

Destructive actions:
- Arsipkan (destruktif, terpisah dari aksi lain).
- Pulihkan (hanya pada arsip).

Information hierarchy:
1. Judul materi (h1) + MaterialStatus.
2. Status ekstraksi (ProcessingState atau badge).
3. Impor terbaru (bila ada data impor).
4. Konten materi (teks hasil ekstraksi).
5. Pembuatan soal terbaru (tabel ringkasan atau gulir terkandung).
6. Topik (panel, satu kolom di bawah `md`).

Section structure:
- Header: PageHeader (judul + MaterialStatus + aksi).
- Bagian status: ProcessingState atau badge.
- Bagian impor: ringkasan impor terbaru (opsional).
- Bagian konten: teks materi.
- Bagian generasi: tabel ringkasan.
- Bagian topik: panel topik.

Components: PageHeader, MaterialStatus, StatusBadge, ProcessingState, Panel, Table atau ringkasan, Button.

View modes: pending, processing, failed, completed (`$canGenerate` true/false), arsip.

Async contract: MANUAL REFRESH. Jangan polling. Tidak ada rute status JSON untuk ekstraksi.

Companion routes: PATCH `materials.update`; POST `materials.archive`; POST `materials.restore`; POST `materials.topics.store`; PATCH `materials.topics.update`; DELETE `materials.topics.destroy`.

Empty state: konten belum ada teks — kalimat "Belum ada teks yang diekstraksi."

Loading / processing state: ProcessingState neutral (`pending`) atau processing (`processing`) plus tombol Muat ulang (sekunder). Tidak ada polling baru.

Error state: ProcessingState danger (`failed`). Tanpa tombol unggah ulang khayalan. Pesan galat dari server bila ada.

Success / terminal state: badge ekstraksi `completed` berlabel Selesai. Selesai bukan izin generasi. Buat soal hanya bila `$canGenerate`.

Desktop behavior: bagian tumpuk vertikal. Topik di panel. Tabel generasi gulir terkandung atau ringkasan. Aksi di header.

Tablet behavior: sama seperti desktop, topik satu kolom di bawah `md`.

Mobile behavior (360/390): satu kolom. Topik satu kolom. Aksi tidak lima tombol sewarna — primer satu, sekunder tumpuk. Tabel generasi ringkasan baris.

UX writing direction: "Buat soal" bukan "Generate Questions". "Area fokus" atau dihilangkan bila bukan istilah pengguna. Label Indonesia untuk status ekstraksi.

Accessibility requirements: satu `h1`. MaterialStatus punya teks. ProcessingState punya kalimat penjelasan. Fokus terlihat. Aksi bermakna.

Domain behavior that must remain unchanged: `$canGenerate` tetap ditentukan controller. Jangan menampilkan primer generasi bila false. Ekstraksi manual refresh saja — tidak ada endpoint status JSON. Topik tetap dari controller.

Acceptance criteria:
- Pada 390 px: aksi tidak lima tombol sewarna; primer satu atau nol; tabel tidak mendorong halaman.
- `pending` tidak mem-poll.
- `failed` tidak menampilkan tombol unggah ulang.
- `$canGenerate` false tidak menampilkan primer generasi.

Visual QA evidence required: desktop 1440 (pending, failed, siap canGenerate), mobile 390 (pending, failed, siap canGenerate).

## MAT-05

ID MAT-05. Area materi. Rute `materials.edit`, GET `/materials/{material}/edit`. Audiens aplikasi (`auth` + `account.active` + `profile.complete`). Layout `resources/views/layouts/app.blade.php`. Tujuan: simpan judul, dan konten hanya untuk teks lama. Sasaran pengguna: memperbarui judul atau konten teks materi.

Current state summary: form edit judul, textarea konten hanya untuk materi teks lama.

Target experience: `--container-form`. Primer Simpan perubahan. Tersier kembali ke detail. Textarea konten hanya muncul bila `$isText` true.

Primary action by state:
- Selalu: Simpan perubahan (primer, isi merek).

Secondary actions: tidak ada.

Tertiary actions:
- Kembali ke detail (tersier, tertiary).

Information hierarchy:
1. Judul halaman (h1): Edit materi.
2. Field judul (wajib).
3. Field konten (Textarea, hanya bila `$isText`).
4. Primer: Simpan perubahan.

Section structure:
- Header: PageHeader.
- Form: TextInput + Textarea (opsional) + Button.
- Footer: tidak ada.

Components: PageHeader, TextInput, Textarea bila `$isText`, Button.

View modes: teks lama (judul + konten) versus unggahan (judul saja).

Async contract: NONE.

Companion routes: PATCH `materials.update`.

Empty state: tidak berlaku.

Loading / processing state: tidak berlaku.

Error state: galat validasi di dekat field (server-side).

Success / terminal state: redirect ke detail materi oleh server (`materials.show`).

Desktop behavior: satu kolom `--container-form`, field lebar penuh, primer di bawah.

Tablet behavior: sama seperti desktop.

Mobile behavior (360/390): satu kolom, primer terlihat tanpa gulir horizontal.

UX writing direction: "Edit materi", "Simpan perubahan". Label field Indonesia.

Accessibility requirements: satu `h1`. Label terikat ke input. Fokus terlihat. Galat `aria-describedby`.

Domain behavior that must remain unchanged: materi unggahan tidak mendapat editor konten hanya karena UI ingin field itu. Textarea hanya muncul bila `$isText` true dari controller.

Acceptance criteria:
- Berkas unggahan tidak menampilkan textarea konten.
- Pada 390 px: field dan primer terlihat tanpa gulir horizontal.
- Galat validasi tampil di dekat field.

Visual QA evidence required: mobile 390 (teks lama dan unggahan).

## PROF-01

ID PROF-01. Area profil materi. Rute `materials.profile.show`, GET `/materials/{material}/profile`. Audiens aplikasi (`auth` + `account.active` + `profile.complete`). Layout `resources/views/layouts/app.blade.php`. Tujuan: mulai atau membaca analisis materi. Sasaran pengguna: memahami apakah profil materi siap, gagal, atau basi.

Current state summary: belum ada spesifikasi UI lengkap di codebase lama, status sering tidak ditangani sempurna.

Target experience: PageHeader plus ProcessingState. Primer mengikuti bendera server. Mulai analisis hanya bila `$canStart`. Jalankan analisis baru hanya bila `$canRegenerate`. In-flight atau tidak layak: tidak ada primer domain. Muat ulang sekunder.

Primary action by state:
- Belum ada dan `$canStart`: Mulai analisis (primer, isi merek).
- Belum ada dan bukan `$canStart`: tidak ada primer domain. Pesan kelayakan tetap.
- `ready` dan `$canRegenerate`: Jalankan analisis baru (primer, isi merek).
- `ready` dan bukan `$canRegenerate`: tidak ada primer domain.
- `failed` dan `$canRegenerate`: Jalankan analisis baru (primer, isi merek).
- `failed` dan bukan `$canRegenerate`: tidak ada primer domain.
- `stale` dan `$canRegenerate`: Jalankan analisis baru (primer, isi merek).
- `stale` dan bukan `$canRegenerate`: tidak ada primer domain.
- In-flight (`queued`, `processing`): tidak ada primer domain.

Secondary actions:
- Muat ulang (sekunder).
- Kembali ke materi (tersier).

Information hierarchy:
1. Judul halaman (h1): Profil materi.
2. Status proses (ProcessingState).
3. Pesan kelayakan (bila tidak layak).
4. Hasil analisis (bila `ready` atau `stale`).
5. Aksi primer/sekunder.

Section structure:
- Header: PageHeader.
- Status: ProcessingState.
- Hasil: Panel berisi butir analisis (bila ada).
- Footer: tidak ada.

Components: PageHeader, ProcessingState, Button, Panel.

View modes: none, queued, processing, ready, failed, stale, tidak layak.

Async contract: POLLING `materials.profile.status`. Berhenti mem-poll bila terminal atau gagal jaringan. Gagal jaringan tidak disimpulkan sebagai gagal domain.

Companion routes: POST `materials.profile.store`, POST `materials.profile.regenerate`, GET `materials.profile.status`.

Empty state: bila `ready` tapi butir kosong, tampilkan kalimat "Tidak ada butir profil yang ditemukan", bukan halaman kosong.

Loading / processing state: ProcessingState neutral (`queued`) atau processing (`processing`). Polling aktif. Progress bar hanya bila ada data langkah dari server.

Error state: ProcessingState danger (`failed`).

Success / terminal state: ProcessingState success (`ready`).

Desktop behavior: satu kolom, lebar form atau konten.

Tablet behavior: sama seperti desktop.

Mobile behavior (360/390): satu kolom, primer terlihat.

UX writing direction: `ready` bertuliskan "Hasil analisis siap", bukan "Izin kisi-kisi diberikan". `stale` peringatan "Tidak sesuai konten terbaru". Pesan kelayakan dari server.

Accessibility requirements: satu `h1`. `aria-live` pada live region status. Reduced motion pada animasi progress.

Domain behavior that must remain unchanged: `$canStart` dan `$canRegenerate` tetap otoritas server. Jangan menambah aksi di luar controller. Polling dan pesan kelayakan tidak diubah.

Acceptance criteria:
- `queued` tidak bergaya peringatan.
- Poll tetap ke rute yang ada.
- `stale` tidak ditampilkan sebagai profil terkini.
- `ready` tanpa `$canRegenerate` tidak punya primer domain.
- `failed` dan `stale` hanya punya primer bila `$canRegenerate`.

Visual QA evidence required: mobile 390 (none, queued, processing, ready, failed, stale, tidak layak).

## BP-01

ID BP-01. Area kisi-kisi. Rute `materials.blueprints.index`, GET `/materials/{material}/blueprints`. Audiens aplikasi (`auth` + `account.active` + `profile.complete`). Layout `resources/views/layouts/app.blade.php`. Tujuan: memilih manual, AI, atau DOCX untuk membuat kisi-kisi, dan melihat daftar kisi-kisi. Sasaran pengguna: memulai pembuatan kisi-kisi atau membuka yang sudah ada.

Current state summary: tiga kartu setara. Profil belum siap menghalangi.

Target experience: PageHeader "Kisi-kisi". Tidak ada satu primer merek ganda. Bila profil siap, tiga jalur yang sudah ada tetap tersedia sebagai aksi sekunder yang setara, bukan tiga tombol isi-merek. Bila profil belum siap, tampilkan penjelasan kelayakan yang sudah ada. Jangan menyempitkan gerbang itu menjadi hanya "tidak bisa dikonfirmasi". View sekarang tetap merender ketiga kontrol. Jangan mengarang keadaan nonaktif yang view tidak punya. Menampilkannya pada redesign tidak membuat pembuatan lolos penolakan server. Daftar kisi-kisi: Table atau ringkasan, StatusBadge draf atau terkonfirmasi.

Primary action by state:
- Profil siap: tidak ada primer domain (tiap jalur adalah secondary/tertiary).
- Profil belum siap: tidak ada primer domain.

Secondary actions (setara):
- Buat manual (secondary).
- Buat dengan AI (secondary).
- Unggah DOCX (secondary, FileInput).

Tertiary actions:
- Lihat daftar kisi-kisi (navigasi, bukan tombol).

Information hierarchy:
1. Judul halaman (h1): Kisi-kisi.
2. Alert kelayakan profil (bila belum siap).
3. Tiga jalur pembuatan (setara).
4. Daftar kisi-kisi (tabel/ringkasan).

Section structure:
- Header: PageHeader.
- Alert profil (opsional).
- Jalur pembuatan: tiga grup secondary/tertiary.
- Daftar: Table/ringkasan ATAU EmptyState.
- Footer: Pagination bila ada.

Components: PageHeader, Alert, Button, FileInput, Table atau ringkasan, StatusBadge, EmptyState, Pagination.

View modes: profil siap versus belum siap. Ada data versus kosong.

Async contract: STATEFUL BUT NOT POLLING. Isi AI diikuti di BP-03.

Companion routes: POST `materials.blueprints.ai`, POST `materials.blueprint-imports.store` (DOCX). Bukan `materials.blueprints.store` (manual pakai BP-02).

Empty state: EmptyState tanpa menghapus tiga jalur. Kalimat: "Belum ada kisi-kisi."

Loading / processing state: tidak berlaku (daftar tidak polling).

Error state: galat berkas di dekat FileInput (DOCX).

Success / terminal state: redirect ke BP-03 atau IMP-02 oleh server.

Desktop behavior: tiga jalur berdampingan atau tumpuk. Tabel penuh.

Tablet behavior: tiga jalur tumpuk. Tabel gulir terkandung atau ringkasan.

Mobile behavior (360/390): tiga jalur tumpuk (tidak tiga tombol isi-merek). Kartu pembuatan menumpuk. Tabel ringkasan baris.

UX writing direction: "Kisi-kisi". "Buat manual", "Buat dengan AI", "Unggah DOCX". Status: Draf, Terkonfirmasi.

Accessibility requirements: satu `h1`. Label terikat. FileInput label terlihat. StatusBadge punya teks. Fokus terlihat.

Domain behavior that must remain unchanged: gerbang profil siap tetap untuk pembuatan manual, AI, dan DOCX, bukan hanya untuk konfirmasi. Kunci Pro mode lanjutan tetap. Tiga jalur tetap terpisah. Tidak memilih satu-satunya produk.

Acceptance criteria:
- Pada 390 px: tidak ada tiga tombol isi-merek; jalur tumpuk; tabel ringkasan.
- Profil belum siap: penjelasan kelayakan yang sudah ada tetap tampil. Redesign tidak menjadikan ketiga jalur berhasil hanya karena kontrolnya terlihat, dan tidak mengarang keadaan nonaktif yang view sekarang tidak punya.
- Profil siap: ketiga jalur yang sudah ada tetap tersedia.
- Kosong: EmptyState + tiga jalur tetap ada.

Visual QA evidence required: desktop 1440 (profil siap & belum siap), mobile 390 (profil siap & belum siap).

## BP-02

ID BP-02. Area kisi-kisi. Rute `materials.blueprints.create`, GET `/materials/{material}/blueprints/create`. Audiens aplikasi (`auth` + `account.active` + `profile.complete`). Layout `resources/views/layouts/app.blade.php`. Tujuan: menulis draf kisi-kisi manual. Sasaran pengguna: menambahkan baris kisi-kisi dan menyimpan draf.

Current state summary: form manual dengan radio mode, field tujuan/topik/indikator/level/kesulitan/bentuk/jumlah/sumber.

Target experience: `--container-form` atau lebar penuh. Primer Simpan draf. Tersier kembali. Radio mode sederhana/lanjutan (Pro mengunci lanjutan). BlueprintRow untuk tiap baris. BlueprintSummary untuk total soal dan perkiraan kredit.

Primary action by state:
- Selalu: Simpan draf (primer, isi merek).

Secondary actions: tidak ada.

Tertiary actions:
- Kembali (tersier, tertiary).

Information hierarchy:
1. Judul halaman (h1): Buat kisi-kisi manual.
2. Radio mode (sederhana/lanjutan).
3. Field tujuan, topik, indikator (wajib).
4. BlueprintRow: level, kesulitan, bentuk, jumlah, sumber.
5. BlueprintSummary: total soal, perkiraan kredit.
6. Primer: Simpan draf.

Section structure:
- Header: PageHeader.
- Mode: Radio.
- Field dasar: TextInput, Select.
- Baris: BlueprintRow (bisa tambah/hapus).
- Ringkasan: BlueprintSummary.
- Footer: Button primer + tersier.

Components: PageHeader, Radio, TextInput, Select, BlueprintRow, BlueprintSummary, Button.

View modes: sederhana versus lanjutan (Pro).

Async contract: NONE.

Companion routes: POST `materials.blueprints.store`.

Empty state: satu baris awal yang form sudah siapkan, bukan empty state halaman.

Loading / processing state: tidak berlaku.

Error state: galat validasi di dekat field (server-side).

Success / terminal state: redirect ke BP-03 oleh server.

Desktop behavior: ringkasan tetap terlihat (sticky atau di samping). Baris tumpuk vertikal.

Tablet behavior: ringkasan di atas atau di bawah. Baris satu kolom.

Mobile behavior (360/390): tiap baris satu kolom. Tambah dan hapus baris di bawah field, area sentuh cukup. Tidak ada dua select sempit berdampingan. Simpan tetap ada di akhir.

UX writing direction: "Simpan draf". Label BlueprintRow terikat. Bahasa Indonesia.

Accessibility requirements: label BlueprintRow terikat ke kontrol. Radio mode terikat. Fokus terlihat. BlueprintSummary bukan live region.

Domain behavior that must remain unchanged: batas baris, jenis, dan kredit tampilan tetap dari server. Jangan menaikkan batas di UI. Pro mengunci mode lanjutan.

Acceptance criteria:
- Pada 390 px: tidak ada dua select sempit berdampingan. Simpan terlihat di akhir.
- Mode lanjutan terkunci tanpa Pro.
- BlueprintSummary menampilkan angka dari server, tidak dihitung ulang di UI.

Visual QA evidence required: mobile 390 (beberapa baris, mode sederhana & lanjutan Pro/non-Pro).

## BP-03

ID BP-03. Area kisi-kisi. Rute `materials.blueprints.show`, GET `/materials/{material}/blueprints/{blueprint}`. Audiens aplikasi (`auth` + `account.active` + `profile.complete`). Layout `resources/views/layouts/app.blade.php`. Tujuan: mengonfirmasi draf atau memulai pembuatan soal dari kisi-kisi terkonfirmasi. Sasaran pengguna: memvalidasi draf atau memulai generasi.

Current state summary: halaman padat dengan banyak aksi setara, status AI tidak selalu jelas.

Target experience: PageHeader judul kisi-kisi + StatusBadge. Primer per mode. Draf yang `$canEditDraft`: Konfirmasi kisi-kisi (primer). Simpan draf (sekunder). AI in-flight: tidak ada primer. AI gagal: Coba lagi hanya bila tombol ada (pemulihan, bukan pengganti konfirmasi). Terkonfirmasi: Buat soal ke GEN-04 (primer, bila mutasi lanjutan diizinkan). Unduh/salin (sekunder). Tanpa Pro pada lanjutan: tidak ada primer ubah/buat.

Primary action by state:
- Draf & `$canEditDraft`: Konfirmasi kisi-kisi (primer, isi merek). Simpan draf (sekunder).
- AI `queued`/`processing`: tidak ada primer domain.
- AI `failed` & tombol retry dirender: Coba lagi (primer, isi merek, pemulihan).
- Terkonfirmasi & mutasi lanjutan diizinkan: Buat soal ke GEN-04 (primer, isi merek).
- Terkonfirmasi & tanpa Pro pada lanjutan: tidak ada primer domain.

Secondary actions:
- Unduh (sekunder, secondary).
- Salin (sekunder, secondary).
- Simpan draf (sekunder, pada draf).

Information hierarchy:
1. Judul kisi-kisi (h1) + StatusBadge.
2. Alert Pro (bila lanjutan terkunci).
3. Primer per mode.
4. Tabel tujuh kolom (terkonfirmasi) atau form draf (draf).
5. Aksi sekunder.

Section structure:
- Header: PageHeader + StatusBadge.
- Alert Pro (opsional).
- Konten: Tabel (terkonfirmasi) atau Form (draf).
- Aksi: Button grup.
- Footer: tidak ada.

Components: PageHeader, StatusBadge, Alert, Button, Table atau ringkasan, BlueprintRow (draf), BlueprintSummary (draf), ProcessingState (AI in-flight).

View modes: draf, AI queued, AI processing, AI failed, AI succeeded, terkonfirmasi, lanjutan tanpa Pro.

Async contract: POLLING `materials.blueprints.status` hanya in-flight (`queued`/`processing`). Gagal jaringan bukan gagal domain.

Companion routes: PATCH `materials.blueprints.update`, POST `materials.blueprints.confirm`, POST `materials.blueprints.clone`, POST `materials.blueprints.retry-ai`, GET `materials.blueprints.status`, GET `materials.blueprints.download`.

Empty state: tidak berlaku (kisi-kisi selalu ada).

Loading / processing state: ProcessingState neutral/processing untuk AI in-flight. Polling aktif.

Error state: AI failed — Coba lagi hanya bila server merender tombol. Alert Pro tetap penjelasan kelayakan, bukan status `failed`.

Success / terminal state: Terkonfirmasi — tabel tujuh kolom. `succeeded` (AI selesai) — menunggu konfirmasi.

Desktop behavior: tabel tujuh kolom, gulir terkandung atau ringkasan di bawah `md`. Draf: form seperti BP-02.

Tablet behavior: tabel gulir terkandung atau ringkasan.

Mobile behavior (360/390): draf satu kolom. Terkonfirmasi ringkasan baris. Dua tombol isi-merek tidak berdampingan. Poll tidak ke rute lain.

UX writing direction: "Konfirmasi kisi-kisi", "Buat soal", "Unduh", "Salin". "Coba lagi" hanya untuk pemulihan AI. Alert Pro jelas.

Accessibility requirements: satu `h1`. StatusBadge punya teks. Live region untuk polling AI. Fokus terlihat. Tabel header terikat.

Domain behavior that must remain unchanged: `$canEditDraft`, daur hidup kisi-kisi, dan Pro tetap. Jangan konfirmasi saat in-flight. Jangan menggabungkan status AI dengan kelayakan Pro.

Acceptance criteria:
- `queued`/`processing` tidak menampilkan konfirmasi.
- `failed` AI: Coba lagi hanya bila server merender tombol.
- Pro nonaktif pada lanjutan: tidak ada primer ubah/buat.
- Polling hanya ke `materials.blueprints.status`.

Visual QA evidence required: mobile 390 (draf, in-flight, gagal, terkonfirmasi, Pro nonaktif).

## IMP-01

ID IMP-01. Area impor. Rute `materials.blueprint-imports.index`, GET `/materials/{material}/blueprint-imports`. Audiens aplikasi (`auth` + `account.active` + `profile.complete`). Layout `resources/views/layouts/app.blade.php`. Tujuan: riwayat impor DOCX. Sasaran pengguna: melihat daftar impor dan membuka tinjauan per impor.

Current state summary: daftar impor dengan status pencocokan.

Target experience: PageHeader "Riwayat impor". Primer tidak ada. Tersier: tinjau per baris (tautan ke IMP-02) dan kembali ke BP-01.

Primary action by state:
- Selalu: tidak ada aksi primer.

Secondary actions: tidak ada.

Tertiary actions:
- Tinjau (tautan per baris ke IMP-02).
- Kembali (tersier ke BP-01).

Information hierarchy:
1. Judul halaman (h1): Riwayat impor.
2. Daftar: tabel/ringkasan (nama berkas, status tiga pipa, tanggal).

Section structure:
- Header: PageHeader.
- Isi: Table/ringkasan ATAU EmptyState.
- Footer: Pagination bila ada.

Components: PageHeader, Table atau ringkasan, GroundingStatus, Pagination, EmptyState.

View modes: ada data versus kosong.

Async contract: STATEFUL BUT NOT POLLING.

Companion routes: tidak ada mutasi di halaman ini.

Empty state: EmptyState tanpa unggah kedua yang mengarang alur. Unggah tetap dari BP-01. Kalimat: "Belum ada impor."

Loading / processing state: tidak berlaku.

Error state: tidak berlaku.

Success / terminal state: flash success lewat layout dari aksi di IMP-02.

Desktop behavior: tabel penuh (nama berkas, status tiga pipa, tanggal, tautan tinjau).

Tablet behavior: tabel gulir terkandung atau ringkasan.

Mobile behavior (360/390): ringkasan baris (nama berkas, tiga label status, tautan Tinjau). Tidak meluapkan halaman.

UX writing direction: "Riwayat impor". Tiga status tidak digabung menjadi satu kata. Label Indonesia.

Accessibility requirements: satu `h1`. Label tabel/header terikat. Fokus terlihat. Tautan Tinjau bermakna.

Domain behavior that must remain unchanged: hanya daftar. Tidak memulai pencocokan dari sini. Unggah tetap dari BP-01.

Acceptance criteria:
- Pada 390 px: tidak meluapkan halaman; tiga status terbaca terpisah.
- Kosong: EmptyState tanpa tombol unggah.

Visual QA evidence required: desktop 1440 (kosong & berisi), mobile 390 (kosong & berisi).

## IMP-02

ID IMP-02. Area impor. Rute `materials.blueprint-imports.show`, GET `/materials/{material}/blueprint-imports/{blueprintImport}`. Audiens aplikasi (`auth` + `account.active` + `profile.complete`). Layout `resources/views/layouts/app.blade.php`. Tujuan: mengikuti tiga pipa (ekstraksi, interpretasi, pencocokan) dan, hanya bila syarat terpenuhi, membuat draf kisi-kisi. Sasaran pengguna: memahami status impor dan menyelesaikan konversi bila layak.

Current state summary: permukaan padat, kartu bersarang, flash ganda.

Target experience: PageHeader nama berkas. ImportProgress tiga pipa terpisah (bukan satu badge). Kandidat interpretasi ditampilkan dengan pagination (`$candidates->hasPages()`, `$candidates->previousPageUrl()`, `$candidates->nextPageUrl()` — bukti di `resources/views/materials/blueprint-imports/show.blade.php` baris 95–167). Formulir konversi hanya muncul bila `grounding_status == ready` DAN `$convertibleIndexes !== []` DAN belum ada `created_blueprint_id`.

Primary action by state:
- In-flight (ekstraksi/interpretasi/pencocokan berjalan): tidak ada primer domain.
- Ekstraksi gagal: tidak ada primer domain.
- Interpretasi gagal & `$canRetry`: Coba lagi (primer, isi merek).
- `review_ready` & pencocokan belum dimulai: Cocokkan dengan materi (primer, isi merek).
- Pencocokan gagal & rute retry-grounding dirender: Coba lagi pencocokan (primer, isi merek).
- Pencocokan selesai & `$convertibleIndexes !== []` & belum ada draf: Simpan sebagai draf setelah pilihan (primer, isi merek). Ini satu-satunya keadaan "siap membuat draf".
- Pencocokan selesai & `$convertibleIndexes` kosong: tidak ada primer. Kalimat: pencocokan selesai, tidak ada kandidat yang dapat dipakai.
- `created_blueprint_id` terisi: Buka draf (primer, isi merek). Bukan buat lagi.

Secondary actions:
- Kembali ke riwayat impor (sekunder/tersier).

Information hierarchy:
1. Judul halaman (h1): nama berkas impor.
2. ImportProgress: tiga pipa terpisah (ekstraksi, interpretasi, pencocokan).
3. Kandidat interpretasi (dengan pagination).
4. Formulir konversi (hanya bila syarat terpenuhi).
5. Aksi primer per keadaan.

Section structure:
- Header: PageHeader (nama berkas).
- Status: ImportProgress (tiga pipa).
- Kandidat: ImportCandidateCard + Pagination.
- Konversi: formulir pilihan + primer (hanya bila syarat).
- Footer: tidak ada.

Components: PageHeader, ImportProgress, ImportCandidateCard, Pagination, ProcessingState, Button, Alert.

View modes: in-flight, ekstraksi gagal, interpretasi gagal, review_ready, pencocokan berjalan, pencocokan gagal, pencocokan selesai (ada kandidat / kosong), draf sudah ada.

Async contract: POLLING `materials.blueprint-imports.status`. Gagal jaringan bukan gagal domain. Berhenti poll setelah terminal.

Companion routes: GET `materials.blueprint-imports.status`, POST `materials.blueprint-imports.retry`, POST `materials.blueprint-imports.ground`, POST `materials.blueprint-imports.retry-grounding`, POST `materials.blueprint-imports.convert`.

Empty state: pencocokan selesai dengan indeks kosong — kalimat "Pencocokan selesai. Tidak ada kandidat yang dapat dipakai." Bukan halaman kosong.

Loading / processing state: ProcessingState per pipa yang sedang berjalan. Polling aktif.

Error state: ProcessingState danger per pipa yang gagal. Interpretasi gagal: Coba lagi (bila `$canRetry`). Pencocokan gagal: Coba lagi pencocokan (bila rute dirender). Jaringan gagal bukan gagal domain.

Success / terminal state: draf sudah ada (`created_blueprint_id` terisi) — Buka draf. Konversi berhasil — redirect atau flash success.

Desktop behavior: ImportProgress tiga pipa berdampingan atau tumpuk. Kandidat dengan pagination. Formulir konversi tidak di dalam kartu kedua.

Tablet behavior: tiga pipa tumpuk. Kandidat satu kolom.

Mobile behavior (360/390): satu kolom. Pilihan di atas field. Primer di akhir. Pagination membungkus. Kandidat tumpuk.

UX writing direction: jangan "Siap membuat draf" di luar syarat. Tiga pipa label terpisah. Label Indonesia.

Accessibility requirements: satu `h1`. Label terikat. Live region untuk polling status. Fokus tidak meloncat sebelum reload. Area sentuh cukup.

Domain behavior that must remain unchanged: `grounding_status == ready` wajib sebelum konversi. `$convertibleIndexes` wajib tidak kosong. `created_blueprint_id` kosong wajib sebelum buat baru. Validasi server tetap. Tiga pipa terpisah — jangan digabung. Candidate pagination tetap (`$candidates->hasPages()`).

Acceptance criteria:
- `review_ready` tidak menampilkan formulir konversi.
- `ready` dengan indeks kosong tidak menampilkan form konversi dan tidak mengklaim siap draf.
- Draf yang sudah ada hanya membuka draf itu, bukan buat lagi.
- Kandidat dipaginasi bila `$candidates->hasPages()`.
- Tiga pipa terpisah di ImportProgress.

Visual QA evidence required: desktop 1440 (tiap pipa, indeks kosong, draf sudah ada), mobile 390 (tiap pipa, indeks kosong, draf sudah ada).

## GEN-01

ID GEN-01. Area pembuatan dari materi. Rute `generations.index`, GET `/generations`. Audiens aplikasi (`auth` + `account.active` + `profile.complete`). Layout `resources/views/layouts/app.blade.php`. Tujuan: riwayat produk generasi lama (`GenerationController`). Produk ini tetap hidup. Sasaran pengguna: melihat riwayat dan membuka hasil generasi lama.

Current state summary: daftar generasi dengan enum mentah, eyebrow Inggris.

Target experience: PageHeader "Pembuatan soal". Primer tidak ada bila ada data. Kosong: "Pilih materi" ke MAT-01. Bukan ke GEN-04 (produk berbeda). Tabel atau ringkasan baris. StatusBadge label Indonesia.

Primary action by state:
- Ada data: tidak ada aksi primer.
- Kosong: Pilih materi ke MAT-01 (primer, isi merek). Bukan ke GEN-04.

Secondary actions: tidak ada.

Information hierarchy:
1. Judul halaman (h1): Pembuatan soal.
2. Daftar: tabel/ringkasan (status, judul materi, jumlah soal, tanggal).

Section structure:
- Header: PageHeader.
- Isi: Table/ringkasan ATAU EmptyState.
- Footer: Pagination bila ada.

Components: PageHeader, Table atau ringkasan, StatusBadge, EmptyState, Pagination.

View modes: ada data versus kosong.

Async contract: STATEFUL BUT NOT POLLING.

Companion routes: tidak ada mutasi di halaman ini.

Empty state: EmptyState + primer "Pilih materi" ke MAT-01. Kalimat: "Belum ada pembuatan soal." Bukan mengarahkan ke generation run.

Loading / processing state: tidak berlaku.

Error state: tidak berlaku.

Success / terminal state: flash success lewat layout.

Desktop behavior: tabel penuh (status, judul materi, jumlah, tanggal).

Tablet behavior: tabel gulir terkandung atau ringkasan.

Mobile behavior (360/390): ringkasan baris (status, judul materi, jumlah). Tidak meluap. Enum tersembunyi di samping label.

UX writing direction: "Pembuatan soal" bukan "Generation History". Label Indonesia. Sembunyikan enum mentah.

Accessibility requirements: satu `h1`. StatusBadge punya teks. Fokus terlihat. Tautan baris bermakna.

Domain behavior that must remain unchanged: daftar masih `GenerationController`. Jangan mengarahkan kosong ke generation run (`GenerationRunController`). Dua produk generasi tetap terpisah.

Acceptance criteria:
- Pada 390 px: tidak meluap.
- Kosong mengarahkan ke MAT-01, bukan GEN-04.
- Enum tidak tampil mentah.

Visual QA evidence required: desktop 1440 (kosong & berisi), mobile 390 (kosong & berisi).

## GEN-02

ID GEN-02. Area pembuatan dari materi. Rute `generations.create`, GET `/materials/{material}/generations/create`. Audiens aplikasi (`auth` + `account.active` + `profile.complete`). Layout `resources/views/layouts/app.blade.php`. Tujuan: mulai pembuatan soal dari materi ini (produk lama). Sasaran pengguna: memilih opsi dan memulai generasi.

Current state summary: form dengan select mode, input jumlah, indikator kredit.

Target experience: `--container-form`. Primer Buat soal. Tersier kembali. Select mode, TextInput jumlah, CreditIndicator.

Primary action by state:
- Form yang ada ditampilkan: Buat soal (primer, isi merek).
- Bila kelayakan atau kuota server menolak pembuatan: jangan menampilkan aksi primer yang aktif. Jelaskan kondisi kuota atau kelayakan yang server sudah kirim. Jangan menentukan sendiri apakah tombol disembunyikan atau dinonaktifkan. View sekarang tetap merender tombol kirim, dan penolakan kuota tampil lewat `@error('quota')`.

Secondary actions: tidak ada.

Tertiary actions:
- Kembali (tersier, tertiary).

Information hierarchy:
1. Judul halaman (h1): Buat soal dari materi.
2. Select mode (pilihan generasi).
3. TextInput jumlah soal.
4. CreditIndicator (perkiraan kredit).
5. Primer: Buat soal.

Section structure:
- Header: PageHeader.
- Form: Select + TextInput + CreditIndicator + Button.
- Footer: tidak ada.

Components: PageHeader, Select, TextInput, CreditIndicator, Button.

View modes: tunggal (form generasi lama).

Async contract: NONE.

Companion routes: POST `generations.store`.

Empty state: tidak berlaku.

Loading / processing state: tidak berlaku (submit standar).

Error state: galat kuota dan materi di dekat form, dari server.

Success / terminal state: redirect ke GEN-03 oleh server.

Desktop behavior: satu kolom `--container-form`, field lebar penuh.

Tablet behavior: sama seperti desktop.

Mobile behavior (360/390): satu kolom, label terlihat, primer tercapai.

UX writing direction: "Buat soal". "Jumlah soal". Label Indonesia.

Accessibility requirements: satu `h1`. Label terikat. Fokus terlihat. Galat `aria-describedby`.

Domain behavior that must remain unchanged: kuota dari server. Jangan mengirim ke `generation-runs.store`. Form tetap ada hanya untuk jalur lama (`GenerationController`).

Acceptance criteria:
- Pada 390 px: label terlihat, primer tercapai bila form yang ada memang ditampilkan.
- Kredit dan kuota tetap angka server. Penolakan kuota memakai galat server yang ada, tanpa keadaan tombol nonaktif yang diada-adakan.

Visual QA evidence required: mobile 390 untuk form yang ada. Jangan mensyaratkan keadaan "kuota habis: tombol nonaktif" yang view tidak punya.

## GEN-03

ID GEN-03. Area pembuatan dari materi. Rute `generations.show`, GET `/generations/{generation}`. Audiens aplikasi (`auth` + `account.active` + `profile.complete`). Layout `resources/views/layouts/app.blade.php`. Tujuan: mengikuti proses dan menyimpan hasil generasi lama (`GenerationController`). Sasaran pengguna: memantau status, menyimpan hasil ke bank soal.

Current state summary: halaman status generasi dengan polling, tombol simpan, dan tombol retry.

Target experience: PageHeader judul generasi + GenerationStatus. Primer per keadaan. In-flight: tidak ada primer. Gagal dan retry diizinkan: Coba lagi (primer). Selesai belum disimpan: Simpan ke bank soal lewat `question-sets.import` (primer). Sudah disimpan: Buka bank soal ke set itu (primer). `cancelled`: tidak ada primer, label Dibatalkan, bukan bahaya.

Primary action by state:
- In-flight (`queued`/`processing`): tidak ada primer domain.
- Gagal & retry diizinkan: Coba lagi (primer, isi merek).
- Selesai & belum disimpan: Simpan ke bank soal (primer, isi merek).
- Sudah disimpan: Buka bank soal (primer, isi merek).
- `cancelled`: tidak ada primer. Label Dibatalkan, bukan bahaya.

Secondary actions: tidak ada.

Information hierarchy:
1. Judul generasi (h1) + GenerationStatus.
2. ProcessingState (in-flight).
3. CreditIndicator (kredit terpakai).
4. GenerationResult (hasil soal, bila selesai).
5. Aksi primer per keadaan.

Section structure:
- Header: PageHeader + GenerationStatus.
- Status: ProcessingState (in-flight).
- Kredit: CreditIndicator.
- Hasil: GenerationResult (bila selesai).
- Aksi: Button primer.
- Footer: tidak ada.

Components: PageHeader, GenerationStatus, GenerationResult, ProcessingState, CreditIndicator, Button.

View modes: queued, processing, failed, completed (belum simpan), completed (sudah simpan), cancelled.

Async contract: POLLING `generations.status` tiap 5000 ms yang sudah ada. Live region mengumumkan label, bukan enum. Gagal jaringan bukan gagal domain.

Companion routes: GET `generations.status`, POST `generations.retry`, POST `question-sets.import`.

Empty state: tidak berlaku (generasi selalu ada).

Loading / processing state: ProcessingState in-flight. Permukaan, bukan hanya badge. Tidak ada persen.

Error state: ProcessingState danger (`failed`). Coba lagi hanya bila retry diizinkan.

Success / terminal state: GenerationResult menampilkan soal. Simpan ke bank soal. Sudah disimpan: tautan ke set.

Desktop behavior: satu kolom, hasil tumpuk vertikal.

Tablet behavior: sama seperti desktop.

Mobile behavior (360/390): satu kolom. Hasil tumpuk. Primer tercapai.

UX writing direction: "Dibatalkan" bukan "Cancelled". Label Indonesia. Live region label manusia.

Accessibility requirements: satu `h1`. Live region untuk polling. Reduced motion. Fokus terlihat.

Domain behavior that must remain unchanged: rute impor lama (`question-sets.import`). Jangan `question-sets.import-run`. Poll tidak pindah produk. `GenerationController` tetap.

Acceptance criteria:
- Selesai belum disimpan tidak membuka set khayalan.
- Poll tetap ke `generations.status`, tidak pindah produk.
- `cancelled` label Dibatalkan, bukan bahaya.

Visual QA evidence required: mobile 390 (queued, gagal, selesai belum simpan, selesai sudah simpan).

## GEN-04

ID GEN-04. Area pembuatan dari kisi-kisi. Rute `generation-runs.create`, GET `/materials/{material}/blueprints/{blueprint}/generation-runs/create`. Audiens aplikasi, pemilik kisi-kisi. Layout `resources/views/layouts/app.blade.php`. Tujuan: mulai generation run dari kisi-kisi yang sudah dikonfirmasi. Sasaran pengguna: memulai pembuatan soal dari kisi-kisi ini, atau memahami bahwa Pro menghalangi mulai.

Current state summary: form `GenerationRunController@create` dengan kuota, bahasa keluaran, dan centang acak hanya pada mode lanjutan. Primer "Mulai generasi" hanya di dalam cabang `$canStart`. Jika tidak, alert Pro dan tidak ada form.

Target experience: PageHeader yang menyebut kisi-kisi, bukan "generation run". CreditIndicator menampilkan angka yang server kirim. Primer Buat soal hanya bila `$canStart`. Jika tidak, tidak ada primer. Alert menjelaskan kunci Pro.

Primary action by state:
- `$canStart` true: Buat soal (primer, isi merek). Mengirim POST `generation-runs.store`.
- `$canStart` false: tidak ada primer.

Secondary actions: tautan kembali ke BP-03. Bukan tombol isi-merek.

Information hierarchy:
1. Judul halaman (h1): buat soal dari kisi-kisi ini.
2. Ringkasan jumlah dan mode yang view sudah kirim.
3. CreditIndicator.
4. Jika tidak boleh mulai: Alert Pro.
5. Jika boleh: bahasa keluaran, lalu centang acak hanya bila mode lanjutan, lalu primer.

Section structure:
- Header: PageHeader dan tautan kembali.
- Kuota: CreditIndicator.
- Blok tidak layak: Alert, tanpa form mulai.
- Form: Select, Checkbox bila lanjutan, Button.
- Footer: tidak ada.

Components: PageHeader, Select, Checkbox, CreditIndicator, Button, Alert.

View modes: `$canStart` true versus false. Mode lanjutan menampilkan centang acak. Mode sederhana tidak mengada-adakan centang itu.

Async contract: NONE.

Companion routes: POST `generation-runs.store`. Field idempotensi yang sudah ada tetap tersembunyi dan tidak menjadi kontrol baru.

Empty state: N/A. Halaman ini hanya dibuka dari kisi-kisi terkonfirmasi.

Loading / processing state: N/A. Tidak ada polling di halaman mulai.

Error state: galat validasi server di dekat field yang bersangkutan. Kunci Pro adalah Alert kelayakan, bukan status proses gagal.

Success / terminal state: server mengarahkan ke GEN-05. Bukan tanggung jawab halaman ini menampilkan hasil.

Desktop behavior: satu kolom `--container-form` di dalam lebar aplikasi. Primer di bawah field.

Tablet behavior: sama seperti desktop.

Mobile behavior (360/390): satu kolom. Label terlihat. Checkbox lanjutan berlabel dan area sentuhnya cukup. Primer tercapai. Tidak ada gulir horizontal halaman.

UX writing direction: "Buat soal", "dari kisi-kisi". Bukan "Generate" atau "generation run".

Accessibility requirements: satu `h1`. Label terikat pada select dan checkbox. Alert Pro adalah teks, bukan hanya warna. Fokus terlihat.

Domain behavior that must remain unchanged: tetap `GenerationRunController`. Primer hanya bila `$canStart`. Gerbang Pro tetap. POST tetap `generation-runs.store`. Jangan mengarah ke `generations.store`. Idempotensi dan kredit tampilan tetap dari server. Jangan menghitung ulang kredit.

Acceptance criteria:
- `$canStart` false tidak merender primer dan tidak merender POST mulai.
- `$canStart` true mengirim `generation-runs.store`, bukan rute generasi lama.
- Pada 390 px checkbox lanjutan berlabel dan tidak ada gulir horizontal halaman.

Visual QA evidence required: desktop 1440 dan mobile 390, untuk `$canStart` true dan false.

## GEN-05

ID GEN-05. Area pembuatan dari kisi-kisi. Rute `generation-runs.show`, GET `/generation-runs/{generationRun}`. Audiens aplikasi, pemilik run. Layout `resources/views/layouts/app.blade.php`. Tujuan: mengikuti generation run dan menyimpan hasilnya lewat rute run. Sasaran pengguna: menunggu selesai, mencoba lagi bila diizinkan, atau menyimpan ke bank soal.

Current state summary: status run mentah, tabel anak dengan status mentah, polling `generation-runs.status` selama belum terminal, simpan lewat `question-sets.import-run`, dan coba lagi hanya bila `$canRetry`.

Target experience: GenerationStatus yang ditandai sebagai run, bukan generasi lama. ProcessingState saat belum terminal. Anak diterjemahkan untuk tampilan. Nilai tersimpan tidak diganti. Primer mengikuti keadaan di bawah.

Primary action by state:
- `queued` atau `processing`: tidak ada primer domain.
- `failed` dan `$canRetry`: Coba lagi (primer). POST `generation-runs.retry`.
- `failed` dan bukan `$canRetry`: tidak ada primer. Kalimat Pro yang sudah ada tetap.
- `completed` dan belum ada question set: Simpan ke bank soal (primer). POST `question-sets.import-run`.
- `completed` dan question set sudah ada: Buka set itu (primer).

Secondary actions: tautan kembali ke BP-03.

Information hierarchy:
1. Judul kisi-kisi (h1) dan status run.
2. Jumlah soal dan kredit yang server kirim.
3. Langkah anak.
4. Galat atau hasil, sesuai keadaan.
5. Satu primer, bila ada.

Section structure:
- Header: PageHeader, GenerationStatus, tautan kembali.
- Langkah: Table terkandung.
- Gagal: Alert atau ProcessingState danger, lalu primer hanya bila `$canRetry`.
- Selesai: GenerationResult dan satu primer simpan atau buka.
- Footer: tidak ada.

Components: PageHeader, GenerationStatus, ProcessingState, Table, GenerationResult, Button, Alert.

View modes: `queued`, `processing`, `failed` dengan retry, `failed` tanpa retry, `completed` belum simpan, `completed` sudah simpan. Run tidak punya status `cancelled`.

Async contract: POLLING `generation-runs.status` dengan `$pollIntervalMs` yang sudah ada, hanya selama belum terminal. Muat ulang bila payload terminal. Gagal jaringan bukan status domain gagal.

Companion routes: GET `generation-runs.status`, POST `generation-runs.retry`, POST `question-sets.import-run`.

Empty state: N/A. Run yang dibuka sudah ada.

Loading / processing state: ProcessingState untuk `queued` dan `processing`. Tidak ada persentase. Status anak adalah label, bukan kemajuan palsu.

Error state: `failed` menampilkan `error_message` yang ada. Coba lagi hanya bila `$canRetry`.

Success / terminal state: `completed` menampilkan soal yang payload sudah beri, lalu simpan atau buka set yang sudah ada.

Desktop behavior: hasil satu kolom. Tabel langkah boleh tabel biasa.

Tablet behavior: tabel langkah boleh gulir horizontal yang terkandung.

Mobile behavior (360/390): hasil satu kolom. Tabel langkah gulir di dalam wilayahnya sendiri, bukan gulir halaman. Primer tercapai.

UX writing direction: label run memakai Menunggu diproses, Sedang diproses, Selesai, Gagal. Status anak memakai kosakata yang sama. Bukan "Question Bank" dan bukan "generation run".

Accessibility requirements: satu `h1`. Status punya teks. Gagal jaringan tidak diumumkan sebagai proses gagal. Fokus tidak dipindah sebelum muat ulang. Tombol coba lagi dan simpan berlabel.

Domain behavior that must remain unchanged: tetap `GenerationRunController`. Jangan digabung dengan GEN-03. Status tetap `generation-runs.status`. Coba lagi tetap `generation-runs.retry` dan boleh tetap diblokir Pro. Simpan tetap `question-sets.import-run`, bukan `question-sets.import`. Nilai status tersimpan tidak diganti nama.

Acceptance criteria:
- Belum terminal tidak punya primer domain.
- Selesai yang belum disimpan hanya memakai `question-sets.import-run`.
- `$canRetry` false tidak merender coba lagi.
- Pada 390 px tabel langkah tidak mendorong gulir halaman.

Visual QA evidence required: mobile 390 untuk queued, gagal yang dapat diulang, gagal yang diblokir Pro, dan selesai. Desktop 1440 untuk selesai.

## QB-01

ID QB-01. Area bank soal. Rute `question-sets.index`, GET `/question-sets`. Audiens aplikasi. Layout `resources/views/layouts/app.blade.php`. Tujuan: daftar set soal, membuka satu set. Sasaran pengguna: menemukan dan membuka set yang sudah disimpan.

Current state summary: tabel dengan enum mentah, eyebrow Inggris, tautan kosong ke GEN-01.

Target experience: PageHeader "Bank soal". Tabel atau ringkasan baris. StatusBadge label Indonesia. Pagination bila perlu. Kosong: tidak ada primer. Satu tautan tersier ke riwayat generasi materi lama (`generations.index`). Teks pendukung: set yang dibuat dari kisi-kisi (GEN-05) muncul di sini setelah disimpan lewat halaman run. Tidak ada indeks run global; jangan mengarang halaman baru. Tidak memaksa CTA ke produk yang salah.

Primary action by state:
- Ada data: tidak ada aksi domain primer (membuka baris adalah navigasi, bukan tombol).
- Kosong: tidak ada primer.

Secondary actions: tidak ada.

Tertiary actions:
- Kosong: tautan "Riwayat pembuatan dari materi" ke `generations.index`.
- Teks pendukung: "Set dari kisi-kisi muncul setelah disimpan dari halaman hasil generasi."

Information hierarchy:
1. Judul halaman (h1): Bank soal.
2. Jika kosong: kalimat penjelasan + tautan tersier + teks pendukung.
3. Jika ada data: tabel/ringkasan dengan kolom judul, status, jumlah soal, diperbarui.

Section structure:
- Header: PageHeader.
- Isi: tabel/ringkasan ATAU EmptyState.
- Footer: Pagination bila ada.

Components: PageHeader, Table atau ringkasan baris, StatusBadge, EmptyState, Pagination.

View modes: ada data versus kosong.

Async contract: STATEFUL BUT NOT POLLING (aksi dan salinan mengikuti status tersimpan; muat ulang atau navigasi mengambil keadaan baru).

Companion routes: tidak ada mutasi di daftar.

Empty state: kalimat "Belum ada set soal." + tautan tersier "Riwayat pembuatan dari materi" ke `generations.index` + teks pendukung "Set soal yang dibuat dari kisi-kisi juga muncul di sini setelah disimpan dari halaman hasil generasi." Tidak ada tombol primer.

Loading / processing state: tidak berlaku (tidak ada polling).

Error state: tidak berlaku.

Success / terminal state: flash sukses dari aksi lain (mis. publikasi) lewat layout.

Desktop behavior: tabel penuh (judul, status, jumlah, diperbarui, aksi). Pagination di bawah.

Tablet behavior: tabel dengan gulir horizontal terkandung di wilayah tabel, atau ringkasan baris (judul, status, jumlah) bila spesifikasi memilih.

Mobile behavior (360/390): ringkasan baris (judul, status, jumlah) tanpa gulir halaman. Pagination membungkus.

UX writing direction: "Bank soal" bukan "Question Sets". Status: Draf, Dihasilkan, Ditinjau, Terbit, Diarsipkan. Hilangkan enum mentah di samping label. Teks kosong tenang, tidak memaksa aksi.

Accessibility requirements: satu `h1`. Label tabel/header terikat. StatusBadge punya teks, bukan hanya warna. Fokus terlihat pada tautan baris dan pagination. Area sentuh cukup pada mobile.

Domain behavior that must remain unchanged: daftar tetap isi yang controller kirim (`QuestionSetController@index`). Tidak menggabungkan daftar run ke daftar ini dengan kueri baru. Tidak menghapus salah satu arsitektur generasi.

Acceptance criteria:
- Pada 390 px: tidak ada gulir horizontal halaman; ringkasan baris terbaca; pagination tidak meluap.
- Kosong: tidak ada tombol primer; tautan tersier ke `generations.index` terlihat; teks pendukung tentang set dari kisi-kisi hadir.
- Enum `draft`/`generating`/`review`/`published`/`archived` tidak tampil mentah di UI.

Visual QA evidence required: desktop 1440 (kosong & berisi), mobile 390 (kosong & berisi).

## QB-02

ID QB-02. Area bank soal. Rute `question-sets.show`, GET `/question-sets/{questionSet}`. Audiens aplikasi, pemilik set. Layout `resources/views/layouts/app.blade.php`. Tujuan: membaca set, menerbitkan draf, atau mengunduh set yang sudah terbit. Sasaran pengguna: menyelesaikan satu tindakan yang sesuai status.

Current state summary: draf menampilkan Edit dan Terbitkan dengan `window.confirm`. Terbit menampilkan dua unduhan DOCX. Status lain hanya berlabel.

Target experience: PageHeader judul set plus StatusBadge. Draf punya satu primer Terbitkan. Edit sekunder. Terbit tidak punya primer. Dua unduhan sekunder. Status selain draf dan terbit tidak mendapat aksi baru.

Primary action by state:
- `draft`: Terbitkan (primer).
- `published`: tidak ada primer.
- `generating`, `review`, atau `archived`: tidak ada primer. Hanya label.

Secondary actions:
- `draft`: Edit ke QB-03.
- `published`: Unduh DOCX siswa dan Unduh DOCX guru, keduanya sekunder.
- Semua mode: kembali ke daftar, dan tautan sumber generasi hanya bila relasi itu sudah ada.

Information hierarchy:
1. Judul set (h1) dan status.
2. Jumlah soal.
3. Aksi yang sah untuk status itu.
4. Daftar soal, satu per blok.

Section structure:
- Header: PageHeader, StatusBadge, tautan kembali.
- Aksi: Button sesuai keadaan.
- Isi: satu panel per soal.
- Footer: tidak ada.

Components: PageHeader, StatusBadge, Button, Panel.

View modes: draf versus terbit. Status lain hanya tampilan.

Async contract: STATEFUL BUT NOT POLLING.

Companion routes: POST `question-sets.publish`, GET `question-sets.download-student`, GET `question-sets.download-teacher`.

Empty state: N/A. Set yang dibuka memiliki soalnya dari data yang controller kirim. Jika koleksi soal kosong, tampilkan kalimat bahwa tidak ada soal, tanpa aksi unduh atau sunting yang tidak ada di server.

Loading / processing state: N/A. Halaman tidak mem-poll.

Error state: view `question-sets/show.blade.php` sudah merender `@error('status')`, `@error('questions')`, `@error('total_question')`, dan `@error('question_type')` sebagai `.error-text` di dekat judul. Hanya mekanisme itu. Jangan menambah galat field lain.

Success / terminal state: setelah terbit, server kembali ke halaman ini pada mode terbit. Flash sukses lewat layout. Konfirmasi terbit memakai `window.confirm` dan menyebut bahwa setelah terbit soal tidak dapat diedit.

Desktop behavior: aksi di header. Soal satu kolom yang nyaman dibaca.

Tablet behavior: aksi boleh membungkus. Soal tetap satu kolom.

Mobile behavior (360/390): primer draf di atas aksi lain. Dua unduhan membungkus tanpa gulir horizontal halaman. Soal satu kolom.

UX writing direction: "Terbitkan", "Unduh". Bukan "Question Bank" atau "Publish".

Accessibility requirements: satu `h1`. Status punya teks. Konfirmasi terbit menyebut akibat. Tombol dan tautan berlabel. Fokus terlihat.

Domain behavior that must remain unchanged: draf boleh diterbitkan lewat rute yang ada. Terbit boleh diunduh lewat dua rute yang ada. Jangan mengarang aksi untuk status lain. Konten terbit tetap tidak dapat diedit bila server menolaknya. Jangan menampilkan Edit pada terbit.

Acceptance criteria:
- Draf menampilkan satu primer Terbitkan dan tidak menampilkan unduhan terbit.
- Terbit tidak menampilkan Edit atau Terbitkan.
- Pada 390 px dua unduhan tidak menyebabkan gulir horizontal halaman.

Visual QA evidence required: desktop 1440 dan mobile 390, untuk draf dan terbit.

## QB-03

ID QB-03. Area bank soal. Rute `question-sets.edit`, GET `/question-sets/{questionSet}/edit`. Audiens aplikasi, pemilik draf yang controller izinkan diedit. Layout `resources/views/layouts/app.blade.php`. Tujuan: menyimpan perubahan draf soal. Sasaran pengguna: memperbaiki teks yang ada lalu menyimpan.

Current state summary: form panjang per soal menurut bentuk yang sudah tersimpan, dengan galat di dekat field, dikirim PATCH `question-sets.update`.

Target experience: PageHeader Edit soal. Satu panel per soal. Primer Simpan di akhir. Tidak menambah perilaku bentuk soal baru.

Primary action by state:
- Form draf yang boleh diedit: Simpan (primer).

Secondary actions: kembali ke QB-02.

Information hierarchy:
1. Judul halaman (h1).
2. Judul set.
3. Tiap soal: teks, opsi atau rubrik sesuai bentuk yang sudah ada, galat.
4. Primer di akhir.

Section structure:
- Header: PageHeader dan tautan kembali.
- Identitas: TextInput judul.
- Soal: Panel per butir dengan Textarea dan input yang view sudah miliki untuk bentuk itu.
- Aksi: Button Simpan.
- Footer: tidak ada.

Components: PageHeader, TextInput, Textarea, Select atau radio hanya bila halaman sudah memakainya untuk kunci yang ada, Button, Panel.

View modes: tunggal, draf yang dapat diedit. Bentuk soal mengikuti data yang ada, bukan mode baru.

Async contract: NONE.

Companion routes: PATCH `question-sets.update`.

Empty state: N/A. Halaman edit tidak dipakai untuk membuat set kosong.

Loading / processing state: N/A.

Error state: galat judul dan galat per soal di dekat field pemicunya.

Success / terminal state: server mengarahkan kembali ke QB-02. Flash sukses lewat layout.

Desktop behavior: satu kolom `--container-form` atau lebar aplikasi bila soal panjang. Primer di akhir form.

Tablet behavior: sama seperti desktop, tetap satu kolom.

Mobile behavior (360/390): satu kolom. Label opsi terlihat. Primer di akhir dan tercapai. Area sentuh tidak disusutkan. Tidak ada dua field sempit berdampingan.

UX writing direction: "Simpan", "Teks soal", "Opsi". Bukan "Edit Question Bank".

Accessibility requirements: satu `h1`. Setiap field berlabel terikat. Galat di dekat field. Fokus terlihat. Primer adalah tombol sungguhan.

Domain behavior that must remain unchanged: sunting hanya bila controller yang ada mengizinkan draf. PATCH tetap `question-sets.update`. Jangan menambah perilaku bentuk soal yang server tidak terima. Jangan membuka edit untuk set terbit.

Acceptance criteria:
- Pada 390 px setiap label terlihat dan primer tercapai tanpa gulir horizontal halaman.
- Form tetap PATCH `question-sets.update`.
- Tidak ada kontrol bentuk soal yang tidak ada pada view sekarang.

Visual QA evidence required: mobile 390 untuk pilihan ganda dan esai bila kedua data itu ada. Desktop 1440 untuk satu draf.

## ACC-01

ID ACC-01. Area langganan. Rute `account.subscription.show`, GET `/account/subscription`, view `account.subscription.show`. Audiens aplikasi, bila `BuildSubscriptionPage` berhasil. Layout `resources/views/layouts/app.blade.php`. Tujuan: melihat paket, kuota, dan mengonfirmasi pembayaran hanya bila checkout yang ada mengizinkan. Sasaran pengguna: memahami sisa kuota dan, bila boleh, mengonfirmasi satu penawaran.

Current state summary: kartu bersarang, status permintaan mentah, kata "generation", QRIS, dan tombol konfirmasi WhatsApp. Permintaan tertunda menghalangi pilihan penawaran lain.

Target experience: PageHeader Langganan. CreditIndicator memakai angka server. Penawaran adalah kartu yang dibandingkan, bukan kartu di dalam panel. Primer Konfirmasi pembayaran hanya pada penawaran yang boleh dipilih. Permintaan tertunda tidak punya primer penawaran lain.

Primary action by state:
- Checkout tersedia dan tidak ada permintaan tertunda: Konfirmasi pembayaran (primer) pada penawaran yang dipilih.
- Permintaan tertunda: tidak ada primer penawaran. Konfirmasi WhatsApp untuk permintaan yang sama hanya bila view yang ada sudah menampilkannya.
- Checkout tidak tersedia: tidak ada primer.

Secondary actions: tidak ada aksi langganan lain. Jangan menambah checkout kedua.

Information hierarchy:
1. Judul halaman (h1).
2. Paket dan kuota dari server.
3. Permintaan tertunda, bila ada.
4. Penawaran atau alasan checkout tidak ada.
5. Riwayat, bila ada.

Section structure:
- Header: PageHeader.
- Kuota: CreditIndicator dan kalimat masa berlaku yang server kirim.
- Tertunda: Panel tanpa kartu di dalamnya.
- Penawaran: satu kartu per penawaran, hanya bila checkout tersedia.
- Riwayat: Table dengan gulir terkandung.
- Footer: tidak ada.

Components: PageHeader, CreditIndicator, Panel, Button, Table, Alert.

View modes: checkout tersedia, permintaan tertunda, checkout tidak tersedia. Perpanjangan terantre adalah data di dalam halaman, bukan mode baru.

Async contract: STATEFUL BUT NOT POLLING.

Companion routes: POST `account.subscription.confirm`.

Empty state: jika tidak ada penawaran dan tidak ada permintaan, kalimat bahwa paket tidak tersedia. Bukan ACC-02. Tidak ada primer.

Loading / processing state: N/A. Tidak ada polling.

Error state: galat konfirmasi dari server tampil sebagai alert atau galat field yang view sudah miliki. Bukan status proses pembuatan soal.

Success / terminal state: setelah konfirmasi, server mengarahkan kembali. Flash lewat layout. Halaman kemudian menampilkan permintaan tertunda bila itu hasilnya.

Desktop behavior: penawaran berdampingan hanya sebagai kartu sebanding, tidak bersarang. QRIS dibatasi lebarnya.

Tablet behavior: penawaran boleh menjadi satu kolom bila sempit.

Mobile behavior (360/390): satu penawaran per blok. Hanya satu tombol isi-merek untuk pilihan yang sedang sah. QRIS mengikuti lebar kontainer dan tidak meluapkan halaman. Riwayat tidak mendorong gulir halaman.

UX writing direction: "Langganan", "Konfirmasi pembayaran". Bukan "Subscription", "generation", atau "Ref" sebagai istilah pengguna. Status permintaan memakai Tertunda, Disetujui, Ditolak, Dibatalkan.

Accessibility requirements: satu `h1`. QRIS punya teks alternatif yang menyebut pembayaran, plus kalimat di dekatnya. Kuota adalah teks, bukan hanya warna. Fokus terlihat pada primer.

Domain behavior that must remain unchanged: server tetap otoritas harga, kuota, keadaan bayar, permintaan tertunda, QRIS, dan ketersediaan checkout. Tidak ada checkout kedua selama tertunda. Tidak ada perhitungan ulang di klien.

Acceptance criteria:
- Permintaan tertunda tidak merender primer untuk penawaran lain.
- Angka kuota sama dengan yang server kirim, tanpa rumus baru di tampilan.
- Pada 390 px QRIS tidak menyebabkan gulir horizontal halaman.

Visual QA evidence required: desktop 1440 dan mobile 390 untuk checkout biasa, permintaan tertunda, dan QRIS.

## ACC-02

ID ACC-02. Area langganan. Rute `account.subscription.show`, GET `/account/subscription`, view `account.subscription.unavailable`. Audiens aplikasi, bila controller menangkap kegagalan integritas langganan. Layout `resources/views/layouts/app.blade.php`. Tujuan: menjelaskan bahwa data langganan tidak dapat ditampilkan. Sasaran pengguna: memahami bahwa tidak ada tindakan bayar di halaman ini.

Current state summary: satu kalimat di dalam kartu. Tidak ada tombol.

Target experience: PageHeader Langganan dan satu Alert atau Panel. Tidak ada checkout, QRIS, atau tombol pemulihan.

Primary action by state:
- Satu-satunya keadaan: tidak ada primer.

Secondary actions: tidak ada.

Information hierarchy:
1. Judul halaman (h1).
2. Satu kalimat bahwa paket tidak dapat ditampilkan.

Section structure:
- Header: PageHeader.
- Isi: Alert atau Panel.
- Footer: tidak ada.

Components: PageHeader, Alert atau Panel.

View modes: tunggal, tidak tersedia.

Async contract: NONE.

Companion routes: tidak ada.

Empty state: N/A. Kalimat halaman ini adalah keadaan gagal baca, bukan daftar kosong.

Loading / processing state: N/A.

Error state: seluruh halaman adalah penjelasan bahwa data tidak dapat ditampilkan. Jangan menambahkan langkah bayar.

Success / terminal state: N/A.

Desktop behavior: satu kolom `--container-form`.

Tablet behavior: sama seperti desktop.

Mobile behavior (360/390): satu kolom. Tidak ada tombol. Tidak ada gulir horizontal halaman.

UX writing direction: kalimat tenang yang sudah ada boleh dirapikan, tanpa janji coba lagi atau bayar.

Accessibility requirements: satu `h1`. Pesan adalah teks. Fokus tidak diperlukan pada aksi karena tidak ada aksi.

Domain behavior that must remain unchanged: bukan halaman bayar. Jangan mengarang checkout, QRIS, atau aksi pemulihan.

Acceptance criteria:
- Tidak ada tombol konfirmasi, penawaran, atau POST `account.subscription.confirm`.
- Pada 390 px halaman tidak meluap.

Visual QA evidence required: desktop 1440 dan mobile 390.

## ADM-01

ID ADM-01. Area admin. Rute `admin.subscription-upgrades.index`, GET `/admin/subscription-upgrades`. Audiens admin. Layout `resources/views/layouts/app.blade.php`. Tujuan: menyaring dan membuka permintaan upgrade yang sudah ada. Sasaran pengguna: menemukan satu referensi lalu membukanya.

Current state summary: saringan bahasa Inggris, tabel enam kolom, status mentah, dan pagination.

Target experience: PageHeader verifikasi pembayaran. Saringan adalah tautan sekunder. Tidak ada primer domain. Tabel atau ringkasan baris. Nilai kueri tidak diganti.

Primary action by state:
- Semua saringan: tidak ada primer.

Secondary actions: tautan saringan Tertunda, Disetujui, Ditolak, Dibatalkan, dan Semua. Membuka satu baris adalah navigasi, bukan tombol primer.

Information hierarchy:
1. Judul halaman (h1).
2. Saringan.
3. Daftar atau kalimat kosong.
4. Pagination bila ada.

Section structure:
- Header: PageHeader.
- Saringan: Button secondary atau tautan.
- Isi: Table atau ringkasan, atau EmptyState.
- Footer: Pagination.

Components: PageHeader, Button, Table, StatusBadge, EmptyState, Pagination.

View modes: kueri `pending`, `approved`, `rejected`, `cancelled`, dan `all`. Nilai itu tetap.

Async contract: STATEFUL BUT NOT POLLING.

Companion routes: tidak ada mutasi di daftar. Query string status tetap pada rute yang sama.

Empty state: kalimat "Tidak ada permintaan." Tidak ada primer dan tidak ada laporan baru.

Loading / processing state: N/A.

Error state: N/A pada daftar. Kegagalan otorisasi ditangani middleware, bukan salinan halaman.

Success / terminal state: N/A. Keputusan terjadi di ADM-02.

Desktop behavior: tabel referensi, pengguna, penawaran, jumlah, status, waktu. Pagination di bawah.

Tablet behavior: gulir horizontal terkandung, atau ringkasan bila kolom tidak muat.

Mobile behavior (360/390): saringan membungkus. Baris menampilkan referensi, status, dan tautan buka. Enam kolom tidak mendorong gulir halaman.

UX writing direction: Tertunda, Disetujui, Ditolak, Dibatalkan, Semua. Bukan Pending, Approved, Rejected, Cancelled. Status baris memakai label yang sama, bukan nilai enum mentah.

Accessibility requirements: satu `h1`. Saringan adalah tautan atau tombol berlabel. Status punya teks. Fokus terlihat.

Domain behavior that must remain unchanged: nilai kueri `pending`, `approved`, `rejected`, `cancelled`, dan `all` tidak diganti. Tidak ada kemampuan laporan admin baru. `role:admin` tetap.

Acceptance criteria:
- Pada 390 px saringan dan daftar tidak menyebabkan gulir horizontal halaman.
- Href saringan tetap memakai nilai kueri yang ada.
- Tidak ada tombol primer domain.

Visual QA evidence required: mobile 390 untuk saringan tertunda, desktop 1440 untuk daftar berisi.

## ADM-02

ID ADM-02. Area admin. Rute `admin.subscription-upgrades.show`, GET `/admin/subscription-upgrades/{upgradeRequest}`. Audiens admin. Layout `resources/views/layouts/app.blade.php`. Tujuan: menyetujui, menolak, atau membatalkan permintaan yang masih tertunda. Sasaran pengguna: mengambil satu keputusan yang server izinkan.

Current state summary: detail permintaan dan, hanya bila status `pending`, tombol setujui, batalkan, serta tolak dengan alasan wajib.

Target experience: PageHeader kode referensi. Fakta permintaan dalam kalimat biasa. Jika `pending`, satu primer Setujui. Batalkan sekunder. Tolak destruktif dengan alasan. Jika bukan `pending`, tidak ada ketiga aksi itu.

Primary action by state:
- `pending`: Setujui (primer).
- Selain `pending`: tidak ada primer.

Secondary actions:
- `pending`: Batalkan.
- Semua keadaan: kembali ke ADM-01.

Information hierarchy:
1. Kode referensi (h1) dan status.
2. Pengguna, penawaran, durasi, jumlah, dan waktu yang server kirim.
3. Alasan penolakan yang sudah ada, bila ada.
4. Aksi hanya bila `pending`.

Section structure:
- Header: PageHeader, StatusBadge, tautan kembali.
- Fakta: Panel.
- Keputusan: hanya bila `pending`, Button setujui dan batalkan, lalu Textarea alasan dan Button danger tolak.
- Footer: tidak ada.

Components: PageHeader, StatusBadge, Panel, Button, Textarea, Alert.

View modes: `pending` versus sudah diputuskan (`approved`, `rejected`, `cancelled`).

Async contract: STATEFUL BUT NOT POLLING.

Companion routes: POST `admin.subscription-upgrades.approve`, POST `admin.subscription-upgrades.reject`, POST `admin.subscription-upgrades.cancel`. Hanya dirender bila view sekarang merendernya untuk `pending`.

Empty state: N/A.

Loading / processing state: N/A.

Error state: galat `rejection_reason` di dekat textarea. Bukan alert proses.

Success / terminal state: setelah keputusan, server mengarahkan kembali. Halaman yang dibuka lagi pada status final tidak menampilkan tiga aksi.

Desktop behavior: fakta di atas. Setujui terpisah dari Tolak. Tolak tidak sejajar sewarna dengan Setujui.

Tablet behavior: sama seperti desktop.

Mobile behavior (360/390): aksi menumpuk. Alasan labelnya terlihat. Tolak di bawah, terpisah dari Setujui. Tidak ada gulir horizontal halaman.

UX writing direction: "Setujui", "Batalkan", "Tolak", "Alasan penolakan". Hilangkan snapshot, window, dan Subscription sebagai jargon. Tanggal tetap kalimat biasa.

Accessibility requirements: satu `h1`. Status punya teks. Alasan berlabel dan galat di dekatnya. Tombol berlabel. Jalur papan ketik dapat mencapai setujui, batal, alasan, dan tolak. Fokus terlihat.

Domain behavior that must remain unchanged: setujui, tolak, dan batalkan hanya bila server mengizinkan status `pending`. `role:admin` tetap. Tidak ada kemampuan admin baru. Alasan penolakan tetap mengikuti validasi yang ada.

Acceptance criteria:
- Status bukan `pending` tidak menampilkan Setujui, Batalkan, atau Tolak.
- Tolak tanpa alasan tetap gagal di server dan galat tampil di dekat field.
- Pada 390 px ketiga aksi tidak sebaris dan tidak meluapkan halaman.

Visual QA evidence required: desktop 1440 dan mobile 390 untuk tertunda dan untuk permintaan yang sudah diputuskan. Sertakan jalur papan ketik pada tertunda.

## Cakupan

ID yang ada di dokumen ini: PUB-01, AUTH-01, DASH-01, DASH-02, MAT-01, MAT-02, MAT-03, MAT-04, MAT-05, PROF-01, BP-01, BP-02, BP-03, IMP-01, IMP-02, GEN-01, GEN-02, GEN-03, GEN-04, GEN-05, QB-01, QB-02, QB-03, ACC-01, ACC-02, ADM-01, ADM-02. Tidak ada ID lain.
