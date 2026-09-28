# Spesifikasi komponen UI/UX Refresh V1

Status dokumen: **UI-0 Pass 5**. Kontrak komponen, bukan implementasi.

Primitif nanti hidup di `resources/views/components/ui/`. Komponen domain hanya bila disebut di bawah. Halaman memanggil primitif. Jangan mengulang rangkaian utilitas panjang.

Komponen menyajikan. Komponen tidak memutuskan kelayakan, izin, kredit, atau transisi status.

Tidak dibuat: IconButton, Toggle, Drawer, Dropdown, Tabs, Stepper, Toast, Skeleton, Spinner terpisah. `window.confirm` boleh tetap sampai spesifikasi halaman menggantinya. Tidak ada dialog kustom wajib.

Token dari `docs/ui-ux/02-DESIGN-TOKENS.md`. Status dari `docs/ui-ux/08-STATE-AND-FEEDBACK-SYSTEM.md`. Istilah dari `docs/ui-ux/04-UX-WRITING-INDONESIA.md`.

## 1. Button

Tujuan: satu kontrol aksi.

Pakai untuk kirim, konfirmasi, atau navigasi yang memang tombol. Jangan pakai untuk tiap tautan kembali.

Jangan pakai dua tombol isi-merek berdampingan. Jangan pakai bahaya untuk aksi yang tidak merusak.

| Varian | Bentuk | Kapan |
| --- | --- | --- |
| primary | Isi `--color-brand`, teks putih, `--control-height` | Paling banyak satu per keadaan. Nol sah |
| secondary | Permukaan plus `--border-strong` | Aksi lain yang sah |
| tertiary | Tautan teks `--color-brand` | Kembali, jarang |
| danger | Isi `--color-danger` | Hapus, tolak, arsip |

Keadaan: default, hover `--color-brand-hover` hanya pada primer, disabled bila server sudah menolak aksi itu, fokus `--focus-ring`.

Slot: label verba. Tidak ada ikon wajib.

Responsif: boleh membungkus. Di layar sentuh, area pukul mendekati 44px. Varian padat 36px hanya di tabel desktop yang spesifikasi izinkan. Jarak di sekeliling bukan area pukul.

Aksesibilitas: `<button>` untuk mengubah keadaan, `<a>` untuk pindah halaman. Label terlihat.

Token: warna, radius `--radius-sm`, tinggi kontrol.

Bahasa: label dari panduan istilah. Komponen tidak menerjemahkan enum.

Domain: tidak memilih rute, tidak menghitung izin.

Tidak memutuskan: kelayakan, Pro, kredit.

Permukaan: semua ID yang punya aksi.

## 2. PageHeader

Tujuan: judul halaman, konteks, dan slot aksi.

Pakai di awal isi. Jangan mengulang judul di dalam panel.

Isi: satu `h1`, teks pendukung opsional, kembali opsional, status opsional, slot primer dan sekunder. Eyebrow langka dan bukan huruf besar Inggris. Eyebrow bukan heading.

Jangan pakai untuk setiap `h2`.

Responsif: aksi berpindah ke bawah judul di lebar sempit. Primer tetap terlihat.

Aksesibilitas: satu `h1`.

Tidak memutuskan: aksi mana yang primer. Halaman yang mengisi slot.

Permukaan: semua ID.

## 3. Alert

Tujuan: pesan halaman, bukan status objek yang menetap.

Pakai untuk flash sukses, flash galat, dan penjelasan Pro yang menghalangi aksi. Jangan dobel dengan alert yang sama.

Varian: success, danger, warning, info. Memakai pasangan subtle token.

Jangan pakai untuk tiap field. Galat field stays di dekat kontrol.

Tidak memutuskan: apakah proses gagal. Hanya menampilkan pesan yang sudah diberikan.

Permukaan: layout, IMP-02, BP-03, GEN-04, GEN-05, ADM-02.

## 4. StatusBadge

Tujuan: metadata ringkas.

Pakai di tabel, daftar, atau samping judul bila status tidak menentukan langkah sendirian.

Jangan pakai sebagai satu-satunya penjelasan IMP-02, PROF-01 in-flight, atau generasi yang belum terminal.

