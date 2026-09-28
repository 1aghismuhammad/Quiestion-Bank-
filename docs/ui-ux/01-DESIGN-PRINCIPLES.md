# Prinsip desain UI/UX Refresh V1

Status dokumen: **UI-0 Pass 2**. Prinsip ini mengatur keputusan visual. Angka ukuran, warna, dan spasi ada di `docs/ui-ux/02-DESIGN-TOKENS.md`. Larangan domain ada di `docs/ui-ux/11-IMPLEMENTATION-GUARDRAILS.md`.

| Label | Arti |
| --- | --- |
| CURRENT STATE | Pola yang ada di Blade produk pada baseline master plan. |
| TARGET STATE | Aturan yang dipakai agen implementasi setelah dokumen ini disetujui. |
| LOCKED RULE | Tidak boleh dilanggar oleh penyegaran UI. |
| OPEN QUESTION | Di luar pass ini. Jangan diputuskan di sini. |

## 1. Kepribadian visual

**LOCKED RULE.** Produk ini Clean Educational SaaS: profesional, tenang, modern, tepercaya, manusiawi, dan jelas bagi pengguna nonteknis. Terasa edukatif tanpa menjadi kekanak-kanakan, dan modern tanpa menjadi futuristik.

**CURRENT STATE.** Permukaan nyata memakai satu layout (`resources/views/layouts/app.blade.php`) dengan kartu putih, biru tombol `#2356d8`, dan eyebrow huruf besar berbahasa Inggris (`USER DASHBOARD`, `GENERATE QUESTIONS`, `QUESTION BANK`). DASH-01 menyajikan lima kartu setara. BP-01 menyajikan tiga kartu pembuatan setara. IMP-02 menaruh `class="card"` di dalam kartu lain, termasuk formulir konversi.

**TARGET STATE.** Halaman terasa seperti alat kerja guru: judul yang menjelaskan objek, status yang dapat dibaca, lalu satu langkah berikutnya. AI terlihat dari kejujuran status dan langkah yang bisa diikuti, bukan dari ilustrasi, kilau, atau label hiasan.

**LOCKED RULE.** Jangan menambahkan citra AI dekoratif, sparkle, gradien ungu/biru, neon, atau metrik semu. Angka yang sudah ada (kuota, jumlah soal, dua hitungan admin di DASH-02) boleh ditampilkan karena itu data produk, bukan hiasan.

## 2. Hierarki informasi

**TARGET STATE.** Setiap halaman, dalam urutan baca, menjawab empat hal:

1. Di mana pengguna berada: nama objek atau nama halaman, plus tautan kembali bila halaman itu anak dari objek lain (materi, kisi-kisi, impor, generasi, bank soal).
2. Keadaan objek atau proses: satu status yang dapat dibaca, bukan nilai enum mentah.
3. Aksi berikutnya yang utama, bila memang ada.
4. Informasi sekunder: metadata, riwayat, peringatan, dan tindakan lain.

**CURRENT STATE yang harus diperbaiki, bukan ditiru.**

- MAT-04 menaruh Edit, Arsipkan, Profil materi, Kisi-kisi, dan kadang Generate Questions dalam satu `.actions`.
- DASH-01 tidak punya satu langkah berikutnya. "Material Management" dan "Generate Question" sama-sama menuju `materials.index`.
- GEN-03 menampilkan label Indonesia dan nilai mentah seperti `queued` sekaligus.
- Eyebrow Inggris tidak menggantikan hierarki. Jangan membuat eyebrow baru hanya untuk meniru pola `.muted` huruf besar.

**LOCKED RULE.** Informasi yang wajib untuk keputusan pengguna tidak boleh hanya ada di tooltip.

## 3. Satu aksi utama

**TARGET STATE.** Tiap keadaan halaman punya paling banyak satu aksi primer. Nol aksi primer sah.

| Peran | Kapan | Bentuk target |
| --- | --- | --- |
| Primer | Satu langkah yang halaman itu minta diselesaikan | Isian merek, tinggi kontrol standar |
| Sekunder | Tindakan lain yang sah pada keadaan yang sama | Permukaan netral, garis, teks gelap |
| Tersier | Navigasi atau tindakan jarang | Tautan teks, bukan tombol merek |
| Destruktif | Menghapus, menolak, atau mengarsipkan | Warna bahaya, terpisah dari primer |

Bukan setiap halaman punya tombol primer.

