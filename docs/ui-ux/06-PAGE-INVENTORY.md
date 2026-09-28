# Inventaris halaman UI/UX Refresh V1

Status dokumen: **UI-0 Pass 3**. Ini kunci ID dan peta rute, bukan spesifikasi halaman. Spesifikasi ada nanti di `docs/ui-ux/07-PAGE-SPECIFICATIONS.md`.

| Label | Arti |
| --- | --- |
| CURRENT STATE | Fakta rute, controller, dan view pada baseline master plan. |
| TARGET STATE | Arah primer yang boleh dipakai spesifikasi, tanpa mengubah syarat domain. |
| LOCKED RULE | ID, jumlah halaman, dan pemisahan halaman versus endpoint. |
| OPEN QUESTION | Tidak diputuskan di inventaris ini. |

Sumber yang dicocokkan: `docs/ui-ux/00-UI-UX-MASTER-PLAN.md`, `routes/web.php`, `php artisan route:list`, controller yang `return view`, dan `resources/views/**/*.blade.php`.

**LOCKED RULE.** Tepat 27 ID. Jangan menambah atau menghapus ID di sini. `welcome.blade.php` bukan halaman: tidak ada rute yang mengembalikannya.

**LOCKED RULE.** Layout semua halaman produk: `resources/views/layouts/app.blade.php`. Tidak ada halaman produk yang memakai layout lain.

Audiens yang terbukti dari middleware `routes/web.php`:

- Publik: tanpa `auth`.
- Akun: `auth` + `account.active`.
- Aplikasi: `auth` + `account.active` + `profile.complete`.
- Admin: aplikasi + `role:admin`.

## 1. Tabel halaman

Sel aksi primer adalah arah per keadaan, bukan spesifikasi. Nol aksi primer sah. Rute pendamping ada di bagian 3.

Arti kolom Async:

- NONE: halaman tidak punya alur asinkron atau perilaku mengikuti keadaan yang membutuhkan umpan balik pengguna.
- MANUAL REFRESH: proses di server dapat berubah saat halaman terbuka, UI yang ada tidak punya endpoint polling, dan pengguna harus memuat ulang untuk melihatnya.
- POLLING: halaman mem-poll endpoint status yang sudah ada.
- STATEFUL BUT NOT POLLING: tampilan atau aksi bergantung pada daur hidup atau status yang tersimpan, tetapi halaman itu sendiri tidak mem-poll perubahan.

Jangan mengarang polling. Ekstraksi berkas materi tetap MANUAL REFRESH di MAT-04 karena tidak ada rute status JSON.

