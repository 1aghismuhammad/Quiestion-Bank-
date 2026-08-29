# System Flow

## Scope

Dokumen ini menerjemahkan rancangan flowchart user dan admin ke alur implementasi Laravel. Flow menggunakan keputusan arsitektur:

- Google OAuth only.
- Admin dan user menggunakan login yang sama serta dibedakan role.
- Blade + Livewire untuk UI.
- Phase 2 Material Management menggunakan Blade/controller tanpa Livewire component.
- Google Gemini melalui queue untuk generation.
- Database canonical: `docs/database/AI_QUESTION_BANK.dbml`.
- Phase 3.1 + 3.2: Plan catalog Free/Pro dan riwayat window Pro. Phase 3.3 + 3.4: resolver entitlement dan quota storage akun pada upload. Phase 3.5: definisi quota generation. Phase 3.6: Plan Offers, QRIS/WhatsApp, dan verifikasi admin.

## User Flow

```mermaid
flowchart LR
    Start([Start]) --> Landing[Landing Page]
    Landing --> Login[Login with Google]
    Login --> OAuth{OAuth valid?}
    OAuth -- No --> LoginError[Show login error]
    LoginError --> Landing
    OAuth -- Yes --> Account{Account active?}
    Account -- No --> Blocked[Show account blocked]
    Account -- Yes --> Dashboard[User Dashboard]
    Dashboard --> Materials[Material Management]
    Dashboard --> AccountSub[Account Subscription]
    AccountSub --> ChooseOffer[Choose Pro 1m or 3m offer]
    ChooseOffer --> PayQris[Pay static QRIS]
    PayQris --> WaConfirm[POST confirm + WhatsApp]
    WaConfirm --> AdminVerify[Admin approve reject or cancel]
    Materials --> MaterialChoice{Material source?}
    MaterialChoice -- Existing --> Existing[Open existing material]
    MaterialChoice -- Upload --> Upload[Upload material]
    MaterialChoice -- Text --> Text[Enter material text and mark ready]
    Upload --> ValidateMaterial{File valid?}
    ValidateMaterial -- No --> MaterialError[Show validation error]
    MaterialError --> Upload
    ValidateMaterial -- Yes --> Extract[Queue content extraction]
    Text --> Topic[Select chapter, topic, and focus]
    Existing --> Topic
    Extract --> Extraction{Extraction successful?}
    Extraction -- No --> ExtractionError[Show extraction error or retry]
    ExtractionError --> Materials
    Extraction -- Yes --> Ready[Mark material ready]
    Ready --> Topic
    Topic --> Materials
    Materials --> Archive[Archive draft or ready material]
    Archive --> Archived[Material archived]
    Archived --> Restore[Owner restores material]
    Restore --> Ready
    Dashboard --> Config[Configure generation on ready material]
    Config --> ValidConfig{Configuration valid?}
    ValidConfig -- No --> Config
    ValidConfig -- Yes --> Quota{Quota available?}
    Quota -- No --> Upgrade[Show quota and upgrade options]
    Upgrade --> Dashboard
    Quota -- Yes --> Reserve[Reserve generation credit]
    Reserve --> Queue[Queue Gemini generation]
    Queue --> Generate{Generation successful and valid?}
    Generate -- No --> AutoRetry{Automatic retry remaining?}
    AutoRetry -- Yes --> Queue
    AutoRetry -- No --> Release[Release credit keep failed generation]
    Release --> Config
    Generate -- Yes --> Charge[Charge credit]
    Charge --> Preview[Preview generated questions]
    Preview --> Dashboard
    Dashboard --> ImportBank[Import completed generation to Question Bank]
    ImportBank --> Review[Review and edit question set]
    Review --> Save{Save review changes?}
    Save -- No --> Review
    Save -- Yes --> Persist[Save question set in review]
    Persist --> Publish{Publish now?}
    Publish -- No --> Dashboard
    Publish -- Yes --> Published[Set status published]
    Published --> Dashboard
    Dashboard --> Logout[Logout]
    Logout --> End([End])
```

### User Flow Rules

