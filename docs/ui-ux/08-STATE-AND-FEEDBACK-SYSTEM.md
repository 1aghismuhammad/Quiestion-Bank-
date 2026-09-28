# Sistem keadaan dan umpan balik UI/UX Refresh V1

Status dokumen: **UI-0 Pass 4**. Ini kontrak presentasi. Bukan mesin keadaan domain dan bukan spesifikasi halaman.

| Label | Arti |
| --- | --- |
| CURRENT STATE | Yang halaman dan endpoint sudah lakukan. |
| TARGET STATE | Cara menyajikan keadaan itu setelah spesifikasi disetujui. |
| LOCKED RULE | Batas yang presentasi tidak boleh langgar. |
| OPEN QUESTION | Tidak diselesaikan di dokumen ini. |

Sumber: dokumen UI-0 yang sudah disetujui, view yang disebut di bawah, dan endpoint status yang sudah ada. Warna memakai keluarga di `docs/ui-ux/02-DESIGN-TOKENS.md`. `queued` dan `pending` biasa bukan peringatan.

## 1. Tiga lapis yang tidak boleh ditukar

**LOCKED RULE.**

| Lapis | Arti | Bukan |
| --- | --- | --- |
| PROCESS STATE | Apa yang proses itu sedang atau sudah lakukan | Izin langkah berikutnya |
| ACTIONABILITY | Apakah aksi pengguna yang sudah ada sedang tersedia | Status proses selesai |
| ELIGIBILITY | Apakah aturan domain mengizinkan langkah berikutnya | Badge "selesai" atau "siap" |

Proses boleh selesai tanpa langkah berikutnya memenuhi syarat. Contoh yang mengikat: interpretasi `review_ready` bukan pencocokan. Pencocokan `ready` bukan izin konversi. Profil `ready` tidak menghapus pesan tidak layak yang terpisah.

## 2. Keluarga presentasi

| Keluarga | Dipakai untuk | Tidak dipakai untuk |
| --- | --- | --- |
| NEUTRAL | Belum mulai, menunggu biasa, `queued`, `pending`, keadaan nonaktif yang normal | Kegagalan, basi, atau "perlu perhatian" |
| PROCESSING | Pekerjaan sedang berjalan | Menunggu di antrean |
| SUCCESS / COMPLETED | Proses itu sendiri selesai dengan hasil | Izin konversi, konfirmasi, atau simpan |
| WARNING | Basi, perlu perhatian, kondisi tidak biasa, atau kondisi yang dapat meminta tindakan | `queued` atau `pending` biasa |
| DANGER | Gagal, galat, akibat destruktif | Dibatalkan, menunggu, atau gerbang Pro yang hanya menjelaskan kunci |
| INFO | Penjelasan, petunjuk, atau catatan yang bukan masalah | Status proses |

Gerbang Pro adalah kelayakan, bukan status proses. Tampilkan sebagai penjelasan yang menghalangi aksi, bukan sebagai `failed`.

## 3. Pola umpan balik

Urutan yang dipakai, dari yang paling dekat ke field sampai yang menjelaskan halaman:

| Pola | Kapan | Mekanisme yang ada |
| --- | --- | --- |
| Galat field | Validasi satu field | `.error-text` di dekat field |
| Alert halaman | `session('success')` dan `session('error')` | `.alert` di `layouts.app` |
| Badge status | Metadata ringkas di daftar atau di samping judul | `.status` dan variannya |
| Permukaan status | Keadaan menentukan langkah berikutnya | Panel dengan kalimat, bukan hanya badge |
| Permukaan proses | Proses berjalan dan ada data kemajuan sungguhan, atau keadaan tak tentu bila tidak ada angka | `<progress>` hanya di PROF-01 |
| Keadaan kosong | Tidak ada objek | Kartu atau paragraf yang sudah ada |
| Konfirmasi sukses | Aksi domain diterima | Flash sukses. Jangan dobel |
| Kegagalan | Proses atau aksi gagal | Kalimat plus pemulihan yang rutenya sudah ada |
| Coba lagi | Pemulihan yang controller izinkan | POST yang sudah ada. Bukan otomatis |
| Muat ulang | Tidak ada polling, atau cadangan bila polling tidak tersedia | Tautan atau petunjuk yang sudah ada |

