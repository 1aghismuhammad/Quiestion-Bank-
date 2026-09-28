# Token desain UI/UX Refresh V1

Status dokumen: **UI-0 Pass 2**. Ini spesifikasi token, bukan implementasi CSS.

**LOCKED RULE.** Nilai pada `resources/views/layouts/app.blade.php` adalah CURRENT VALUE. Nilai itu bukan TARGET TOKEN. Kemiripan angka pada tabel di bawah adalah pilihan, bukan pengesahan otomatis.

Prinsip pemakaian ada di `docs/ui-ux/01-DESIGN-PRINCIPLES.md`. Guardrail domain ada di `docs/ui-ux/11-IMPLEMENTATION-GUARDRAILS.md`.

| Label | Arti |
| --- | --- |
| CURRENT VALUE | Yang benar-benar dipakai permukaan produk hari ini, atau ketiadaan token. |
| TARGET TOKEN | Nama yang nanti dipetakan ke tema. |
| TARGET VALUE | Nilai yang dipakai implementasi setelah dokumen ini disetujui. |
| OPEN QUESTION | Tidak diputuskan di pass ini. |

## 1. Bukti current

**CURRENT STATE.** Design system yang hidup adalah `<style>` di `resources/views/layouts/app.blade.php`. Layout tidak memuat `@vite`. `resources/css/app.css` hanya berisi `@import 'tailwindcss'` dan `@theme { --font-sans: 'Instrument Sans', ... }`. Berkas itu tidak dipakai halaman produk.

Tidak ada `tailwind.config.js`. Tailwind yang terpasang adalah v4 dengan konfigurasi CSS. Jangan membuat `tailwind.config.js` hanya karena Tailwind lama memakainya.

Cuplikan current yang menjadi bukti, bukan target:

| Peran hidup | Current value | Sumber |
| --- | --- | --- |
| Latar halaman | `#f4f7fb` | `:root` layout |
| Teks | `#172033` | `:root` layout |
| Teks redup | `#667085` | `.muted` |
| Permukaan kartu dan nav | `#ffffff` | `.card`, `.nav` |
| Garis | `#dce3ee` | `.nav`, `.card`, `.table` |
| Garis field | `#bac5d6` | `.input` |
| Merek | `#2356d8` | `.button`, outline fokus BP-01 |
| Sekunder | `#e9eef8` / teks `#172033` | `.button-secondary`, `.status-muted` |
| Bahaya tombol | `#b42318` | `.button-danger` |
| Sukses | teks `#155e3b` / latar `#e8f8ef` | `.alert-success`, `.status` |
| Peringatan | teks `#7a5400` / latar `#fff4d6` | `.status-warn` |
| Bahaya permukaan | teks `#9f2424` / latar `#fdecec` | `.alert-error`, `.status-error` |
| Galat field | `#b42318` | `.error-text` |
| Radius kartu | `14px` | `.card` |
| Radius kontrol | `9px` | `.button`, `.input`, `.alert` |
| Radius status | `999px` | `.status` |
| Bayangan | tidak ada | layout |
| Fokus | tidak ada di layout; `outline: 2px solid #2356d8` hanya pemilih berkas BP-01 | `blueprints/index.blade.php` |
| Lebar aplikasi | `min(1080px, calc(100% - 32px))` | `.container` |
| Padding halaman | `40px 0` | `.page` |
| Tinggi tombol | `min-height: 42px`, padding horizontal `18px` | `.button` |
| Tinggi field | `min-height: 44px` | `.input` |
| Font disebut | `Inter, ui-sans-serif, system-ui, sans-serif` | layout, tidak dimuat |
| Font tema Tailwind | `Instrument Sans` | `app.css`, tidak dipakai layout |

Halaman juga membawa nilai sekali pakai: PUB-01 `max-width: 720px` dan padding `48px`; AUTH-01 membatasi kartu sekitar `560px`. Itu current, bukan skala kontainer target.

## 2. Warna

Palet target sengaja pendek. Tidak ada tangga 50–950. Kontras akhir diverifikasi pada audit browser UI-1, bukan dinyatakan lulus dari dokumen ini.