| ID | Area | Route Name | Method / URI | Controller / Action | View | Audience | Purpose | Primary Action / State | Phase | Responsive Risk | Copy Risk | Async | Domain Sensitivity |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| PUB-01 | Publik | `home` | GET `/` | closure `routes/web.php` | `home` | Publik; jika sudah masuk, tautan dasbor | Masuk ke produk | Tamu: Login dengan Google. Sudah masuk: Buka Dashboard | UI-2 | LOW | MEDIUM | NONE | LOW |
| AUTH-01 | Akun | `profile.setup` | GET `/profile/setup` | `ProfileSetupController@create` | `profile.setup` | Akun, profil belum lengkap | Isi nomor telepon | Simpan dan lanjutkan | UI-2 | LOW | LOW | NONE | MEDIUM |
| DASH-01 | Dasbor | `dashboard` | GET `/dashboard` | `DashboardController` | `dashboard` | Aplikasi | Orientasi pemilik | Saat ini tidak ada satu primer. Arah: satu langkah berikutnya. Isi langkah itu milik spesifikasi UI-3 | UI-3 | MEDIUM | HIGH | NONE | LOW |
| DASH-02 | Admin | `admin.dashboard` | GET `/admin/dashboard` | `Admin\DashboardController` | `admin.dashboard` | Admin | Dua hitungan dan jalan ke verifikasi | Di halaman: Verifikasi upgrade. Penempatan entri admin tetap OPEN | UI-3 | LOW | MEDIUM | NONE | MEDIUM |
| MAT-01 | Materi | `materials.index` | GET `/materials` | `MaterialController@index` | `materials.index` | Aplikasi | Daftar materi bukan arsip | Buat materi. Kosong: primer yang sama | UI-4 | HIGH | MEDIUM | STATEFUL BUT NOT POLLING | LOW |
| MAT-02 | Materi | `materials.archived` | GET `/materials/archived` | `MaterialController@archived` | `materials.index` | Aplikasi | Daftar arsip | Tidak ada aksi domain. Navigasi: Materi aktif | UI-4 | HIGH | MEDIUM | STATEFUL BUT NOT POLLING | LOW |
| MAT-03 | Materi | `materials.create` | GET `/materials/create` | `MaterialController@create` | `materials.create` | Aplikasi | Unggah PDF, DOCX, atau TXT | Unggah materi | UI-4 | LOW | LOW | NONE | MEDIUM |
| MAT-04 | Materi | `materials.show` | GET `/materials/{material}` | `MaterialController@show` | `materials.show` | Aplikasi, pemilik | Detail, ekstraksi, topik, jalan berikutnya | Saat ini beberapa aksi setara. Jangan menampilkan generasi bila `$canGenerate` false | UI-4 | HIGH | HIGH | MANUAL REFRESH | MEDIUM |
| MAT-05 | Materi | `materials.edit` | GET `/materials/{material}/edit` | `MaterialController@edit` | `materials.edit` | Aplikasi, pemilik | Ubah judul; konten hanya materi teks lama | Simpan perubahan | UI-4 | MEDIUM | LOW | NONE | MEDIUM |
| PROF-01 | Profil materi | `materials.profile.show` | GET `/materials/{material}/profile` | `MaterialProfileController@show` | `materials.profile.show` | Aplikasi, pemilik | Analisis profil pedagogis | `none` + boleh mulai: Mulai analisis. Boleh ulang: Jalankan analisis baru. In-flight atau tidak layak: tidak ada primer domain | UI-4 | MEDIUM | LOW | POLLING | HIGH |
| BP-01 | Kisi-kisi | `materials.blueprints.index` | GET `/materials/{material}/blueprints` | `QuestionBlueprintController@index` | `blueprints.index` | Aplikasi, pemilik | Pilih manual, AI, atau DOCX, plus daftar | Tidak ada satu primer. Tiga jalur tetap ada. Profil belum siap menghalangi buat/konfirmasi | UI-5 | HIGH | LOW | STATEFUL BUT NOT POLLING | MEDIUM |
| BP-02 | Kisi-kisi | `materials.blueprints.create` | GET `/materials/{material}/blueprints/create` | `QuestionBlueprintController@create` | `blueprints.create` | Aplikasi, pemilik | Susun draf manual | Simpan draf | UI-5 | HIGH | LOW | NONE | HIGH |
| BP-03 | Kisi-kisi | `materials.blueprints.show` | GET `/materials/{material}/blueprints/{blueprint}` | `QuestionBlueprintController@show` | `blueprints.show` | Aplikasi, pemilik | Ubah draf atau bertindak pada kisi-kisi terkonfirmasi | Draf boleh diubah: Konfirmasi. Terkonfirmasi dan Pro mengizinkan: mulai generasi kisi-kisi. AI in-flight: tidak ada primer domain. Gagal AI: coba lagi hanya bila aksi itu sudah ada | UI-5 | HIGH | LOW | POLLING | HIGH |
| IMP-01 | Impor DOCX | `materials.blueprint-imports.index` | GET `/materials/{material}/blueprint-imports` | `QuestionBlueprintImportController@index` | `materials.blueprint-imports.index` | Aplikasi, pemilik | Riwayat impor | Tidak ada primer domain. Buka tinjauan | UI-4 | HIGH | LOW | STATEFUL BUT NOT POLLING | MEDIUM |
| IMP-02 | Impor DOCX | `materials.blueprint-imports.show` | GET `/materials/{material}/blueprint-imports/{import}` | `QuestionBlueprintImportController@show` | `materials.blueprint-imports.show` | Aplikasi, pemilik | Ekstraksi, interpretasi, pencocokan, tinjauan, konversi bila sah | Lihat bagian 2. Selesai proses bukan izin konversi | UI-4 | HIGH | LOW | POLLING | HIGH |
| GEN-01 | Generasi lama | `generations.index` | GET `/generations` | `GenerationController@index` | `generations.index` | Aplikasi | Riwayat generasi materi | Kosong: Pilih materi. Ada data: buka satu baris, bukan aksi domain baru | UI-5 | HIGH | HIGH | STATEFUL BUT NOT POLLING | MEDIUM |
| GEN-02 | Generasi lama | `generations.create` | GET `/materials/{material}/generations/create` | `GenerationController@create` | `generations.create` | Aplikasi, pemilik | Mulai generasi dari materi | Mulai generate, hanya bila form ini memang dirender | UI-5 | MEDIUM | HIGH | NONE | HIGH |
| GEN-03 | Generasi lama | `generations.show` | GET `/generations/{generation}` | `GenerationController@show` | `generations.show` | Aplikasi, pemilik | Status dan hasil generasi lama | Belum terminal: tidak ada primer. Gagal dan boleh ulang: Coba lagi. Selesai belum disimpan: Simpan ke bank soal lewat `question-sets.import`. Sudah disimpan: buka set | UI-5 | MEDIUM | HIGH | POLLING | HIGH |
| GEN-04 | Generasi kisi-kisi | `generation-runs.create` | GET `/materials/{material}/blueprints/{blueprint}/generation-runs/create` | `GenerationRunController@create` | `generation-runs.create` | Aplikasi, pemilik | Mulai generasi dari kisi-kisi terkonfirmasi | Mulai generasi hanya bila `$canStart`. Jika tidak: tidak ada primer | UI-5 | MEDIUM | MEDIUM | NONE | HIGH |
| GEN-05 | Generasi kisi-kisi | `generation-runs.show` | GET `/generation-runs/{generationRun}` | `GenerationRunController@show` | `generation-runs.show` | Aplikasi, pemilik | Status dan hasil generation run | Sama pola GEN-03, tetapi simpan lewat `question-sets.import-run`. Ulang boleh diblokir Pro | UI-5 | MEDIUM | HIGH | POLLING | HIGH |
| QB-01 | Bank soal | `question-sets.index` | GET `/question-sets` | `QuestionSetController@index` | `question-sets.index` | Aplikasi | Daftar set | Tidak ada primer domain. Buka satu set | UI-6 | HIGH | HIGH | STATEFUL BUT NOT POLLING | LOW |
| QB-02 | Bank soal | `question-sets.show` | GET `/question-sets/{questionSet}` | `QuestionSetController@show` | `question-sets.show` | Aplikasi, pemilik | Baca, terbitkan, atau unduh | Draf: Terbitkan. Terbit: tidak ada satu primer; dua unduhan setara | UI-6 | MEDIUM | HIGH | STATEFUL BUT NOT POLLING | HIGH |
| QB-03 | Bank soal | `question-sets.edit` | GET `/question-sets/{questionSet}/edit` | `QuestionSetController@edit` | `question-sets.edit` | Aplikasi, pemilik draf | Ubah draf | Simpan | UI-6 | HIGH | MEDIUM | NONE | HIGH |
| ACC-01 | Langganan | `account.subscription.show` | GET `/account/subscription` | `Account\SubscriptionController@show` | `account.subscription.show` | Aplikasi | Paket, kuota, QRIS, konfirmasi | Konfirmasi pembayaran hanya bila checkout yang ada mengizinkannya. Permintaan tertunda tidak membuka checkout kedua | UI-6 | MEDIUM | HIGH | STATEFUL BUT NOT POLLING | HIGH |
| ACC-02 | Langganan | `account.subscription.show` | GET `/account/subscription` | `Account\SubscriptionController@show` | `account.subscription.unavailable` | Aplikasi | Data langganan tidak dapat ditampilkan | Tidak ada | UI-6 | LOW | LOW | NONE | MEDIUM |
| ADM-01 | Admin | `admin.subscription-upgrades.index` | GET `/admin/subscription-upgrades` | `Admin\SubscriptionUpgradeController@index` | `admin.subscription-upgrades.index` | Admin | Saring dan buka permintaan | Tidak ada primer domain. Buka satu referensi | UI-6 | HIGH | HIGH | STATEFUL BUT NOT POLLING | MEDIUM |
| ADM-02 | Admin | `admin.subscription-upgrades.show` | GET `/admin/subscription-upgrades/{upgradeRequest}` | `Admin\SubscriptionUpgradeController@show` | `admin.subscription-upgrades.show` | Admin | Putuskan permintaan tertunda | Tertunda: Setujui. Tolak dan batalkan tetap sekunder/destruktif. Bukan tertunda: tidak ada primer | UI-6 | MEDIUM | HIGH | STATEFUL BUT NOT POLLING | HIGH |