**CURRENT STATE.** Tidak ada sistem toast. Jangan mewajibkan toast. IMP-02 mengulang flash sukses yang sudah dirender layout. TARGET STATE: satu alert halaman.

**LOCKED RULE.** Jangan mengarang persentase. Angka kemajuan hanya bila payload yang ada mengirimnya.

## 4. Kelas Async dari inventaris

Definisi sama dengan `docs/ui-ux/06-PAGE-INVENTORY.md`.

| Kelas | Perilaku UI yang diizinkan | Yang dilarang |
| --- | --- | --- |
| NONE | Form atau halaman statis. Flash dan galat field bila aksi dikirim | Spinner proses, polling |
| MANUAL REFRESH | Kalimat keadaan, pembeda visual, dan cara memuat ulang yang terlihat | Endpoint atau skrip polling baru |
| POLLING | Keadaan in-flight yang dapat dibaca, poll ke rute yang sudah ada, muat ulang bila status domain berubah, cadangan muat ulang bila sudah ada | Menganggap gagal jaringan sebagai `failed` domain |
| STATEFUL BUT NOT POLLING | Aksi dan salinan mengikuti status tersimpan. Muat ulang atau navigasi mengambil keadaan baru | UI kemajuan hanya karena halamannya stateful. Jangan menambah polling agar terasa langsung |

STATEFUL BUT NOT POLLING bukan otomatis alur asinkron.

## 5. Ekstraksi berkas materi — MAT-04

**CURRENT STATE.** Label di `materials/show.blade.php`: `pending` Menunggu ekstraksi, `processing` Sedang diproses, `completed` Selesai, `failed` Ekstraksi gagal, `not_required` Tidak diperlukan. `pending` dan `processing` meminta muat ulang. Tidak ada rute JSON status ekstraksi. Async: MANUAL REFRESH.

| Domain state | Keluarga | Arah label | Umpan balik | Aksi |
| --- | --- | --- | --- | --- |
| `pending` | NEUTRAL | Menunggu diproses | Permukaan status plus muat ulang | Tidak ada primer domain |
| `processing` | PROCESSING | Sedang diproses | Sama | Tidak ada primer domain |
| `completed` | SUCCESS / COMPLETED | Selesai | Badge atau kalimat selesai | Bukan izin generasi |
| `failed` | DANGER | Gagal | Kalimat gagal | Hanya pemulihan yang sudah ada. Jangan mengarang unggah ulang bila tidak ada rute |
| `not_required` | NEUTRAL | Tidak diperlukan | Badge netral | Bukan masalah |

TARGET STATE boleh memperbaiki salinan, warna, dan keterlihatan muat ulang. Tidak boleh menambah polling.

## 6. Profil materi — PROF-01

**CURRENT STATE.** Keadaan `MaterialProfileOwnerState`: `none`, `queued`, `processing`, `ready`, `failed`, `stale`. Label view: Belum dianalisis, Menunggu antrian, Sedang dianalisis, Siap, Gagal, Tidak sesuai konten terbaru. `canStart` dan `canRegenerate` terpisah. `eligibilityMessage` tampil bila belum bisa dianalisis. In-flight mem-poll `materials.profile.status`, mengisi `aria-live` dan `<progress>` dari `completed_steps` / `total_steps`, lalu memuat ulang bila `terminal` atau `state` berubah. Gagal jaringan diulang paling banyak tiga kali, lalu polling berhenti tanpa menyatakan domain gagal. 401, 403, dan 404 menghentikan polling. Ada tautan Muat ulang status dan `<noscript>`. Progress menghormati `prefers-reduced-motion`.

| Domain state | Keluarga | Arah label | Aksi yang sudah ada |
| --- | --- | --- | --- |
| `none` | NEUTRAL | Belum dianalisis | Mulai analisis hanya bila `canStart`. Jika tidak: tidak ada primer, pesan kelayakan tetap |
| `queued` | NEUTRAL | Menunggu diproses | Tidak ada primer domain. Bukan peringatan |
| `processing` | PROCESSING | Sedang dianalisis | Tidak ada primer domain. Tunjukkan langkah bila datanya ada |
| `ready` | SUCCESS / COMPLETED | Hasil analisis siap | Bukan penghapus masalah kelayakan lain. Ulang hanya bila `canRegenerate` |
| `failed` | DANGER | Gagal | Ulang hanya bila `canRegenerate`. Profil lama yang masih sah tetap dijelaskan sebagai bukan hasil yang gagal |
| `stale` | WARNING | Tidak sesuai konten terbaru | Bukan profil terkini. Jangan menampilkan hasil basi sebagai profil siap |

