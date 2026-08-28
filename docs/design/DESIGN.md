# System Design

## Design Status

- Version: 0.8
- Architecture style: Laravel modular monolith
- Runtime: PHP 8.3+, Laravel 13
- UI: Blade + Livewire + Tailwind CSS
- Authentication: Google OAuth only
- AI provider: Google Gemini
- Database source of truth: `docs/database/AI_QUESTION_BANK.dbml`

## Architecture Overview

```mermaid
flowchart TB
    Browser[Browser]
    UI[Blade + Livewire]
    App[Laravel Application]
    Queue[Queue Worker]
    DB[(Relational Database)]
    Storage[(File Storage)]
    Google[Google OAuth]
    Gemini[Google Gemini API]

    Browser --> UI
    UI --> App
    App --> Google
    App --> DB
    App --> Storage
    App --> Queue
    Queue --> Gemini
    Queue --> DB
```

Generation AI dipisahkan dari request web agar timeout provider tidak memblokir Livewire. UI membaca status generation dari database sampai proses selesai atau gagal.

## Architecture Decisions

### Laravel Modular Monolith

MVP dibangun sebagai satu aplikasi Laravel agar deployment, authorization, transaksi database, dan pengembangan UI tetap sederhana. Pemisahan dilakukan berdasarkan modul dan service, bukan microservice.

### Blade + Livewire

Blade menangani layout dan server-rendered page. Livewire menangani form interaktif, status queue, filter dashboard, review soal, dan admin tools. REST API publik tidak menjadi kebutuhan MVP.

Phase 2 Material Management secara khusus menggunakan Blade, controller, Form Request, policy, action/service, dan JavaScript polling minimal. Phase 2 tidak menginstal atau membuat Livewire component; Livewire hanya dapat diperkenalkan pada phase lain melalui keputusan terpisah.

### Google OAuth Only

Laravel Socialite menangani redirect dan callback Google. Tidak ada registration, password login, atau password reset lokal. Admin adalah user Google dengan role `ADMIN`, bukan guard terpisah.

### Google Gemini

Gemini menjadi provider tunggal MVP dan diakses melalui kontrak internal agar provider dapat diganti tanpa mengubah domain service.

## Application Layers

```text
Route / Livewire Component
        |
Form Validation + Authorization Policy
        |
Action / Domain Service
        |
Eloquent Model + Database Transaction
        |
Job / External Provider Adapter (jika asynchronous)
```

- Livewire component mengelola state UI, bukan aturan bisnis.
- Action atau service mengelola use case dan transaction boundary.
- Model mengelola relasi serta query scope sederhana.
- Job mengelola AI generation, ekstraksi materi, dan broadcast Phase 7.
- Provider adapter mengisolasi Google Gemini dan layanan eksternal.

## Suggested Directory Structure

```text
app/
|-- Actions/
|   |-- Auth/
|   |-- Materials/
|   |-- QuestionSets/
|   `-- Subscriptions/
|-- Contracts/AI/
|-- Data/
|-- Jobs/
|-- Livewire/
|   |-- User/
|   `-- Admin/
|-- Models/
|-- Policies/
|-- Services/
|   |-- AI/
|   |-- Materials/
|   `-- Subscriptions/
`-- Support/
```

Repository layer hanya ditambahkan jika query kompleks atau sumber data perlu diabstraksi. Eloquent tidak perlu dibungkus repository secara otomatis.

## Core Modules

### Authentication and User Management

- Google OAuth redirect dan callback.
- Auto-provision user dan sinkronisasi profil.
- Role `USER` dan `ADMIN`.
- First-login phone setup melalui `whatsapp_contacts`.
- User status enforcement.
- Session termination dan logout.

### Material Management

- Menu material berdiri sendiri dan dapat dibuka langsung dari dashboard tanpa membuat question set.
- Upload hanya mendukung PDF, DOCX, dan TXT. Setiap file maksimal 10 MB (batas keselamatan MVP). Quota storage akun Plan (Free 50 MiB / Pro 500 MiB total) ditegakkan pada upload dan tidak menggantikan batas per file.
- Input teks manual langsung menghasilkan material ready.
- Metadata file, hash, extraction status, dan storage usage.
- Chapter, sub-chapter, topic, dan focus area.
- Ownership policy.
- Material text memakai extraction status `not_required`; upload berjalan dari pending ke processing lalu completed/failed.
- Material berubah dari draft menjadi ready setelah teks tersedia atau extraction selesai.
- Upload wajib menyimpan internal file path, file size, MIME type, SHA-256 hash, dan extraction status.
- Kombinasi user dan file hash unique untuk mencegah duplikasi per user.
- Storage usage menghitung seluruh upload yang belum dihapus, termasuk archived dan extraction failed.
- Lifecycle material mendukung `draft|ready -> archived` dan owner restore `archived -> ready`.