View di atas adalah nama Blade. Berkasnya `resources/views/` dengan titik menjadi direktori, akhir `.blade.php`. MAT-01 dan MAT-02 berbagi `resources/views/materials/index.blade.php`. ACC-01 dan ACC-02 berbagi URI, bercabang di controller.

## 2. Mode yang terverifikasi

Bukan ID baru.

- MAT-01 / MAT-02: satu view. `archived` false menolak `MaterialStatus::ARCHIVED`. `archived` true hanya status arsip.
- MAT-04: ekstraksi `pending`, `processing`, `completed`, `failed`, `not_required`. `pending` dan `processing` meminta muat ulang manual. Tidak ada rute JSON ekstraksi.
- MAT-05: konten teks hanya bila materi itu teks lama. Materi unggahan tidak mendapat editor konten di view ini.
- PROF-01: `none`, `queued`, `processing`, `ready`, `failed`, `stale`. Mulai hanya bila `canStart`. Ulang hanya bila `canRegenerate`. Pesan tidak layak tetap terlihat. In-flight mem-poll `materials.profile.status`.
- BP-01: pembuatan tertahan bila profil siap tidak ada. Mode AI `advanced` terkunci tanpa Pro.
- BP-02: draf manual baru.
- BP-03: `draft` versus `confirmed`. Isi AI `queued` atau `processing` mematikan sunting dan mem-poll `materials.blueprints.status`. `failed` punya coba lagi bila mutasi lanjutan diizinkan. Kisi-kisi lanjutan tanpa Pro dapat dilihat tetapi tidak diubah, dikonfirmasi, disalin, atau dipakai generasi baru.
- IMP-01: menampilkan label ekstraksi, interpretasi, dan pencocokan. Tidak mem-poll.
- IMP-02: tiga jalur terpisah. Ekstraksi `pending`, `processing`, `extracted`, `failed`. Interpretasi kosong, `queued`, `processing`, `review_ready`, `failed`. Pencocokan kosong, `queued`, `processing`, `ready`, `failed`. In-flight mem-poll `materials.blueprint-imports.status`. `review_ready` dengan pencocokan kosong menampilkan cocokkan, bukan konversi. `ready` sendiri tidak membuka konversi. Formulir konversi hanya bila pencocokan `ready`, `$convertibleIndexes` tidak kosong, dan `created_blueprint_id` masih kosong. Jika draf sudah dibuat, primer keadaan itu adalah buka draf.
- GEN-01: status `queued`, `processing`, `completed`, `failed`, `cancelled` ditampilkan, sering beserta nilai mentah.
- GEN-03: sama, plus terminal versus belum. Belum terminal mem-poll `generations.status`. Selesai bisa sudah punya question set atau belum.
- GEN-04: `$canStart` false menampilkan blok Pro, bukan form mulai.
- GEN-05: status run belum tentu terminal. Belum terminal mem-poll `generation-runs.status`. Selesai menyimpan lewat `question-sets.import-run`, bukan `question-sets.import`. Gagal dapat diblokir ulang bila Pro tidak aktif.
- QB-01 / QB-02: label `draft`, `generating`, `review`, `published`, `archived`. Aksi yang terbukti di QB-02: draf boleh edit dan terbitkan; terbit boleh dua unduhan. Jangan mengarang aksi untuk status lain.
- ACC-01: view `show` bila `BuildSubscriptionPage` berhasil. Di dalamnya checkout, permintaan tertunda, perpanjangan terantre, dan riwayat adalah keadaan data, bukan ID baru.
- ACC-02: view `unavailable` bila exception integritas langganan yang ditangkap controller. Bukan halaman checkout kosong.
- ADM-01: saringan kueri `pending`, `approved`, `rejected`, `cancelled`, `all`. Nilai kueri tetap.
- ADM-02: aksi hanya saat status permintaan `pending`.