**LOCKED RULE.** `ready` berarti hasil analisis untuk konten saat ini ada di model tampilan. Bukan izin membuat kisi-kisi dengan sendirinya. Pintu profil siap untuk kisi-kisi tetap aturan yang sudah ada di BP-01. Jangan menambah aksi yang controller tidak punya.

Arah label `ready` di sini adalah "Hasil analisis siap", bukan kata "Siap" yang sama dengan pencocokan.

## 7. Impor DOCX — IMP-02

Tiga pipa. Jangan dijadikan satu status generik. Async: POLLING ke `materials.blueprint-imports.status` saat ekstraksi, interpretasi, atau pencocokan masih in-flight. Payload memuat `extraction_status`, `interpretation_status`, `grounding_status`, `draft_blueprint_id`, `terminal`, `can_retry`. Gagal jaringan mengikuti pola profil: ulang terbatas, bukan `failed` domain. 401, 403, 404 menghentikan polling.

### A. Ekstraksi

Nilai: `pending`, `processing`, `extracted`, `failed`.

| State | Keluarga | Arah label | Bukan |
| --- | --- | --- | --- |
| `pending` | NEUTRAL | Menunggu ekstraksi | Peringatan |
| `processing` | PROCESSING | Sedang diekstraksi | Selesai |
| `extracted` | SUCCESS / COMPLETED | Ekstraksi selesai | Interpretasi selesai, apalagi izin draf |
| `failed` | DANGER | Ekstraksi gagal | Jangan menjalankan interpretasi dari UI |

### B. Interpretasi

Nilai: kosong, `queued`, `processing`, `review_ready`, `failed`.

| State | Keluarga | Arah label | Bukan |
| --- | --- | --- | --- |
| kosong | NEUTRAL | Belum diinterpretasi | Gagal |
| `queued` | NEUTRAL | Menunggu diproses | Peringatan |
| `processing` | PROCESSING | Sedang diproses | Siap ditinjau |
| `review_ready` | SUCCESS / COMPLETED | Siap ditinjau | Pencocokan `ready`, dan bukan izin konversi |
| `failed` | DANGER | Interpretasi gagal | Coba lagi hanya lewat `materials.blueprint-imports.retry` bila `canRetry` |

`review_ready` dengan pencocokan masih kosong menampilkan cocokkan (`materials.blueprint-imports.ground`). Itu aksi pencocokan, bukan konversi.

### C. Pencocokan

Nilai dari `_grounding-label.blade.php`: kosong Belum dicocokkan, `queued` Menunggu verifikasi, `processing` Sedang dicocokkan, `ready` Siap, `failed` Gagal.

| State | Keluarga | Arah label | Bukan |
| --- | --- | --- | --- |
| kosong | NEUTRAL | Belum dicocokkan | Gagal |
| `queued` | NEUTRAL | Menunggu diproses | Peringatan. "Menunggu verifikasi" sekarang mudah tertukar dengan peringatan |
| `processing` | PROCESSING | Sedang dicocokkan | Selesai |
| `ready` | SUCCESS / COMPLETED | Pencocokan selesai | "Siap membuat draf" |
| `failed` | DANGER | Pencocokan gagal | Coba lagi hanya lewat `materials.blueprint-imports.retry-grounding` |

**LOCKED RULE.** Konversi tetap memakai syarat yang sudah ada, sekaligus:

- `grounding_status` adalah `ready`;
- `$convertibleIndexes` tidak kosong;
- pengguna memilih kandidat;
- `created_blueprint_id` masih kosong;
- validasi server pada `materials.blueprint-imports.convert` tetap berlaku.

Jika pencocokan selesai tetapi tidak ada kandidat yang dapat dikonversi, UI boleh mengatakan pencocokan selesai. UI tidak boleh mengatakan impor siap dibuat menjadi draf.