| Role | Current value | Target token | Target value | Rationale |
| --- | --- | --- | --- | --- |
| Latar halaman | `#f4f7fb` | `--color-background` | `#f4f5f7` | Netral. Current terlalu biru untuk bidang besar. |
| Permukaan | `#ffffff` | `--color-surface` | `#ffffff` | Panel, nav, dan dialog. |
| Permukaan sunyi | `#f4f7fb` dipakai ulang `.content-block` | `--color-surface-subtle` | `#eef0f3` | Kelompok sunyi di dalam panel, bukan kartu kedua. |
| Garis | `#dce3ee` | `--color-border` | `#e1e4ea` | Pemisah netral. |
| Garis kuat | `#bac5d6` | `--color-border-strong` | `#c5cad3` | Field dan pemisah yang harus terlihat. |
| Teks | `#172033` | `--color-text` | `#1c2430` | Teks utama di latar dan permukaan. |
| Teks pendukung | `#667085` | `--color-text-muted` | `#4e5968` | Lebih gelap daripada current agar tidak menggantung pada pengukuran yang belum dilakukan. Bukan untuk syarat kelayakan. |
| Merek | `#2356d8` | `--color-brand` | `#1f4e8c` | Biru tenang untuk primer, tautan, dan fokus. Bukan latar halaman. |
| Merek hover | tidak ada | `--color-brand-hover` | `#1a4378` | Hanya keadaan hover primer dan tautan. |
| Merek sunyi | `#e9eef8` (saat ini malah menjadi tombol sekunder) | `--color-brand-subtle` | `#e7eef6` | Penekanan terpilih yang jarang. Bukan isi tombol sekunder. |
| Sukses | `#155e3b` / `#e8f8ef` | `--color-success` / `--color-success-subtle` | `#14643d` / `#e7f5ee` | Teks status atau alert di atas latar sunyi. |
| Peringatan | `#7a5400` / `#fff4d6` | `--color-warning` / `--color-warning-subtle` | `#7a5200` / `#fff4dc` | Basi, perlu perhatian, atau kondisi yang tidak biasa dan dapat menghalangi atau meminta tindakan. Bukan menunggu biasa. |
| Bahaya | `#9f2424` / `#fdecec`; tombol `#b42318` | `--color-danger` / `--color-danger-subtle` | `#9f2424` / `#fdecec` | Kegagalan, galat, dan aksi destruktif. Satu bahaya, bukan dua merah. |
| Informasi | tidak ada token; `.status-muted` memakai biru sekunder | `--color-info` / `--color-info-subtle` | `#1e4a73` / `#e8f0f7` | Petunjuk atau informasi yang perlu diketahui. Bukan peringatan proses secara otomatis. |
| Cincin fokus | `#2356d8` hanya di BP-01 | `--color-focus-ring` | `#1f4e8c` | Sama dengan merek agar fokus dan aksi satu keluarga. |

**TARGET STATE pemakaian.**

- Tombol sekunder: permukaan plus `--color-border-strong`, teks `--color-text`. Bukan `--color-brand-subtle`.
- Tautan teks: `--color-brand`.
- Status netral memakai teks `--color-text` di atas `--color-surface-subtle`. Termasuk menunggu biasa, `queued`, `pending`, belum mulai, dan keadaan normal yang bukan masalah.
- `--color-info` untuk petunjuk atau informasi yang perlu diketahui. Bukan peringatan proses secara otomatis.
- `--color-warning` untuk basi, perlu perhatian, kondisi yang tidak biasa, atau kondisi yang dapat menghalangi atau meminta tindakan pengguna.
- `--color-danger` untuk kegagalan, galat, dan aksi destruktif.
- Jangan menggradasi token di atas.

**LOCKED RULE.** `queued`, `pending`, dan menunggu biasa tidak otomatis memakai gaya peringatan. Pemetaan akhir tiap status ke permukaan ada nanti di `docs/ui-ux/08-STATE-AND-FEEDBACK-SYSTEM.md`. Dokumen ini hanya mengunci makna warna.

## 3. Tipografi

**CURRENT STATE.** Dua arah font hidup dan keduanya tidak benar-benar tampil sebagai wajah produk: layout menulis `Inter` tanpa memuat berkasnya; `app.css` menulis `Instrument Sans` tanpa dipakai layout. `h1` tidak diberi ukuran. Bobot 750 dan 800 muncul pada merek dan `.stat`.

**LOCKED RULE.** Satu arah target: sans sistem. Jangan memuat Inter. Jangan mempromosikan Instrument Sans dari stylesheet starter.