## 3. Rute pendamping

Bukan halaman.

| ID | Pendamping |
| --- | --- |
| PUB-01 | `login` hanya redirect. Bukan view login |
| AUTH-01 | POST `profile.setup.store` |
| DASH-01 | tidak ada |
| DASH-02 | tidak ada mutasi |
| MAT-01 | tidak ada |
| MAT-02 | tidak ada |
| MAT-03 | POST `materials.store-upload` |
| MAT-04 | PATCH `materials.update`; POST `materials.archive`, `materials.restore`; topik POST `materials.topics.store`, PATCH `materials.topics.update`, DELETE `materials.topics.destroy` |
| MAT-05 | PATCH `materials.update` |
| PROF-01 | GET `materials.profile.status`; POST `materials.profile.store`, `materials.profile.regenerate` |
| BP-01 | POST `materials.blueprints.ai`; POST `materials.blueprint-imports.store` |
| BP-02 | POST `materials.blueprints.store` |
| BP-03 | PATCH `materials.blueprints.update`; POST `materials.blueprints.confirm`, `materials.blueprints.clone`, `materials.blueprints.retry-ai`; GET `materials.blueprints.status`, `materials.blueprints.download` |
| IMP-01 | tidak ada mutasi di daftar |
| IMP-02 | GET `materials.blueprint-imports.status`; POST `materials.blueprint-imports.retry`, `materials.blueprint-imports.ground`, `materials.blueprint-imports.retry-grounding`, `materials.blueprint-imports.convert` |
| GEN-01 | tidak ada |
| GEN-02 | POST `generations.store` |
| GEN-03 | GET `generations.status`; POST `generations.retry`; POST `question-sets.import` |
| GEN-04 | POST `generation-runs.store` |
| GEN-05 | GET `generation-runs.status`; POST `generation-runs.retry`; POST `question-sets.import-run` |
| QB-01 | tidak ada |
| QB-02 | POST `question-sets.publish`; GET `question-sets.download-student`, `question-sets.download-teacher` |
| QB-03 | PATCH `question-sets.update` |
| ACC-01 | POST `account.subscription.confirm` |
| ACC-02 | tidak ada |
| ADM-01 | tidak ada |
| ADM-02 | POST `admin.subscription-upgrades.approve`, `admin.subscription-upgrades.reject`, `admin.subscription-upgrades.cancel` |

