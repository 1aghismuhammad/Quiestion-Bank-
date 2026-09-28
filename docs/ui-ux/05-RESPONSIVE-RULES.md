# Aturan responsif UI/UX Refresh V1

Status dokumen: **UI-0 Pass 6**. Aturan susunan. Bukan bukti bahwa layar sudah diuji.

Lebar uji: 360, 390, 768, 1024, dan 1440 px. Itu viewport QA, bukan breakpoint kustom.

Sistem breakpoint tetap bawaan Tailwind v4: `sm` 40rem, `md` 48rem, `lg` 64rem, `xl` 80rem, `2xl` 96rem. Jangan menambah breakpoint 360, 390, atau 1440.

Padding horizontal mengikuti token: 16px di bawah `48rem`, 24px mulai `48rem`.

## 1. Aturan global

Tidak ada gulir horizontal tingkat halaman. Gulir horizontal yang terkandung sah untuk tabel yang spesifikasinya memilih itu.

Aksi primer keadaan itu, bila ada, harus tercapai tanpa lebar desktop. Label tetap terlihat. Validasi tetap di dekat field. Di konteks sentuh, area pukul mendekati 44px. Jarak di sekeliling kontrol bukan area pukul. Jangan mengandalkan hover. Informasi wajib tidak hanya di tooltip.

Halaman alat memakai `--container-app`. Formulir sempit memakai `--container-form`. Lebar baca `--container-reading` hanya untuk prosa, bukan untuk kisi-kisi, impor, atau bank soal.

## 2. Shell

Nav atas harus dapat dipakai pada 360 dan 390 px tanpa meluapkan halaman. Identitas pengguna boleh menyusut atau pindah ke baris berikutnya. Keluar tetap kontrol yang terlihat.

Titik integrasi admin: spesifikasi DASH-01 dan DASH-02. Bukan keputusan dokumen ini. Shell tidak mendapat rute atau izin baru.

## 3. Tabel

Jangan mengubah semua tabel menjadi kartu.

| ID | Risiko | Arah |
| --- | --- | --- |
| MAT-01, MAT-02 | HIGH | Di bawah `md`, ringkasan baris: judul, status, lalu detail sekunder. Bukan enam kolom yang mendorong halaman |
| MAT-04 generasi terbaru | HIGH | Gulir terkandung atau ringkasan. Formulir topik tidak ikut tergulung tabel |
| BP-01 | HIGH | Gulir terkandung pada daftar. Kartu pembuatan menumpuk |
| BP-03 terkonfirmasi | HIGH | Gulir terkandung untuk tujuh kolom, atau ringkasan baris di bawah `md` |
| IMP-01 | HIGH | Ringkasan: nama berkas, tiga status singkat, tautan tinjau |
| GEN-01 | HIGH | Ringkasan: status, materi, jumlah |
| GEN-05 langkah | MEDIUM | Gulir terkandung. Sedikit kolom |
| QB-01 | HIGH | Ringkasan: judul, status, jumlah |
| ACC-01 riwayat | MEDIUM | Gulir terkandung |
| ADM-01 | HIGH | Ringkasan: referensi, pengguna, status. Saringan membungkus |

## 4. Formulir

BP-02, BP-03, IMP-02, QB-03, dan topik MAT-04: kelompok field menjadi satu kolom di bawah `md`. Tidak ada dua kontrol sempit berdampingan pada 360 px. Tombol baris tetap di bawah field, lebar nyaman.

IMP-02 konversi: satu kandidat per blok, pilihan di atas field pelengkap, primer di akhir blok yang terlihat.

## 5. Kelompok aksi

Aksi membungkus. Di bawah `md`, tumpuk. Primer di atas atau paling mudah dijangkau. Bahaya terpisah, tidak sejajar sewarna dengan primer. Jangan lima tombol setara pada 360 px. MAT-04 dan BP-01 adalah contoh yang harus punya hierarki, bukan deretan setara.

## 6. Dialog

Tidak ada dialog kustom wajib. `window.confirm` tetap sampai spesifikasi halaman menggantinya. Jika nanti ada dialog, pada layar sempit ia melebar hampir penuh. Jangan membuat drawer hanya di dokumen ini.

## 7. Halaman yang tidak berisiko tinggi

AUTH-01, MAT-03, MAT-05, ACC-02, dan DASH-02 tetap satu kolom yang sudah sempit. Tetap diperiksa pada 360 px agar tidak meluap karena shell.