Jika `created_blueprint_id` sudah terisi, langkah berikutnya adalah membuka draf yang ada. Bukan membuat draf kedua.

`ready` profil dan `ready` pencocokan tidak memakai kalimat yang sama.

## 8. Isi AI kisi-kisi — BP-03

**CURRENT STATE.** `BlueprintAiFillStatus`: `none`, `queued`, `processing`, `succeeded`, `failed`. View hanya memberi label pada `queued` (Dalam antrean) dan `processing` (Sedang diproses), dan saat ini memakai gaya peringatan. Itu CURRENT STATE, bukan target. `$canEditDraft` false selama in-flight. Poll `materials.blueprints.status` hanya saat in-flight. `terminal` di JSON berarti isi AI terminal atau daur hidup sudah `confirmed`. Reload bila `payload.terminal`. `catch` kosong tidak menampilkan kegagalan domain. Gagal isi menampilkan `error_message` dan "Coba isi AI lagi" hanya bila `failed` dan mutasi lanjutan diizinkan (`materials.blueprints.retry-ai`).

| State | Keluarga | Arah label | Sunting dan konfirmasi |
| --- | --- | --- | --- |
| `none` | NEUTRAL | Tidak ada badge proses | Mengikuti draf dan Pro, bukan status AI |
| `queued` | NEUTRAL | Menunggu diproses | Tidak ada. Bukan peringatan |
| `processing` | PROCESSING | Sedang diproses | Tidak ada |
| `succeeded` | SUCCESS / COMPLETED | Pengisian selesai | Bukan izin konfirmasi dengan sendirinya. Konfirmasi tetap butuh draf yang boleh diubah |
| `failed` | DANGER | Pengisian gagal | Coba lagi hanya bila aksi itu dirender. Konfirmasi tetap ditutup bila bendera server menutupnya |

Daur hidup `draft` / `confirmed` adalah status objek, terpisah dari isi AI. Pro adalah kelayakan terpisah: kisi-kisi lanjutan tanpa Pro tetap dapat dilihat, tetapi tidak diubah, dikonfirmasi, disalin, atau dipakai untuk generasi baru. Jangan membuat alur AI kedua.

## 9. Generasi materi lama — GEN-03

**CURRENT STATE.** `GenerationStatus`: `queued`, `processing`, `completed`, `failed`, `cancelled`. Terminal di `generations.status`: `completed`, `failed`, `cancelled`. Skrip mem-poll tiap 5000 ms bila belum terminal, menulis nilai mentah ke `aria-live`, lalu memuat ulang bila status berubah atau `terminal`. Galat jaringan menjadwalkan poll lagi, bukan status gagal. Belum ada penyimpanan: POST `question-sets.import`. Sudah ada set: buka `question-sets.show`. Gagal dan `@can('retry')`: POST `generations.retry`.

| Keadaan | Keluarga | Arah label | Aksi |
| --- | --- | --- | --- |
| `queued` | NEUTRAL | Menunggu diproses | Tidak ada primer domain |
| `processing` | PROCESSING | Sedang diproses | Tidak ada primer domain |
| `completed` belum disimpan | SUCCESS / COMPLETED | Selesai | Simpan ke bank soal lewat `question-sets.import` |
| `completed` sudah disimpan | SUCCESS / COMPLETED | Selesai | Buka set yang ada |
| `failed` dan retry diizinkan | DANGER | Gagal | Coba lagi sebagai pemulihan |
| `failed` dan retry tidak diizinkan | DANGER | Gagal | Tidak ada primer. Pesan galat tetap |
| `cancelled` | NEUTRAL | Dibatalkan | Tidak ada primer. Bukan gaya gagal |

Selesai belum berarti set sudah ada di bank soal. Tidak ada kemajuan persen. Keadaan in-flight butuh permukaan status, bukan hanya badge.

## 10. Generasi dari kisi-kisi — GEN-05

Bukan daur hidup GEN-03. Controller tetap `GenerationRunController`. Status run `GenerationRunStatus`: `queued`, `processing`, `completed`, `failed`. Terminal: `completed` atau `failed`. Tidak ada `cancelled` pada run. Poll `generation-runs.status` memakai `$pollIntervalMs` selama belum terminal, reload bila `payload.terminal`. `catch` kosong tidak menyatakan domain gagal. Anak menampilkan `generation_status` mentah. TARGET STATE menerjemahkan anak dengan kosakata bagian 9 tanpa mengganti nilai tersimpan.