### Subscription and Quota

- Catalog Plan: Free dan Pro sebagai entitlement (bukan harga). Institution adalah arah post-MVP.
- Free adalah fallback jika tidak ada window Pro efektif. Tidak ada baris subscription Free.
- Subscription adalah riwayat window Pro `[starts_at, ends_at)` dengan status `active|expired|cancelled`.
- Paling banyak satu window efektif per instant. Resolver memvalidasi seluruh antrian `active` current/future sebagai Pro; overlap efektif fail-closed; data stale historis tidak mengunci akun. Plan Pro inactive tidak mencabut window yang sudah dibayar.
- Limit dibaca live dari Plan (bukan snapshot di Subscription). Quota storage akun ditegakkan di `GuardUploadStorageQuota` dengan kunci baris `users` per pemilik. Duplikat `(user_id, file_hash)` dicek ulang di bawah kunci sebelum quota. Fondasi quota generation, reservation, dan `ai_usage_logs` adalah Phase 3.5 + 3.6; integrasi Gemini dikoordinasikan dengan Phase 4.
- UI subscription/quota, QRIS statis, konfirmasi WhatsApp, dan verifikasi admin minimum adalah Phase 3.5 + 3.6. Tidak ada payment gateway di MVP.

### AI Engine

```mermaid
sequenceDiagram
    participant U as User
    participant L as Livewire
    participant Q as Quota Service
    participant J as Queue Job
    participant P as Prompt Builder
    participant G as Gemini
    participant V as Output Validator
    participant D as Database

    U->>L: Submit generation configuration
    L->>Q: Validate and reserve credit
    Q->>D: Create usage reservation
    L->>D: Link draft question set and mark generating
    L->>J: Dispatch generation
    J->>P: Build versioned prompt
    P->>G: Generate structured output
    G-->>J: JSON response
    J->>V: Validate by question type
    alt Valid
        V->>D: Save questions and mark question set review
        J->>Q: Charge reserved credit
    else Invalid or provider failure
        J->>D: Save failure and return question set to draft
        J->>Q: Release reserved credit
    end
```

AI Engine terdiri dari:

- Prompt version management.
- Gemini client adapter.
- Queue-based generation.
- Schema validation per question type.
- Retry lineage.
- Token, cost, raw response, dan parsed output audit.
- Retry hanya berlaku pada generation gagal; draft question set yang sama diarahkan ke generation anak dan kembali berstatus generating.

### Question Bank

- Question set manual atau hasil AI.
- Multiple choice, true/false, dan essay.
- User review dan editing.
- Draft, generating, review, publish, archive.
- Optional admin review.
- Private visibility secara default; public membutuhkan aksi eksplisit pemilik.

### Admin Dashboard

- User management CRUD.
- Question bank management CRUD dan review.
- AI generation monitoring.
- AI usage monitoring.
- Subscription monitoring.
- Manual upgrade/payment verification.

### WhatsApp CRM

Modul Phase 7 post-MVP yang menambahkan broadcast compose, confirmation, queue, contact consent, segment filter, provider message ID, dan delivery status. Implementasi harus menghormati opt-out.

## Data Design

Enam belas entitas domain dan seluruh relasinya didefinisikan pada:

- `docs/database/AI_QUESTION_BANK.dbml`
- `docs/database/DATABASE_REFERENCE.md`

Tabel infrastruktur Laravel seperti sessions, cache, jobs, job batches, dan failed jobs tetap dikelola oleh migration framework dan tidak dihitung sebagai entitas domain.

## Security Design

- Session authentication dan CSRF untuk seluruh UI.
- OAuth state validation melalui Socialite.
- Role middleware untuk route admin.
- Policy untuk material, question set, dan generation.
- MIME, extension, dan size validation pada upload.
- Rate limit untuk login callback, generation, serta broadcast mulai Phase 7.
- Environment secret untuk Google dan Gemini.
- Sanitasi output ketika dirender pada Blade.
- Admin action dan AI request harus dapat diaudit.

## Configuration

Environment minimum:

```dotenv
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=
GEMINI_API_KEY=
GEMINI_MODEL=
```

Nama model Gemini disimpan pada konfigurasi aplikasi dan dicatat pada setiap generation.

## Deployment Topology

MVP membutuhkan:

1. Web application Laravel.
2. Queue worker.
3. Scheduler.
4. Relational database.
5. File storage lokal atau object storage.

Production dapat menambahkan Redis untuk queue/cache, object storage, monitoring, dan multiple workers tanpa mengubah domain model.