1. Login pertama membuat user dan role default; login berikutnya memperbarui profil Google. Entitlement default adalah Plan Free; OAuth tidak membuat baris subscription.
2. Phase 2 Material Management dibuka langsung dari dashboard dan tidak bergantung pada question set.
3. Material upload hanya menerima PDF, DOCX, atau TXT. Setiap file maksimal 10 MB. Quota storage akun Plan (Free 50 MiB / Pro 500 MiB total) adalah kontrol terpisah: `CreateUploadMaterial` mengunci baris user, menolak duplikat dulu, lalu menolak file baru jika usage terhitung + ukuran file melebihi limit Plan efektif.
4. Material upload harus lolos MIME, extension, size, dan ownership validation.
5. Upload wajib menyimpan internal file path, file size, MIME type, SHA-256 hash, dan extraction status.
6. Kombinasi user dan file hash unique sehingga duplikat user yang sama ditolak.
7. Material text menggunakan extraction status `not_required`; upload berjalan pending, processing, lalu completed/failed.
8. Material berubah dari draft menjadi ready setelah content text tersedia atau extraction berhasil.
9. Seluruh upload yang belum dihapus tetap dihitung pada storage usage, termasuk archived dan extraction failed.
10. Owner dapat melakukan `draft|ready -> archived` dan `archived -> ready`.
11. Assessment type, difficulty, dan question type adalah konfigurasi berbeda.
12. Credit direservasi pada Start generation (satu request = satu reservation) agar request paralel tidak melewati quota. Generation tidak memerlukan draft `question_sets`.
13. Credit hanya ditagihkan (`charged`) setelah output valid. Terminal failure me-release reservation.
14. Automatic provider/job retry memakai Generation dan reservation yang sama (`attempt_number` boleh naik; tanpa credit tambahan). Manual retry setelah terminal failure membuat Generation baru dan reservation baru; `parent_generation_id` boleh menunjuk Generation lama.
15. Jangan persist raw prompt atau full raw Gemini/provider response secara default. Error/diagnostik disanitasi. Preview generation adalah Phase 4; Question Bank / `question_sets` adalah Phase 5 dan boleh mengimpor hasil generation yang completed.

## AI Generation State Flow

```mermaid
stateDiagram-v2
    [*] --> queued
    queued --> processing
    processing --> completed: valid output
    processing --> failed: terminal provider or validation error
    queued --> cancelled
    processing --> cancelled
    completed --> [*]
    failed --> [*]
    cancelled --> [*]
```

Automatic provider/job retry stays on the **same** `AiGeneration` and the **same** `AiUsageLog` reservation. `attempt_number` may increase. No extra credit. Do not create a child Generation for automatic retry.

Manual user retry after terminal failure: the old Generation remains `failed`; its reservation is `released`; the user starts a **new** Generation with a new reservation; `parent_generation_id` may link to the old Generation.

Phase 4 does not create or require `question_sets`. It stores generation runtime (and later a preview). Question Bank is Phase 5 and may import an approved completed generation.

## Question Set State Flow

Question Bank / `question_sets` is Phase 5. It is not a prerequisite of Phase 4 generation.

```mermaid
stateDiagram-v2
    [*] --> draft
    draft --> review: import completed generation or manual questions
    review --> published: user confirms or admin approves
    published --> archived
    archived --> draft: restore
```

Admin review memiliki state terpisah:

```mermaid
stateDiagram-v2
    [*] --> not_submitted
    not_submitted --> pending: user submits
    pending --> approved: admin approves
    pending --> rejected: admin rejects
    rejected --> pending: user resubmits
```

Selama admin review pending/rejected, lifecycle utama tetap `question_sets.status = review`. Approval dapat mengubah lifecycle menjadi published.

## Admin Flow