- ACC-02 (`account.subscription.unavailable`) adalah keadaan gagal baca. Tidak ada checkout dan tidak ada tombol primer yang diada-adakan.
- Halaman proses yang masih menunggu atau sedang berjalan (profil in-flight, impor in-flight, generasi belum terminal) menjelaskan keadaan. Muat ulang adalah sekunder, bukan primer, bila aksi domain memang belum tersedia.
- GEN-03 atau GEN-05 yang sudah selesai dan belum disimpan punya satu primer: simpan ke bank soal lewat rute yang sudah ada. Membuka set yang sudah tersimpan adalah primer keadaan itu, bukan tombol kedua yang sewarna.
- BP-03 draf yang boleh diubah: primer adalah konfirmasi. Simpan draf adalah sekunder. BP-03 terkonfirmasi: primer adalah mulai generasi dari kisi-kisi. Unduh dan salin adalah sekunder.
- Destruktif (arsip materi, hapus topik, tolak upgrade) tidak pernah memakai warna merek.

**LOCKED RULE.** Jangan membuat dua tombol isi-merek bersanding. Jangan memakai warna bahaya untuk aksi yang tidak merusak.

## 4. Strategi permukaan

**TARGET STATE.**

| Permukaan | Pakai untuk | Jangan pakai untuk |
| --- | --- | --- |
| Latar halaman | Bidang di belakang seluruh isi | Kartu palsu selebar halaman |
| Panel | Satu kelompok kerja: status, formulir, atau hasil | Setiap paragraf |
| Kartu | Satu objek yang bisa dipilih atau dibandingkan dengan objek lain (penawaran langganan, kandidat yang memang berdiri sendiri) | Pembungkus judul, pembungkus tabel, pembungkus kartu lain |
| Bagian polos | Judul bagian plus isi yang sudah punya pemisah sendiri | — |
| Tabel atau daftar | Banyak baris sejenis | Satu atau dua field |
| Permukaan status | Satu keadaan proses atau hasil, dengan teks | Hiasan berwarna tanpa kalimat |

**LOCKED RULE.** Kartu di dalam kartu dilarang. Pengecualian tidak ada pada IMP-02, baris kisi-kisi, atau penawaran di ACC-01: isi lanjutan memakai bagian polos atau panel di dalam satu permukaan, bukan `card` kedua.

**CURRENT STATE.** `.card` adalah wadah default, termasuk DASH-01, daftar tabel, dan IMP-02. Itu bukti pemakaian berlebih, bukan pola target.

## 5. Hierarki tipografi

Peran semantik berikut wajib. Ukuran, bobot, dan tinggi baris ada di dokumen token, bukan di sini.

| Peran | Fungsi | Aturan |
| --- | --- | --- |
| Judul halaman | Satu `h1` | Nama objek atau tugas halaman. Bukan eyebrow. |
| Judul bagian | `h2` | Memecah wilayah besar: status, hasil, riwayat. |
| Judul panel | `h3` atau judul panel | Hanya di dalam panel yang benar-benar terpisah. |
| Isi | Paragraf dan sel | Ukuran baca default. |
| Teks pendukung | Bantuan, waktu, metadata | Jangan memakai warna redup untuk syarat yang menentukan boleh atau tidaknya aksi. |
| Label | Nama field | Selalu terlihat, terikat ke kontrol. |
| Keterangan | Angka kecil, rentang halaman, catatan di bawah field | Bukan pengganti label. |
| Eyebrow | Konteks sangat pendek di atas `h1` | Hanya bila spesifikasi halaman membuktikannya perlu. Bukan huruf besar Inggris pada setiap halaman. |

**CURRENT STATE.** `h1` dan `h2` tidak punya ukuran di layout, jadi judul mewarisi ukuran isi. `.brand` 18px bobot 750, `.stat` 34px, `.label` 14px. Eyebrow dipakai luas tanpa peran yang tetap.

**LOCKED RULE.** Jangan mengembalikan `.stat` 34px sebagai gaya metrik dasbor. DASH-02 boleh menampilkan dua hitungan yang ada, dengan peran isi atau judul bagian, bukan kartu metrik hias.

## 6. Spasi

**TARGET STATE.** Spasi mengelompokkan, bukan menghias. Pakai skala di dokumen token.

- Kontrol di dalam satu field rapat dengan label dan galatnya.
- Field dalam satu kelompok memakai langkah skala yang sama.
- Antarkelompok lebih besar daripada di dalam kelompok.
- Jangan menambah margin acak per halaman bila peran kelompoknya sama (judul halaman, panel, kelompok aksi).

**CURRENT STATE.** Layout memakai 6, 8, 10, 12, 14, 16, 18, 20, 24, 40, dan 48 px secara campur, sering lewat atribut `style`. PUB-01 memakai padding 48px pada kartu tunggal.

## 7. Warna

**TARGET STATE.**

- Warna merek hanya untuk aksi primer, tautan, fokus, dan penekanan yang dipilih. Bukan untuk setiap tombol, setiap kartu, atau latar halaman.
- Warna semantik hanya untuk sukses, peringatan, bahaya, dan informasi. Informasi jarang: petunjuk yang bukan status objek.
- Warna netral menanggung struktur: latar, permukaan, garis, teks, teks pendukung.
- Jangan mewarnai setiap wadah.