## 4. Perjalanan

| Perjalanan | Status | Bukti |
| --- | --- | --- |
| Masuk publik PUB-01 | VERIFIED | `home` |
| Autentikasi Google | VERIFIED | `login` redirect, `auth.google.redirect`, `auth.google.callback`. Bukan halaman |
| Registrasi email/kata sandi | NOT FOUND | Tidak ada rute atau view |
| Pulihkan atau reset kata sandi | NOT FOUND | Tidak ada rute atau view |
| Penyiapan profil bila belum lengkap | VERIFIED | AUTH-01, sebelum `profile.complete` |
| Dasbor | VERIFIED | DASH-01 |
| Materi | VERIFIED | MAT-01 sampai MAT-05 |
| Profil materi | VERIFIED | PROF-01 |
| Pilihan kisi-kisi | VERIFIED | BP-01 |
| Kisi-kisi manual | VERIFIED | BP-02 lalu BP-03 |
| Kisi-kisi AI | VERIFIED | POST di BP-01, keadaan di BP-03 |
| DOCX | VERIFIED | unggah di BP-01, IMP-01, IMP-02 |
| Pencocokan dan tinjauan DOCX | VERIFIED | IMP-02. Interpretasi bukan pencocokan. `ready` bukan izin konversi |
| Draf kisi-kisi | VERIFIED | BP-02 / BP-03 / konversi IMP-02 |
| Konfirmasi kisi-kisi | VERIFIED | POST `materials.blueprints.confirm` pada draf yang boleh diubah |
| Generasi dari kisi-kisi | VERIFIED | GEN-04, GEN-05 |
| Hasil lalu bank soal | VERIFIED | simpan lalu QB-01 sampai QB-03 |
| Generasi materi lama | VERIFIED | Jalur terpisah: MAT-04 bila `$canGenerate`, GEN-02, GEN-03, simpan `question-sets.import` |
| Langganan | VERIFIED | ACC-01 atau ACC-02 |
| Tinjauan admin | VERIFIED | DASH-02, ADM-01, ADM-02. DASH-02 tidak ditaut dari shell |

