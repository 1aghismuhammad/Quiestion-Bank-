# Perubahan Phase 1

Tanggal: 21 Agustus 2026

Implementasi ini menambahkan fondasi database, autentikasi, manajemen user dan role, profile setup, serta dashboard dasar untuk Phase 1 AI Question Bank.

## Perubahan yang Ditambahkan

- Menambahkan Laravel Socialite untuk autentikasi Google OAuth.
- Menambahkan proses login Google, callback, provisioning user baru, login user lama, dan logout.
- Menambahkan role default `USER` untuk user baru serta role `ADMIN` melalui seeder.
- Menambahkan first-login profile setup yang mewajibkan nomor WhatsApp.
- Menambahkan normalisasi nomor Indonesia ke format E.164 (`+62`).
- Menambahkan middleware untuk memeriksa status akun, kelengkapan profil, dan role.
- Menambahkan dashboard user dengan informasi akun dan placeholder menu Generate Question, Question Bank, History, dan Subscription.
- Menambahkan dashboard admin dengan jumlah total user dan admin.
- Menambahkan pembatasan request pada route Google OAuth.
- Menambahkan perlindungan agar profile setup tidak dapat digunakan untuk menimpa nomor yang sudah tersimpan.

## Database dan Model

Migration yang ditambahkan:

1. `prepare_users_for_google_oauth`
2. `create_roles_table`
3. `create_role_user_table`
4. `create_whatsapp_contacts_table`

Model dan relasi yang ditambahkan:

- `User` memiliki banyak `Role`.
- `Role` memiliki banyak `User`.
- `User` memiliki satu `WhatsAppContact`.
- `WhatsAppContact` dimiliki oleh satu `User`.

Enum yang ditambahkan:

- `RoleName`: `ADMIN` dan `USER`.
- `UserStatus`: `active`, `suspended`, dan `inactive`.

## File Konfigurasi yang Diubah

- `composer.json` dan `composer.lock` untuk Laravel Socialite.
- `.env.example` untuk konfigurasi Google OAuth.
- `config/services.php` untuk Google client ID, secret, dan redirect URI.
- `bootstrap/app.php` untuk registrasi middleware.
- `routes/web.php` untuk route autentikasi, profile setup, dashboard, admin, dan logout.
- `app/Models/User.php`, user factory, serta database seeder.

## Tampilan yang Ditambahkan

- Layout utama aplikasi.
- Halaman utama/login.
- Halaman profile setup.
- Dashboard user.
- Dashboard admin.

## Testing dan Verifikasi

- Menambahkan test Google OAuth, sinkronisasi login ulang, konflik identitas, rate limit, kegagalan provider, status suspended/inactive termasuk saat sesi aktif, dan logout.
- Menambahkan test relasi database.
- Menambahkan test profile setup, validasi nomor, duplikasi nomor, dan perlindungan perubahan ulang.
- Menambahkan test perlindungan route admin.
- Hasil akhir: **25 test berhasil dengan 92 assertions**.
- Laravel Pint berhasil.
- Seluruh migration berhasil dijalankan.
- Seeder berhasil membuat role `ADMIN` dan `USER`.
- DBML berhasil dikompilasi.
- Composer audit tidak menemukan security advisory.

## Dokumentasi yang Disesuaikan

- README.
- Product Requirement Document.
- System Design.
- Database Reference dan DBML.
- Phase Roadmap.
- Development Rules.
- Change Log.

Implementasi tetap dibatasi pada Phase 1 dan belum menambahkan fitur material, subscription, AI generation, question bank, monitoring, maupun broadcast.
