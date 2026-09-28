# Standar aksesibilitas UI/UX Refresh V1

Status dokumen: **UI-0 Pass 6**. Standar praktis untuk produk ini. Bukan klaim sertifikasi WCAG.

## 1. Semantik

Tautan untuk pindah halaman. Tombol untuk mengirim atau mengubah keadaan di halaman. Jangan memakai `div` yang diklik bila elemen bawaan cukup.

## 2. Label

Label terlihat. Hubungkan dengan `for` dan `id`. Placeholder bukan label. Galat di dekat field.

Risiko saat ini: sebagian besar label di `resources/views/blueprints/_row.blade.php` tidak terikat ke kontrol. BlueprintRow wajib memperbaiki itu. Jangan mengulang pola itu di IMP-02 atau QB-03.

## 3. Fokus

Pakai `--focus-ring`. Jangan menghapus fokus tanpa pengganti. Polling tidak boleh memindahkan fokus sebelum muat ulang penuh. Setelah muat ulang, fokus mengikuti halaman baru.

## 4. Status

Makna tidak hanya dari warna. Teks dapat dibaca. Pertahankan `aria-live` PROF-01 dan GEN-03, serta wilayah live IMP-02. Jangan mengumumkan nilai enum mentah. GEN-03 saat ini melakukan itu. Target mengumumkan label manusia.

## 5. Sentuh

Pada konteks sentuh atau sempit, area pukul interaktif mendekati 44px, lewat padding kontrol atau pembungkus yang dapat diklik. Kontrol padat 36px hanya untuk desktop yang spesifikasi izinkan, dan tidak otomatis sah untuk sentuh. Jarak di sekeliling kontrol bukan area pukul.

## 6. Heading

Satu `h1` yang bermakna. Urutan bagian logis. Eyebrow bukan heading.

## 7. Gambar

Gambar yang membawa informasi punya teks alternatif. Gambar hias tidak menambah derau. QRIS di ACC-01 fungsional. Pertahankan alternatif yang menyebut pembayaran, plus konteks kalimat di dekatnya. Jangan hanya mengandalkan gambar.

## 8. Konfirmasi

`window.confirm` boleh tetap sampai spesifikasi halaman menggantinya. Kalimat harus menyebut akibatnya: arsip materi, hapus topik, terbitkan soal. Jangan membuat modal hanya untuk tampilan.

## 9. Gerak

Hormati `prefers-reduced-motion`. Pertahankan perilaku kemajuan PROF-01. Tidak ada gerak dekoratif wajib.

## 10. Papan ketik yang nanti diuji

Shell, editor BP-02 dan BP-03, IMP-02, editor QB-03, dan ADM-02. Ini daftar uji, bukan bukti lulus.