| Role | Current value | Target token | Target value | Rationale |
| --- | --- | --- | --- | --- |
| Keluarga | `Inter, ui-sans-serif, system-ui, sans-serif` | `--font-sans` | `ui-sans-serif, system-ui, "Segoe UI", sans-serif` | Satu tumpukan. Tidak menambah dependensi font pada pass ini atau pada UI-1 kecuali Council mengganti arah ini. |
| Isi | tidak disetel (bawaan peramban) | `--font-size-body` | `1rem`; tinggi `1.5`; bobot `400` | Ukuran baca. |
| Judul halaman | tidak disetel | `--font-size-page-title` | `1.75rem`; tinggi `1.25`; bobot `600` | Satu `h1`. |
| Judul bagian | tidak disetel | `--font-size-section` | `1.25rem`; tinggi `1.3`; bobot `600` | `h2`. |
| Judul panel | tidak disetel | `--font-size-panel` | `1.125rem`; tinggi `1.35`; bobot `600` | `h3` di dalam panel. |
| Teks pendukung | `.muted` tanpa ukuran | `--font-size-supporting` | `0.875rem`; tinggi `1.45`; bobot `400` | Metadata dan bantuan. |
| Label | `14px` / `700` | `--font-size-label` | `0.875rem`; tinggi `1.35`; bobot `600` | Label field. |
| Keterangan | status `13px` / `700` | `--font-size-caption` | `0.8125rem`; tinggi `1.4`; bobot `400` | Bukan label. |
| Eyebrow | huruf besar tanpa token | `--font-size-eyebrow` | `0.75rem`; tinggi `1.3`; bobot `600` | Hanya bila spesifikasi halaman mengizinkan eyebrow. |

**TARGET STATE.** Skala berhenti di situ. Tidak ada ukuran display untuk metrik. `.stat` saat ini `34px` / bobot `800` tidak menjadi token.

**OPEN QUESTION.** Mengganti arah ini dengan font yang di-host adalah keputusan Council baru, bukan cabang diam-diam di UI-1. Sampai itu terjadi, hanya tumpukan di atas yang sah.

## 4. Spasi

**CURRENT STATE.** Tidak ada skala. Nilai yang terlihat antara lain 6, 8, 10, 12, 14, 16, 18, 20, 24, 32, 40, dan 48 px.

**TARGET STATE.** Hanya langkah berikut.

| Token | Target value | Pemakaian |
| --- | --- | --- |
| `--space-1` | `4px` | Jarak ikon ke label, bila ikon nanti ada. |
| `--space-2` | `8px` | Label ke kontrol, antaraksi yang membungkus, galat ke field. |
| `--space-3` | `12px` | Antarfield di dalam satu kelompok. |
| `--space-4` | `16px` | Padding kontrol, padding sel, gutter sempit. |
| `--space-5` | `20px` | Jarak kelompok kecil di dalam panel. |
| `--space-6` | `24px` | Padding panel. |
| `--space-8` | `32px` | Antarpanel. |
| `--space-10` | `40px` | Jarak judul halaman ke isi pada lebar longgar. Pada lebar sempit boleh turun ke `--space-6`. |
| `--space-12` | `48px` | Hanya pemisah wilayah besar yang langka. Bukan padding default kartu. |
| `--space-16` | `64px` | Hanya shell atau halaman kosong yang memang butuh satu napas. Bukan PUB-01 secara otomatis. |

Jangan menambah 6, 10, 14, atau 18 px sebagai token. Padding current `10px 12px` pada input menjadi `--space-2` vertikal dan `--space-3` horizontal hanya bila tinggi kontrol tetap memenuhi bagian 10. Utamakan tinggi kontrol, bukan menyalin padding lama.

## 5. Radius

**CURRENT STATE.** `14px` kartu, `9px` kontrol dan alert, `999px` status. Tidak ada peran yang dinamai.

| Role | Current value | Target token | Target value | Rationale |
| --- | --- | --- | --- | --- |
| Kecil | `9px` dicampur untuk kontrol dan alert | `--radius-sm` | `6px` | Field, tombol, alert, badge yang bukan pil. |
| Sedang | tidak ada | `--radius-md` | `8px` | Panel. |
| Besar | `14px` | `--radius-lg` | `12px` | Permukaan yang memang berdiri sendiri, jarang. |
| Penuh | `999px` | `--radius-full` | `999px` | Hanya pil status. |

**LOCKED RULE.** Jangan memakai radius di luar empat token ini. Kartu bersarang tidak diselesaikan dengan radius yang lebih kecil.

## 6. Garis