| Keadaan run | Keluarga | Arah label | Aksi |
| --- | --- | --- | --- |
| `queued` | NEUTRAL | Menunggu diproses | Tidak ada primer domain |
| `processing` | PROCESSING | Sedang diproses | Tidak ada primer domain |
| `completed` belum disimpan | SUCCESS / COMPLETED | Selesai | POST `question-sets.import-run` |
| `completed` sudah disimpan | SUCCESS / COMPLETED | Selesai | Buka set yang ada |
| `failed` dan `$canRetry` | DANGER | Gagal | POST `generation-runs.retry` |
| `failed` dan bukan `$canRetry` | DANGER | Gagal | Tidak ada coba lagi. Kalimat Pro yang sudah ada tetap |

Jangan mengarahkan simpan GEN-05 ke `question-sets.import`. Jangan mengarahkan simpan GEN-03 ke `question-sets.import-run`.

## 11. Stateful tanpa polling

BP-01, QB-02, ACC-01, dan ADM-02 merender aksi dari status tersimpan. Mereka tidak membutuhkan UI kemajuan.

- BP-01: daftar `draft` / `confirmed` dan gerbang profil siap. Isi AI yang dimulai di sini diikuti di BP-03, bukan di daftar.
- QB-02: draf boleh terbitkan. Terbit boleh dua unduhan. Status lain yang hanya berlabel tidak mendapat aksi baru.
- ACC-01: checkout, permintaan tertunda, dan kuota adalah keadaan tersimpan. Permintaan tertunda tidak membuka checkout kedua.
- ADM-02: setujui, tolak, dan batalkan hanya saat permintaan `pending`.

Muat ulang atau navigasi mengambil keadaan baru. Jangan menambah polling agar terasa langsung.

## 12. Badge versus permukaan

Badge untuk metadata di tabel, daftar, atau samping judul: draf, terbit, selesai yang sudah tidak menentukan langkah, sumber.

Permukaan status bila keadaan menentukan langkah berikutnya atau proses masih berjalan: MAT-04 saat ekstraksi belum selesai atau gagal, PROF-01, ketiga pipa IMP-02, isi AI in-flight atau gagal di BP-03, GEN-03 dan GEN-05 yang belum terminal atau gagal.

IMP-02 tidak boleh hanya mengandalkan badge kecil. GEN-03 in-flight harus menjelaskan apa yang terjadi, dengan label manusia, bukan enum mentah di `aria-live`.

Jangan mengubah setiap status menjadi alert besar. Daftar GEN-01 cukup badge plus label. Jangan menaruh alert per baris.

## 13. Coba lagi

Coba lagi hanya bila view sudah merender form ke rute yang ada:

- interpretasi: `materials.blueprint-imports.retry` bila `canRetry`;
- pencocokan: `materials.blueprint-imports.retry-grounding` bila gagal;
- isi AI: `materials.blueprints.retry-ai` bila `failed` dan mutasi lanjutan diizinkan;
- generasi lama: `generations.retry` bila otorisasi retry lulus;
- generation run: `generation-runs.retry` bila `$canRetry`.

Jika syarat itu tidak terpenuhi, tombol tidak ada. Pesan galat sebelumnya tetap dapat dibaca. Coba lagi adalah pemulihan proses itu, bukan kelayakan langkah hilir. Pro yang memblokir ulang tetap kalimat kelayakan, bukan status `failed` baru.

## 14. Polling

Halaman: PROF-01, IMP-02, BP-03, GEN-03, GEN-05. Rute: `materials.profile.status`, `materials.blueprint-imports.status`, `materials.blueprints.status`, `generations.status`, `generation-runs.status`.

Interval yang ada: `$pollIntervalMs` pada PROF-01, IMP-02, BP-03, dan GEN-05. GEN-03 memakai 5000 ms di skrip. Dokumen ini tidak mengubah interval.

Saat in-flight, pengguna melihat kalimat keadaan. UI boleh memuat ulang lewat polling yang ada. Muat ulang manual tetap cadangan di tempat yang sudah ada (PROF-01, dan `<noscript>` pada PROF-01 serta GEN-03).