```mermaid
flowchart TB
    Start([Start]) --> Login[Login with Google]
    Login --> Auth{OAuth valid and admin role?}
    Auth -- No --> Failed[Show access denied]
    Failed --> Login
    Auth -- Yes --> Menu[Admin Dashboard Menu]

    Menu --> Users[User Management]
    Users --> FetchUsers[Fetch user data]
    FetchUsers --> UserList[Display users, role, status, subscription]
    UserList --> UserAction{Action}
    UserAction --> UpdateUser[Update user or status]
    UserAction --> AssignRole[Assign or revoke role]
    UserAction --> DeleteUser[Delete or deactivate user]
    UpdateUser --> UserList
    AssignRole --> UserList
    DeleteUser --> UserList

    Menu --> Questions[Question Bank Management]
    Questions --> FetchQuestions[Fetch question bank data]
    FetchQuestions --> QuestionList[Display question, options, answer, explanation]
    QuestionList --> QuestionAction{Action}
    QuestionAction --> CreateQuestion[Create question]
    QuestionAction --> UpdateQuestion[Update or review question]
    QuestionAction --> DeleteQuestion[Delete question]
    CreateQuestion --> QuestionList
    UpdateQuestion --> QuestionList
    DeleteQuestion --> QuestionList

    Menu --> Generation[AI Generation Monitoring]
    Generation --> GenerationList[Display user, material, config, model, status, date]
    GenerationList --> Menu

    Menu --> Usage[AI Usage Monitoring]
    Usage --> UsageList[Display user, tokens, credits, cost, purpose, date]
    UsageList --> Menu

    Menu --> Subscription[Subscription Monitoring]
    Subscription --> SubscriptionList[Display Pro windows and status]
    SubscriptionList --> Menu

    Menu --> Payment[Manual Upgrade Verification]
    Payment --> RequestList[Display payment or upgrade requests]
    RequestList --> Verify[Admin verifies]
    Verify --> Outcome{Decision}
    Outcome -- Approve --> GrantPro[Create or append Pro subscription]
    GrantPro --> RequestList
    Outcome -- Reject --> RejectRequest[Reject with required reason]
    RejectRequest --> RequestList
    Outcome -- Cancel --> CancelRequest[Cancel pending request]
    CancelRequest --> RequestList

    Menu --> Broadcast[Phase 7: Broadcast Management]
    Broadcast --> Compose[Compose message and target segment]
    Compose --> Confirm{Confirm send?}
    Confirm -- No --> Menu
    Confirm -- Yes --> Process[Queue broadcast]
    Process --> Result{Broadcast successful?}
    Result -- No --> Error[Show error and failed delivery count]
    Result -- Yes --> Success[Show success and delivery summary]
    Error --> Menu
    Success --> Menu

    Menu --> Logout[Logout and terminate session]
    Logout --> End([End])
```

### Admin Flow Rules

- Semua admin action menggunakan authorization policy, bukan hanya tampilan menu.
- User dibuat otomatis oleh Google OAuth; admin tidak membuat password account secara manual.
- Monitoring subscription terpisah dari verifikasi pembayaran/upgrade manual (minimum Phase 3.6; dashboard penuh Phase 6). Domain 3.1 + 3.2 hanya catalog Plan dan riwayat window Pro.
- Delete user sebaiknya berupa deactivation atau soft delete untuk menjaga audit.
- Verifikasi permintaan pembayaran/upgrade menyimpan admin, waktu keputusan, dan alasan penolakan pada `subscription_upgrade_requests`, bukan pada Subscription. Admin dapat approve, reject (alasan wajib), atau cancel. User tidak dapat membatalkan pending miliknya. Notifikasi email belum termasuk Phase 3.6.
- Verifikasi pembayaran tidak menembus `MaterialPolicy`. Admin tidak memperoleh akses global ke Material privat.
- Monitoring AI bersifat read-only kecuali retry/cancel diberikan secara eksplisit.
- Branch broadcast adalah target Phase 7 dan bukan release gate MVP.
- Broadcast membutuhkan confirmation, consent filter, opt-out filter, dan delivery log.
- Admin kembali ke dashboard atau list setelah setiap aksi selesai.

## Failure Handling

### OAuth Failure

Kembali ke landing dengan pesan generik. Detail provider dicatat di log server tanpa mengekspos token.

### Material Failure

File invalid ditolak sebelum disimpan. Extraction failure dapat di-retry tanpa membuat ulang metadata material.

### Quota Failure

Upload yang melebihi `storage_limit_bytes` ditolak. Allowance generation didefinisikan di Phase 3.5 dan ditegakkan di Phase 4.1+4.2 (`available = allowance - charged - reserved`). Generation gagal me-release credit (Action exists; Gemini job is Phase 4.3+).

### Gemini Failure

Timeout, provider error, dan invalid JSON: automatic retry on the same Generation/reservation until the budget is exhausted; then `failed`, sanitized error metadata, and Release. Manual retry is a new Generation. Do not persist full raw provider responses by default.

### Broadcast Failure

Flow ini berlaku mulai Phase 7. Failure satu penerima tidak membatalkan seluruh campaign. Setiap penerima memiliki delivery status sendiri.