| Role | Current value | Target token | Target value | Rationale |
| --- | --- | --- | --- | --- |
| Biasa | `1px solid #dce3ee` | `--border-default` | `1px solid var(--color-border)` | Nav, tabel, pemisah bagian. |
| Kuat | `1px solid #bac5d6` | `--border-strong` | `1px solid var(--color-border-strong)` | Field dan tombol sekunder. |

Tidak ada garis putus-putus hias. Pemisah tabel memakai garis biasa, bukan permukaan baru.

## 7. Bayangan

**CURRENT STATE.** Tidak ada bayangan.

| Role | Current value | Target token | Target value | Rationale |
| --- | --- | --- | --- | --- |
| Tidak ada | tidak ada | `--shadow-none` | `none` | Default panel, kartu, dan halaman. |
| Halus | tidak ada | `--shadow-subtle` | `0 1px 2px rgb(28 36 48 / 0.06)` | Hanya nav lengket bila spesifikasi shell memang menempelkannya. |
| Overlay | tidak ada | `--shadow-overlay` | `0 8px 24px rgb(28 36 48 / 0.12)` | Hanya dialog atau lapisan di atas halaman. |

**LOCKED RULE.** Panel biasa tidak memakai bayangan. Jangan menambah bayangan besar untuk kesan "kartu SaaS".

## 8. Kontainer

| Role | Current value | Target token | Target value | Rationale |
| --- | --- | --- | --- | --- |
| Lebar aplikasi | `1080px` dengan gutter `16px` di dalam `min()` | `--container-app` | `72rem` (`1152px`) | Muat tabel kerja tanpa menjadi bidang tak bertepi di 1440 px. |
| Padding horizontal | ikut perhitungan `100% - 32px` | `--page-padding-inline` | `16px` di bawah `48rem`; `24px` mulai `48rem` | Gutter tidak terkunci pada satu lebar desktop. |
| Lebar formulir | kartu `560px` di AUTH-01, tidak bertoken | `--container-form` | `40rem` | Formulir satu kolom dan penyiapan profil. |
| Lebar baca | `720px` di PUB-01, tidak bertoken | `--container-reading` | `40rem` | Paragraf panjang, misalnya penjelasan profil. Bukan setiap halaman. |

**TARGET STATE.** Isi aplikasi berada di `--container-app` dan tetap menyusut di bawah itu. PUB-01 boleh memakai lebar baca. Halaman alat (materi, kisi-kisi, impor, generasi, bank soal, admin) memakai lebar aplikasi.

## 9. Fokus

| Role | Current value | Target token | Target value | Rationale |
| --- | --- | --- | --- | --- |
| Cincin | tidak ada di layout | `--focus-ring` | `0 0 0 2px var(--color-surface), 0 0 0 4px var(--color-focus-ring)` | Terlihat di atas latar dan di atas permukaan. |

**TARGET STATE.** Terapkan pada tautan, tombol, input, select, textarea, dan kontrol yang dapat difokuskan. Jangan mengandalkan outline sekali pakai di BP-01. Jangan menghapus cincin dengan `outline: none` tanpa pengganti ini.

## 10. Ukuran kontrol

| Role | Current value | Target token | Target value | Rationale |
| --- | --- | --- | --- | --- |
| Tinggi field | `44px` | `--control-height` | `44px` | Input, select, dan tombol standar. |
| Tinggi padat | tidak ada | `--control-height-compact` | `36px` | Hanya bila spesifikasi halaman membenarkan tampilan padat di desktop. Di konteks sentuh atau sempit, area pukul interaktifnya sendiri yang mencapai kira-kira `44px`, lewat padding atau pembungkus yang dapat diklik. Jarak di sekeliling kontrol memisahkan, tetapi tidak memperbesar area pukul. |
| Tombol standar | `42px` | memakai `--control-height` | `44px` | Tidak perlu tinggi visual yang berbeda dari field. |
| Area teks | `min-height: 180px` | `--control-textarea-min` | `8rem` | Lebih pendek daripada current agar formulir soal tidak memaksa tinggi hias. Spesifikasi halaman boleh menaikkan untuk konten materi. |

**LOCKED RULE.** Kontrol padat `36px` hanya bila spesifikasi halaman membenarkan kerapatan desktop. Di konteks sentuh atau sempit, area pukul interaktifnya sendiri harus mencapai kira-kira `44px`. Itu dicapai dengan padding pada kontrol atau pembungkus yang dapat diklik. Jarak atau gap di sekeliling kontrol bukan pengganti ukuran area pukul. Jangan menurunkan field di bawah `--control-height`. Jangan memaksa setiap tombol visual tepat `44px` bila area pukulnya sendiri sudah memenuhi arah itu.