## 5. Kepemilikan fase

Cocok dengan master plan. UI-1 memiliki shell bersama, bukan isi halaman. UI-7 menyapu semua ID, bukan pemilik utama.

| Fase | ID |
| --- | --- |
| UI-2 | PUB-01, AUTH-01 |
| UI-3 | DASH-01, DASH-02 |
| UI-4 | MAT-01, MAT-02, MAT-03, MAT-04, MAT-05, PROF-01, IMP-01, IMP-02 |
| UI-5 | BP-01, BP-02, BP-03, GEN-01, GEN-02, GEN-03, GEN-04, GEN-05 |
| UI-6 | QB-01, QB-02, QB-03, ACC-01, ACC-02, ADM-01, ADM-02 |

GEN-01 sampai GEN-03 tetap `GenerationController`. GEN-04 dan GEN-05 tetap `GenerationRunController`. Jangan digabung.

## 6. Halaman yang peka domain

UI nanti hanya menyajikan keadaan yang server sudah kirim.

- PROF-01: kelayakan mulai dan ulang, serta beda `ready`, `failed`, dan `stale`. Jangan menampilkan hasil basi sebagai profil terkini.
- IMP-02: interpretasi `review_ready` bukan pencocokan. Pencocokan `ready` bukan izin konversi. Syarat indeks yang dapat dikonversi, pilihan kandidat, dan belum adanya `created_blueprint_id` tetap otoritatif.
- BP-03: beda draf dan terkonfirmasi. Isi AI yang masih berjalan bukan draf yang boleh dikonfirmasi. Gerbang Pro tetap menutup mutasi lanjutan.
- GEN-03 dan GEN-05: beda belum terminal, gagal, selesai, dan sudah disimpan. Ulang dan simpan tetap pada rute produk masing-masing.
- GEN-02 dan GEN-04: kuota dan kredit ditampilkan dari perhitungan yang ada. GEN-04 tidak mulai bila `$canStart` false.
- ACC-01: keadaan pembayaran, permintaan tertunda, dan angka kuota tidak dihitung ulang di tampilan.
- ACC-02: tidak ada checkout.
- ADM-02: setujui, tolak, dan batalkan hanya untuk permintaan yang controller sudah izinkan. `role:admin` tidak dilonggarkan. Tidak ada kemampuan admin baru.

## 7. Bukan halaman

| Kelompok | Rute |
| --- | --- |
| Status JSON | `materials.profile.status`, `materials.blueprints.status`, `materials.blueprint-imports.status`, `generations.status`, `generation-runs.status` |
| Mutasi | Semua POST, PATCH, dan DELETE pada bagian 3. Termasuk topik dan keputusan admin |
| Unduhan | `materials.blueprints.download`, `question-sets.download-student`, `question-sets.download-teacher` |
| Auth / redirect | `login` menuju `auth.google.redirect`; `auth.google.redirect`; `auth.google.callback`; POST `logout` |
| Sistem | GET `/up`; `storage.local` GET dan PUT |

Tidak ada rute JSON status ekstraksi berkas materi. Jangan mengarang ID halaman untuk endpoint di atas.

## 8. Bukan keputusan inventaris

**OPEN QUESTION.**

- Memensiunkan generasi materi lama. Saat ini GEN-01 sampai GEN-03 tetap hidup di samping GEN-04 dan GEN-05.
- Menambah endpoint status ekstraksi. MAT-04 tetap muat ulang manual.
- Mengubah locale runtime.
- Menghapus `welcome.blade.php`.
- Di mana entri admin diletakkan. DASH-02 saat ini hanya tercapai lewat URL atau dari dalam area admin, bukan dari navbar.