**LOCKED RULE.** Kegagalan jaringan atau poll yang berulang bukan `failed` domain, kecuali payload status domain memang berubah menjadi gagal. BP-03 dan GEN-05 saat ini menelan `catch` dan tidak menampilkan gagal domain. PROF-01 dan IMP-02 berhenti setelah tiga kegagalan tanpa mengganti status domain. Pertahankan perbedaan itu. Jangan menambah rute status.

Muat ulang penuh adalah perilaku yang ada. Jangan memindahkan fokus di dalam halaman yang sama selama poll belum memuat ulang.

## 15. Aksesibilitas umpan balik

- Status punya kata, bukan hanya warna.
- Teks live dapat dibaca manusia. GEN-03 saat ini mengumumkan nilai mentah. TARGET STATE mengumumkan label.
- Pertahankan `aria-live` yang ada di PROF-01 dan GEN-03. IMP-02 punya wilayah live saat in-flight.
- Galat menyebut apa yang gagal.
- Polling tidak melompatkan fokus sebelum muat ulang.
- Kemajuan PROF-01 tetap menghormati `prefers-reduced-motion`.
- Coba lagi dan muat ulang memakai tombol atau tautan dengan label terlihat.

## 16. Matriks

| Surface | Domain state | Keluarga | Arah label | Umpan balik utama | Arah aksi | Async | Catatan |
| --- | --- | --- | --- | --- | --- | --- | --- |
| MAT-04 | `pending` | NEUTRAL | Menunggu diproses | Permukaan + muat ulang | Tidak ada primer | MANUAL REFRESH | Bukan polling |
| MAT-04 | `processing` | PROCESSING | Sedang diproses | Permukaan + muat ulang | Tidak ada primer | MANUAL REFRESH | |
| MAT-04 | `completed` | SUCCESS / COMPLETED | Selesai | Badge | Bukan izin generasi | MANUAL REFRESH | |
| MAT-04 | `failed` | DANGER | Gagal | Kalimat | Hanya pemulihan yang ada | MANUAL REFRESH | |
| MAT-04 | `not_required` | NEUTRAL | Tidak diperlukan | Badge | Tidak ada | MANUAL REFRESH | Bukan masalah |
| PROF-01 | `none` | NEUTRAL | Belum dianalisis | Permukaan | Mulai bila `canStart` | POLLING | Pesan kelayakan terpisah |
| PROF-01 | `queued` | NEUTRAL | Menunggu diproses | Permukaan | Tidak ada primer | POLLING | Bukan warning |
| PROF-01 | `processing` | PROCESSING | Sedang dianalisis | Progress bila ada data | Tidak ada primer | POLLING | `aria-live` |
| PROF-01 | `ready` | SUCCESS / COMPLETED | Hasil analisis siap | Permukaan hasil | Ulang bila `canRegenerate` | POLLING | Bukan izin kisi-kisi otomatis |
| PROF-01 | `failed` | DANGER | Gagal | Kalimat | Ulang bila diizinkan | POLLING | |
| PROF-01 | `stale` | WARNING | Tidak sesuai konten terbaru | Permukaan | Bukan profil terkini | POLLING | |
| IMP-02 ekstraksi | `pending` | NEUTRAL | Menunggu ekstraksi | Permukaan pipa | Tidak ada konversi | POLLING | Pipa sendiri |
| IMP-02 ekstraksi | `processing` | PROCESSING | Sedang diekstraksi | Permukaan pipa | Tidak ada konversi | POLLING | |
| IMP-02 ekstraksi | `extracted` | SUCCESS / COMPLETED | Ekstraksi selesai | Permukaan pipa | Bukan izin draf | POLLING | |
| IMP-02 ekstraksi | `failed` | DANGER | Ekstraksi gagal | Kalimat | Tidak memulai interpretasi | POLLING | |
| IMP-02 interpretasi | kosong | NEUTRAL | Belum diinterpretasi | Permukaan pipa | Tidak ada konversi | POLLING | |
| IMP-02 interpretasi | `queued` | NEUTRAL | Menunggu diproses | Permukaan pipa | Tidak ada konversi | POLLING | |
| IMP-02 interpretasi | `processing` | PROCESSING | Sedang diproses | Permukaan pipa | Tidak ada konversi | POLLING | |
| IMP-02 interpretasi | `review_ready` | SUCCESS / COMPLETED | Siap ditinjau | Permukaan pipa | Cocokkan bila pencocokan masih kosong | POLLING | Bukan `ready` pencocokan |
| IMP-02 interpretasi | `failed` | DANGER | Interpretasi gagal | Kalimat | Retry bila `canRetry` | POLLING | |
| IMP-02 pencocokan | kosong | NEUTRAL | Belum dicocokkan | Permukaan pipa | Tidak ada konversi | POLLING | |
| IMP-02 pencocokan | `queued` | NEUTRAL | Menunggu diproses | Permukaan pipa | Tidak ada konversi | POLLING | |
| IMP-02 pencocokan | `processing` | PROCESSING | Sedang dicocokkan | Permukaan pipa | Tidak ada konversi | POLLING | |
| IMP-02 pencocokan | `ready` | SUCCESS / COMPLETED | Pencocokan selesai | Permukaan pipa | Konversi hanya bila syarat bagian 7 terpenuhi | POLLING | Bukan "siap membuat draf" |
| IMP-02 pencocokan | `failed` | DANGER | Pencocokan gagal | Kalimat | Retry grounding bila dirender | POLLING | |
| BP-03 AI | `queued` | NEUTRAL | Menunggu diproses | Badge + permukaan | Tidak konfirmasi | POLLING | |
| BP-03 AI | `processing` | PROCESSING | Sedang diproses | Badge + permukaan | Tidak konfirmasi | POLLING | |
| BP-03 AI | `succeeded` | SUCCESS / COMPLETED | Pengisian selesai | Tidak memaksa alert | Konfirmasi hanya bila bendera draf mengizinkan | POLLING | |
| BP-03 AI | `failed` | DANGER | Pengisian gagal | Alert galat | Retry bila dirender | POLLING | Pro tetap terpisah |
| GEN-03 | `queued` | NEUTRAL | Menunggu diproses | Permukaan | Tidak ada primer | POLLING | |
| GEN-03 | `processing` | PROCESSING | Sedang diproses | Permukaan | Tidak ada primer | POLLING | |
| GEN-03 | `completed` | SUCCESS / COMPLETED | Selesai | Hasil | Impor atau buka set | POLLING | `question-sets.import` |
| GEN-03 | `failed` | DANGER | Gagal | Kalimat | Retry bila diizinkan | POLLING | |
| GEN-03 | `cancelled` | NEUTRAL | Dibatalkan | Badge | Tidak ada primer | POLLING | Bukan danger |
| GEN-05 | `queued` | NEUTRAL | Menunggu diproses | Permukaan + anak | Tidak ada primer | POLLING | Bukan GEN-03 |
| GEN-05 | `processing` | PROCESSING | Sedang diproses | Permukaan + anak | Tidak ada primer | POLLING | |
| GEN-05 | `completed` | SUCCESS / COMPLETED | Selesai | Hasil | `question-sets.import-run` atau buka set | POLLING | |
| GEN-05 | `failed` | DANGER | Gagal | Kalimat | Retry hanya bila `$canRetry` | POLLING | Pro dapat memblokir |

## 17. Yang presentasi tidak boleh ubah

- Kelayakan profil, `canStart`, `canRegenerate`, dan `eligibilityMessage`.
- Kelayakan pencocokan dan konversi, termasuk indeks yang dapat dikonversi, pilihan kandidat, dan `created_blueprint_id`.
- Daur hidup draf dan terkonfirmasi.
- Gerbang Pro.
- Kredit dan kuota.
- Dua arsitektur generasi dan kedua rute simpan.
- Otorisasi coba lagi.
- Keputusan admin dan pembayaran.

Jika presentasi butuh perilaku lain: **SEPARATE PRODUCT/DOMAIN DECISION REQUIRED**.

## 18. Bukan keputusan dokumen ini

**OPEN QUESTION.**

- Endpoint status ekstraksi. MAT-04 tetap muat ulang manual.
- Locale runtime.
- Memensiunkan generasi lama.
- Penempatan entri admin.
- Menghapus `welcome.blade.php`.