Varian mengikuti keluarga: neutral, processing, success, warning, danger, info. Teks wajib. Bukan hanya warna.

Masukan: label yang sudah diterjemahkan. Bukan nilai enum mentah.

Tidak memutuskan: kelayakan, izin, daur hidup, nilai tersimpan, atau menyalakan aksi.

Permukaan: MAT-01, MAT-04, BP-01, BP-03, GEN-01, QB-01, QB-02, ADM-01.

## 5. Panel

Tujuan: satu kelompok kerja pada latar halaman.

Pakai untuk status, formulir, atau hasil. Jangan membungkus setiap paragraf, tabel, atau panel lain.

Bukan kartu. Radius `--radius-md`. Bayangan tidak ada. Padding `--space-6`.

Kartu hanya untuk objek yang dibandingkan atau dipilih: penawaran ACC-01. Kandidat impor memakai ImportCandidateCard, bukan Panel di dalam Panel. Jangan kartu di dalam kartu.

Tidak memutuskan isi.

Permukaan: hampir semua halaman alat.

## 6. TextInput, Textarea, Select

Tujuan: satu field berlabel.

Pakai dengan label terlihat dan `for`/`id`. Placeholder bukan label.

Keadaan: default, fokus, galat, disabled. Tinggi `--control-height`. Textarea minimum `--control-textarea-min`. Galat di bawah field.

Responsif: lebar penuh di dalam kelompok. Kelompok padat menjadi satu kolom di lebar sempit.

Tidak memutuskan validasi. Hanya menampilkan `@error` yang ada.

Permukaan: AUTH-01, MAT-03, MAT-05, BP-02, BP-03, IMP-02, GEN-02, GEN-04, QB-03, ADM-02.

## 7. FileInput

Tujuan: memilih berkas yang labelnya terlihat.

Pakai di MAT-03 dan unggah DOCX BP-01. Nama berkas terpilih terlihat, bukan hanya "pilih file".

Fokus terlihat. Tidak menyembunyikan label.

Tidak memutuskan tipe atau ukuran. Validasi tetap di server.

## 8. Checkbox dan Radio

Tujuan: pilihan yang sudah ada.

Radio untuk mode sederhana/lanjutan dan mode impor. Checkbox untuk acak soal dan pilihan kandidat.

Label terlihat di samping kontrol, terikat ke kontrol. Disabled tetap terlihat bila Pro mengunci lanjutan.

Tidak memutuskan apakah Pro aktif. Hanya mencerminkan atribut yang view sudah set.

Permukaan: BP-01, BP-02, BP-03, GEN-04, IMP-02.

## 9. Table

Tujuan: banyak baris sejenis di lebar longgar.

Pakai bila spesifikasi halaman memilih tabel. Jangan otomatis menjadi kartu.

Mode yang halaman boleh pilih: tabel desktop, gulir horizontal yang terkandung, kolom dikurangi, atau ringkasan baris. Gulir tidak boleh menjadi gulir halaman.

Tidak memutuskan mode. Spesifikasi halaman yang memilih.

Permukaan: MAT-01, MAT-02, MAT-04, BP-01, BP-03, IMP-01, GEN-01, GEN-05, QB-01, ACC-01, ADM-01.

## 10. Pagination

Tujuan: sebelumnya dan berikutnya yang sudah ada.

Pakai bila paginator Laravel punya lebih dari satu halaman. Jangan mengarang nomor halaman baru.

Keadaan nonaktif adalah teks, bukan tombol primer.

Tidak memutuskan ukuran halaman.

Permukaan: MAT-01, MAT-02, IMP-01, IMP-02, GEN-01, QB-01, ADM-01.

Bukti repositori IMP-02: `resources/views/materials/blueprint-imports/show.blade.php` baris 95–167 memakai `$candidates->hasPages()`, `$candidates->previousPageUrl()`, dan `$candidates->nextPageUrl()` untuk menavigasi daftar kandidat interpretasi. Ini paginasi kandidat tinjauan, bukan paginasi daftar impor.

## 11. EmptyState

Tujuan: tidak ada objek, plus satu langkah bila rute itu ada.