**CURRENT STATE.** Tombol sekunder memakai `#e9eef8`, yaitu biru muda yang bersaing dengan merek. Latar halaman `#f4f7fb` juga bernuansa biru. Status sukses, peringatan, dan bahaya sudah punya pasangan teks dan latar.

**LOCKED RULE.** Jangan membuat UI yang bergantung pada gradien. Jangan memakai warna merek sebagai latar panel besar.

## 8. Presentasi status

**TARGET STATE.** Status dipahami tanpa mengandalkan warna: ada kata. Warna hanya menguatkan kata itu.

| Keadaan yang dilihat pengguna | Bukan ini |
| --- | --- |
| Menunggu | `queued` mentah |
| Sedang diproses | `processing` mentah |
| Selesai sebagai proses | Izin untuk langkah domain berikutnya |
| Gagal | Hanya bidang merah tanpa kalimat |
| Dapat ditindaklanjuti | Tombol yang muncul sebelum syarat domain terpenuhi |
| Memenuhi syarat | Sama dengan "proses selesai" |

**LOCKED RULE.** Nilai enum tersimpan tidak ditampilkan sebagai salinan UI biasa. Menerjemahkan label tidak mengganti nilai yang disimpan atau yang dikirim form.

**CURRENT STATE.** GEN-01, GEN-03, GEN-05, QB-01, ACC-01, dan ADM-01 masih menampilkan nilai mentah di samping atau sebagai pengganti label.

## 9. Formulir

**TARGET STATE.**

- Label terlihat dan terikat ke kontrol. Placeholder bukan label.
- Bantuan berada di dekat field yang dijelaskannya.
- Galat berada di dekat field pemicunya, bukan hanya di atas halaman.
- Wajib atau opsional dapat dipahami dari label atau teks di field itu.
- Formulir panjang dikelompokkan (identitas kisi-kisi, lalu baris, lalu sumber konteks).
- Pada lebar sempit, kelompok field menjadi satu kolom.

**CURRENT STATE.** AUTH-01, MAT-03, dan MAT-05 sudah mengikat sebagian label lewat `for`/`id`. Baris di `resources/views/blueprints/_row.blade.php` sebagian besar tidak. `.field-grid` memakai `minmax(180px, 1fr)`, yang masih bisa merapatkan banyak kontrol.

**LOCKED RULE.** Jangan memindahkan syarat domain ke teks bantuan yang menyembunyikan penolakan server. Contoh: kunci Pro dan pesan kelayakan profil tetap terlihat sebagai keadaan, bukan tooltip.

## 10. Tabel dan daftar

**TARGET STATE.**

- Di lebar longgar, data sejenis boleh tetap tabel.
- Di lebar sempit, spesifikasi halaman memilih satu: gulir horizontal yang terkandung di tabel itu, kolom yang dikurangi, atau ringkasan baris. Pilih sesuai tugas, jangan semua tabel menjadi kartu.
- Gulir horizontal tidak boleh menjadi gulir seluruh halaman.
- Satu-satunya pembungkus gulir yang ada sekarang, `.blueprint-table-wrap` di BP-01, adalah bukti CURRENT STATE, bukan izin bagi tabel lain untuk meluap ke halaman.

**LOCKED RULE.** Jangan mengubah tabel menjadi kartu secara otomatis hanya karena lebarnya sempit. Keputusan per ID ada di spesifikasi halaman nanti.

## 11. Proses asinkron

**TARGET STATE.** Salinan dan permukaan status membedakan:

| Kata prinsip | Arti untuk UI |
| --- | --- |
| Menunggu | Diterima, belum dikerjakan |
| Sedang diproses | Pekerjaan berjalan |
| Selesai | Proses itu berakhir dengan hasil |
| Gagal | Proses berhenti karena kegagalan, dengan kalimat dan aksi coba lagi hanya bila rute itu sudah ada |
| Dapat ditindaklanjuti | Pengguna boleh menekan aksi yang rute dan syaratnya sudah ada |
| Memenuhi syarat | Aturan domain mengizinkan langkah berikutnya |

**LOCKED RULE.** Selesai tidak otomatis berarti memenuhi syarat.

Untuk impor DOCX di IMP-02, ikuti guardrail:

- `review_ready` pada interpretasi bukan izin membuat draf.
- Grounding `ready` sendiri bukan izin konversi.
- Formulir konversi tetap mengikuti syarat yang sudah ada, termasuk indeks yang dapat dikonversi, pemilihan kandidat, dan belum adanya draf dari impor itu.
- Jangan menampilkan tombol konversi lebih awal hanya agar halaman terasa "selesai".

