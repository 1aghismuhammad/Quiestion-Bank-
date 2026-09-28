# Daftar periksa QA visual UI/UX Refresh V1

Status dokumen: **UI-0 Pass 8**. Ini daftar yang fase implementasi nanti harus penuhi. Bukan bukti bahwa QA sudah lulus. UI-0 tidak membuat tangkapan layar.

Lebar uji: 360, 390, 768, 1024, dan 1440 px. Bukan breakpoint baru.

## 1. Visual umum

Pada permukaan yang berubah, periksa: susunan, hierarki, spasi token, satu `h1`, warna merek tidak memenuhi latar, paling banyak satu primer, bahaya terpisah, fokus terlihat, status punya kata, keadaan kosong atau galat atau proses bila halaman itu memilikinya, nav tidak meluap, salinan sesuai panduan bahasa, area sentuh pada lebar sempit.

Gagal bila: eyebrow Inggris wajib, dua tombol isi-merek, kartu di dalam kartu, enum mentah sebagai salinan, atau persentase proses yang tidak ada datanya.

## 2. Responsif

Pada kelima lebar:

- tidak ada gulir horizontal tingkat halaman;
- gulir tabel hanya di dalam daerah yang spesifikasi pilih;
- label tidak terpotong;
- aksi primer, bila ada, tercapai;
- formulir BP-02, BP-03, IMP-02, QB-03, dan topik MAT-04 nyaman di 360 dan 390;
- nav dapat dipakai di 360 dan 390;
- kelompok aksi MAT-04 dan BP-03 tidak meluap.

## 3. Keadaan proses

| Skenario | Lulus bila |
| --- | --- |
| MAT-04 pending atau processing | Ada kalimat dan cara muat ulang. Tidak ada polling baru |
| PROF-01 queued | Netral, bukan peringatan. Tidak ada primer domain |
| PROF-01 processing | Kalimat berjalan. Progress hanya bila langkah ada |
| PROF-01 ready | Hasil analisis siap. Bukan izin kisi-kisi otomatis |
| PROF-01 failed | Gagal, ulang hanya bila diizinkan |
| PROF-01 stale | Tidak sesuai konten terbaru. Bukan profil terkini |
| IMP-02 ekstraksi | Status ekstraksi sendiri |
| IMP-02 interpretasi `review_ready` | Siap ditinjau. Bukan form konversi |
| IMP-02 pencocokan berjalan | Bukan izin draf |
| IMP-02 ready dan indeks kosong | Pencocokan selesai. Tidak ada form. Tidak mengklaim siap membuat draf |
| IMP-02 draf sudah ada | Hanya buka draf yang ada |
| BP-03 AI queued atau processing | Tidak ada konfirmasi. Poll ke `materials.blueprints.status` |
| GEN-03 belum terminal | Tidak ada primer. Label manusia di live region |
| GEN-03 selesai | Simpan lewat `question-sets.import` atau buka set yang ada |
| GEN-05 | Rute `generation-runs.status`, retry, dan `question-sets.import-run` saja |
| Gagal jaringan saat poll | Bukan status domain gagal |

## 4. Regresi domain

- Google masih satu-satunya masuk. Tidak ada form kata sandi.
- `profile.complete` masih menahan dasbor.
- Pro masih menutup lanjutan dan ulang yang server tutup.
- Kuota dan kredit masih angka server.
- Kisi-kisi manual, AI, dan DOCX masih ada.
- Konversi impor masih memakai syarat yang sudah ada.
- GEN-01 sampai GEN-03 masih `GenerationController`.
- GEN-04 dan GEN-05 masih `GenerationRunController`.
- Simpan bank soal tidak tertukar rutenya.
- Terbit dan unduh masih pada rute yang ada.
- Langganan tidak menghitung harga. ACC-02 tidak punya checkout.
- Admin setujui, tolak, dan batalkan hanya saat server mengizinkan.

## 5. Aksesibilitas

Papan ketik pada shell, editor kisi-kisi, IMP-02, editor bank soal, dan ADM-02. Fokus terlihat. Label terikat, termasuk baris kisi-kisi. Heading logis. Live region tidak membacakan enum. Status tidak hanya warna. Area sentuh cukup. Reduced motion pada kemajuan profil. Konfirmasi menyebut akibat.

## 6. Kontras Visual

Wajib diperiksa pada browser sebelum UI-1 ditutup (tanpa klaim sertifikasi numerik WCAG):
- Teks utama (`--color-text`) di atas latar dan permukaan.
- Teks pendukung/redup (`--color-text-muted`) tetap terbaca, tidak terlalu pudar.
- Tautan (`--color-brand`) dapat dibedakan dari teks biasa.
- Kontrol form (batas dan placeholder) terlihat jelas.
- Cincin fokus (`--color-focus-ring`) kontras terhadap latar belakangnya.
- StatusBadge terbaca jelas teks di atas latar warnanya.
- Permukaan semantik (success, warning, danger, info) memiliki kontras teks yang memadai.
- Teks putih di atas tombol primer (`--color-brand`) terbaca jelas.

## 7. Bukti yang fase nanti kumpulkan

Bukan pada UI-0. Untuk permukaan yang berubah: satu tangkapan desktop dan satu mobile. Untuk IMP-02, BP-03, QB-03, dan ADM-02: keadaan yang relevan bila dapat disiapkan. Jangan membuat tangkapan palsu.

## 8. Syarat tutup UI-1

UI-1 tidak ditutup sebelum uji browser pada 360, 390, 768, 1024, dan 1440 px. Minimum: shell, nav, kontras visual (berdasarkan bagian 6), fokus, kontainer, tombol, kontrol form, status, dan perilaku primitif tabel. Isi halaman belum harus selesai di UI-1.

## 9. Syarat tutup UI-7

Daftar ini lulus pada 27 ID, salinan sisa bersih, dan regresi bagian 4 lulus. Council yang menandai selesai. Dokumen ini sendiri tidak menyatakan lulus.
