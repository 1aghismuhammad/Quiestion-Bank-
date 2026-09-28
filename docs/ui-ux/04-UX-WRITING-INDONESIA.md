# Panduan bahasa antarmuka Bahasa Indonesia

Status dokumen: **UI-0 Pass 6**. Mengatur salinan yang dilihat pengguna. Tidak mengganti nama rute, kelas, enum, kolom, atau kunci request.

Bahasa target: jelas, singkat, manusiawi, profesional, tenang, dan sebisa mungkin nonteknis. Hindari nada robot dan istilah backend bila ada frasa yang lebih jelas.

Locale runtime `config/app.php` tetap `en` pada CURRENT STATE. Mengubahnya **OPEN QUESTION**. Panduan ini mengatur salinan eksplisit di Blade, bukan pesan framework yang bergantung pada locale.

## 1. Istilah produk

Satu istilah target per konsep.

| Konsep | Istilah target | Bukan |
| --- | --- | --- |
| Dashboard | Dasbor | Beranda. Beranda tertukar dengan halaman publik |
| Home publik | tetap judul tugas, bukan "Dasbor" | — |
| Material | Materi | Material |
| Blueprint | Kisi-kisi | Blueprint |
| Question Bank | Bank soal | Question Bank |
| Draft | Draf | Draft |
| Confirm | Konfirmasi | Confirm |
| Upload | Unggah | Upload |
| Download | Unduh | Download |
| Save | Simpan | Save |
| Cancel | Batal | Cancel |
| Back | Kembali | Back |
| Next | Lanjut | Next |
| Retry | Coba lagi | Retry |
| Refresh status | Perbarui status | Bila perilaku yang ada adalah muat ulang penuh: Muat ulang |
| Processing | Sedang diproses | Processing |
| Queued | Menunggu diproses | Bukan peringatan |
| Completed | Selesai | Completed |
| Failed | Gagal | Failed |
| Published | Terbit | Published |
| Archived | Diarsipkan sebagai kata kerja, Arsip sebagai daftar | Archived |
| Assessment type | Jenis asesmen | Tipe assessment |
| Difficulty | Tingkat kesulitan | Difficulty |
| Question type | Bentuk soal | Tipe soal, bila yang dimaksud bentuk |
| Logout | Keluar | Logout |
| Subscription | Langganan | Subscription |
| Admin | Admin | Boleh tetap, sudah umum |

Aksi membuat soal: **Buat soal**. Nama proses: **Pembuatan soal**. Kata "Generasi" hanya bila perlu membedakan dua produk yang sudah ada: pembuatan dari materi, dan pembuatan dari kisi-kisi. Jangan memakai "Generate".

Nilai tersimpan tidak berubah. `draft` tetap `draft`. Labelnya "Draf".

## 2. `ready` bergantung konteks

Jangan satu terjemahan global.

| Konteks | Arah label | Bukan |
| --- | --- | --- |
| Profil `ready` | Hasil analisis siap | Siap membuat kisi-kisi |
| Interpretasi `review_ready` | Siap ditinjau | Siap dicocokkan selesai, atau siap draf |
| Pencocokan `ready` | Pencocokan selesai | Siap membuat draf |

"Siap membuat draf" hanya boleh bila syarat konversi yang sudah ada benar-benar terpenuhi. Jika tidak ada kandidat yang dapat dikonversi, katakan pencocokan selesai dan tidak ada kandidat yang dapat dipakai.

## 3. Tombol

Mulai dengan kata kerja. Spesifik.

Sah: Unggah materi, Simpan draf, Konfirmasi kisi-kisi, Buat soal, Coba lagi, Buka bank soal, Muat ulang, Keluar.

Hindari: OK, Submit, Proceed, Continue, Action, Process.

## 4. Galat

Menjawab apa yang gagal, apa yang dapat dilakukan, dan apa yang tidak hilang bila itu benar. Jangan janji coba lagi bila rute coba lagi tidak ada. Jangan tampilkan jejak stack atau nama kelas. Kegagalan jaringan saat polling bukan "proses gagal".

## 5. Proses

Ikuti `docs/ui-ux/08-STATE-AND-FEEDBACK-SYSTEM.md`. Menunggu diproses bukan nada peringatan. Selesai bukan izin langkah berikutnya. Basi profil: "Tidak sesuai konten terbaru". Dibatalkan bukan "Gagal".

## 6. Admin

Terjemahkan label yang admin lihat: Tertunda, Disetujui, Ditolak, Dibatalkan. Jangan menulis snapshot, window, atau Phase 1. Fakta masa berlaku dan jumlah tetap kalimat biasa.

## 7. Locale

**OPEN QUESTION.** Jangan mengubah `config/app.php` sebagai bagian penulisan salinan.