## 11. Tumpukan

**CURRENT STATE.** Tidak ada skala `z-index`. Nav tidak lengket. Tidak ada dialog buatan.

| Role | Current value | Target token | Target value | Rationale |
| --- | --- | --- | --- | --- |
| Dasar | auto | `--z-base` | `0` | Isi. |
| Lengket | tidak dipakai | `--z-sticky` | `10` | Hanya bila shell menempel. |
| Lapisan | tidak ada | `--z-overlay` | `20` | Penutup dialog. |
| Dialog | `window.confirm` peramban | `--z-dialog` | `30` | Dialog buatan, bila spesifikasi nanti mengganti `confirm`. |

**LOCKED RULE.** Jangan menambah tingkat di luar empat ini pada UI-1. `window.confirm` tetap sah sampai spesifikasi halaman mengubahnya.

## 12. Arah pemetaan Tailwind v4

**TARGET STATE.** Implementasi nanti, bukan pass ini.

- Warna dan `--font-sans` ditulis di `resources/css/app.css` lewat `@theme`, menggantikan `--font-sans: 'Instrument Sans'` untuk permukaan produk. `@theme` menghasilkan utilitas peran (`bg-surface`, `text-muted`) yang hanya dipakai di dalam primitif.
- Peran tipografi adalah tiga nilai (ukuran, tinggi baris, bobot), bukan satu kunci `--text-*` Tailwind. Jangan memasukkan string gabungan ke namespace `--text-*` karena namespace itu dipakai skala ukuran bawaan. Ukuran peran memakai variabel `--font-size-*` pada primitif. Tinggi baris dan bobot menyertai primitif yang sama.
- Spasi target adalah subset langkah 4 px. Tailwind v4 sudah memakai dasar itu. Jangan membuat skala spasi kedua. Pakai langkah pada bagian 4 saja.
- Garis, fokus, kontainer, radius, bayangan, dan tinggi kontrol yang bukan utilitas warna tetap variabel CSS pada primitif. Jangan menyalin rangkaian utilitas panjang di setiap halaman.
- Tidak membuat `tailwind.config.js`.
- Layout produk nanti memuat CSS lewat Vite. Pass dokumentasi ini tidak mengubah layout.
- Primitif Blade (tombol, field, alert, status, panel) adalah cara pemakaian token. Halaman tidak mengulang nilai heksadesimal.

Contoh arah, bukan kode yang dipasang sekarang:

```css
@theme {
  --font-sans: ui-sans-serif, system-ui, "Segoe UI", sans-serif;
  --color-background: #f4f5f7;
  --color-surface: #ffffff;
  --color-text: #1c2430;
  --color-brand: #1f4e8c;
}
```

Utilitas hasil tema dipakai di dalam primitif. Halaman memanggil primitif, bukan mengulang `bg-[#1f4e8c]`.

## 13. Responsif: breakpoint versus viewport QA

**LOCKED RULE.** Jangan membuat breakpoint pada setiap lebar uji.

| Jenis | Nilai | Peran |
| --- | --- | --- |
| Sistem breakpoint | bawaan Tailwind v4: `sm` `40rem`, `md` `48rem`, `lg` `64rem`, `xl` `80rem`, `2xl` `96rem` | Satu-satunya sistem. Jangan menambah `360`, `390`, atau `1440` sebagai breakpoint kustom. |
| Viewport QA | `360`, `390`, `768`, `1024`, `1440` px | Lebar pemeriksaan browser sebelum UI-1 ditutup, sesuai master plan dan guardrail. |

`768` dan `1024` kebetulan berdekatan dengan `md` dan `lg`. Itu tidak menjadikan setiap lebar QA sebagai token breakpoint. Padding horizontal hanya berubah pada `48rem` (`md`), seperti bagian 8.

## 14. Bukan keputusan pass ini

**OPEN QUESTION.** Tetap terbuka, dan tidak boleh diselesaikan dengan token:

- memensiunkan generasi materi lama;
- endpoint status ekstraksi baru;
- locale runtime `config/app.php`;
- menghapus `welcome.blade.php`;
- di mana entri admin diletakkan.

Token tidak mengubah syarat Pro, kredit, grounding, atau kelayakan konversi. Warna dan label hanya menyajikan keadaan yang server sudah kirim.