Pakai di daftar kosong. Jangan mengarang metrik.

Slot: kalimat, opsional satu primer.

Tidak memutuskan bahwa pengguna boleh membuat objek. Hanya bila halaman sudah punya tautan itu.

Permukaan: MAT-01, MAT-02, GEN-01, QB-01, BP-01, IMP-01.

## 12. ProcessingState

Tujuan: menjelaskan proses atau keadaan yang menentukan langkah.

Varian: neutral waiting, processing, completed, warning, failure, information.

Pakai sebagai permukaan, bukan badge, bila keadaan menentukan langkah. Jangan persentase palsu. Angka hanya bila data sungguhan ada, seperti langkah PROF-01.

Tidak mem-poll sendiri. Halaman yang sudah punya skrip tetap pemilik polling.

Tidak memutuskan terminal, gagal jaringan sebagai gagal domain, atau kelayakan.

Permukaan: MAT-04, PROF-01, IMP-02, BP-03, GEN-03, GEN-05.

## 13. MaterialStatus

Menampilkan status materi dan ekstraksi yang view sudah hitung. Tidak memulai ekstraksi dan tidak menambah polling.

Permukaan: MAT-01, MAT-04.

## 14. ImportProgress

Menampilkan tiga pipa terpisah: ekstraksi, interpretasi, pencocokan. Tidak menggabungkan menjadi satu badge. Tidak menyimpulkan bahwa satu pipa selesai berarti pipa lain selesai atau konversi diizinkan.

Permukaan: IMP-02, dan ringkasan di MAT-04 bila data impor terbaru ada.

## 15. GroundingStatus

Menampilkan status pencocokan, termasuk kosong. Tidak memutuskan konversi. `ready` ditampilkan sebagai pencocokan selesai, bukan siap membuat draf.

Permukaan: IMP-01, IMP-02.

## 16. ImportCandidateCard

Satu kandidat yang dapat dibaca, dan bila syarat konversi sudah dipenuhi halaman, dapat dipilih. Tidak merender formulir konversi hanya karena pencocokan `ready`. Halaman yang memutuskan apakah syarat bagian konversi terpenuhi.

Permukaan: IMP-02.

## 17. BlueprintRow

Satu baris kisi-kisi: tujuan, topik, indikator, level, kesulitan, bentuk, jumlah, sumber. Label terikat ke kontrol. Di lebar sempit, satu kolom.

Tidak menaikkan batas baris, tidak menghitung kredit final, tidak membuka konfirmasi.

Permukaan: BP-02, BP-03 draf.

## 18. BlueprintSummary

Menampilkan total soal dan perkiraan kredit yang view sudah hitung. Tidak menghitung ulang kredit.

Permukaan: BP-02, BP-03.

## 19. GenerationStatus

Menampilkan status satu produk. Masukan wajib membedakan generasi lama dan generation run. Tidak menggabungkan rute. Tidak menyimpan ke bank soal.

Anak run diterjemahkan tanpa mengganti nilai tersimpan.

Permukaan: GEN-03 atau GEN-05, tidak keduanya dalam satu instance.

## 20. GenerationResult

Menampilkan soal yang payload sudah beri, sesuai bentuk soal. Tidak menormalisasi hasil lama menjadi hasil run.

Permukaan: GEN-03, GEN-05, dan baca QB-02 bila spesifikasi memakai pola yang sama untuk tampilan saja.

## 21. CreditIndicator

Menampilkan terpakai, diproses, dan tersedia dari objek kuota yang server kirim. Menampilkan kredit yang diperlukan bila halaman sudah mengirim angkanya. Tidak menghitung ulang.

Permukaan: GEN-02, GEN-03, GEN-04, ACC-01.

## 22. Yang tidak dimiliki komponen

Kelayakan profil, pencocokan, indeks konversi, pilihan kandidat, `created_blueprint_id`, draf atau terkonfirmasi, Pro, kredit, dua arsitektur generasi, otorisasi coba lagi, dan keputusan bayar atau admin. Jika UI butuh keputusan itu di komponen: **SEPARATE PRODUCT/DOMAIN DECISION REQUIRED**.