**CURRENT STATE.** PROF-01, IMP-02, BP-03 (isi AI), GEN-03, dan GEN-05 sudah melakukan polling lalu memuat ulang. Ekstraksi berkas di MAT-04 meminta muat ulang manual dan tidak punya rute status JSON. Prinsip ini tidak menambah rute itu.

Halaman in-flight harus mengatakan apa yang terjadi dan apakah pengguna boleh meninggalkan halaman. PROF-01 sudah melakukan itu. Halaman lain yang hanya menulis nilai mentah belum.

## 12. Responsif

**TARGET STATE.** Susun dari layar sempit ke atas. Jangan mengorbankan kepadatan informasi di layar longgar hanya agar versi sempit menjadi setumpuk kartu.

- Navigasi shell harus tetap dapat dipakai tanpa meluapkan halaman.
- Aksi primer keadaan itu harus tercapai tanpa bergantung pada lebar desktop.
- Formulir padat menjadi satu kolom bila beberapa kolom membuat label atau kontrol tidak nyaman.
- Dialog atau permukaan besar yang nanti ada harus mengikuti lebar layar, bukan lebar tetap desktop.

**LOCKED RULE.** Lebar uji 360, 390, 768, 1024, dan 1440 px adalah viewport QA, bukan daftar breakpoint CSS. Rinciannya ada di dokumen token.

**CURRENT STATE.** Layout tidak punya `@media`. `.nav-actions` tidak membungkus. Itu risiko sumber, belum bukti piksel, dan tetap harus diuji di browser sebelum UI-1 ditutup.

## 13. Aksesibilitas

**TARGET STATE.**

- Fokus terlihat dan konsisten pada tautan, tombol, dan kontrol. CURRENT STATE hanya menata fokus pemilih berkas di BP-01.
- Kontrol memakai elemen semantik. Tautan untuk pindah halaman. Tombol untuk mengirim atau mengubah keadaan di halaman.
- Label terlihat, bukan hanya `placeholder` atau `aria-label` pada field biasa.
- Target sentuh mengikuti arah ukuran kontrol di dokumen token.
- Makna tidak hanya dari warna: status, galat, dan peringatan punya teks.
- Konfirmasi yang memakai `window.confirm` (arsip, hapus topik, terbitkan) boleh tetap sampai spesifikasi halaman menggantinya, asalkan kalimatnya menyebut akibatnya. Jangan menggantinya dengan dialog hias yang mengubah syarat server.

**LOCKED RULE.** Jangan menghapus `aria-live` yang sudah ada di PROF-01 dan GEN-03. Bila salinan live region diubah, isi yang diumumkan harus label yang dipahami pengguna, bukan enum mentah.

## 14. Gerak

**TARGET STATE.** Gerak hanya fungsional: pergantian keadaan, kemajuan, atau buka-tutup. Tidak ada kewajiban animasi dekoratif.

**CURRENT STATE.** PROF-01 sudah mematikan transisi progress bila pengguna meminta `prefers-reduced-motion`. Pertahankan perilaku itu pada indikator kemajuan.

**LOCKED RULE.** Jangan menambah animasi masuk, hover yang memantul, atau skeleton hias pada halaman yang datanya sudah ada.

## 15. Daftar cegah pola generik

Agen implementasi menolak pola berikut meskipun terlihat "modern":

- Gradien biru atau ungu sebagai latar, hero, atau tombol.
- Neon, glow, dan glassmorphism tanpa fungsi.
- Sparkle, ilustrasi acak, dan ikon emoji sebagai sistem.
- Setiap bagian dibungkus kartu, termasuk kartu di dalam kartu.
- Radius, bayangan, dan spasi yang berbeda di setiap halaman.
- Bayangan besar pada kartu biasa.
- Setiap tombol memakai warna merek.
- Metrik dasbor palsu, termasuk menghidupkan kembali `.stat` sebagai hiasan.
- Eyebrow Inggris di atas setiap `h1`.
- Enum mentah sebagai teks status.
- Menyamakan proses selesai dengan langkah berikutnya yang diizinkan.
- Mengubah semua tabel menjadi kartu.
- Animasi yang tidak menjelaskan keadaan.
- Tooltip sebagai satu-satunya tempat syarat, label, atau galat.

## 16. Di luar dokumen ini

**OPEN QUESTION.** Jangan diputuskan di prinsip atau token:

- memensiunkan generasi materi lama;
- menambah endpoint status ekstraksi;
- mengubah locale runtime;
- menghapus `welcome.blade.php`;
- penempatan entri admin.

Penempatan admin tetap keputusan spesifikasi UI-3, bukan navbar global yang dikunci dari dokumen prinsip.
