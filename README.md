# AI Question Bank SaaS

AI Question Bank adalah aplikasi Laravel untuk menghasilkan, meninjau, dan mengelola bank soal dari materi pembelajaran menggunakan Google Gemini.

## Current Status

- Phase 0 - Foundation: `COMPLETE`
- Phase 1 - Authentication and User Management: `COMPLETE`
- Phase 2 - Material Management: `COMPLETE`
- Phase 2.1 - Material Domain Foundation: `COMPLETE`
- Phase 2.2 - Material Creation Flow: `COMPLETE`
- Phase 2.3 - Private Storage & Usage: `COMPLETE`
- Phase 2.4 - Content Extraction: `COMPLETE`
- Phase 2.5 - Topic Management: `COMPLETE`
- Phase 2.6 - Material Ownership & Authorization: `COMPLETE`
- Phase 2.7 - Archive / Restore Lifecycle: `COMPLETE`
- Phase 2.8 - Material Web Management: `COMPLETE`
- Application code: Google OAuth, role access, profile setup, dashboards, owner-scoped Blade Material Management, Plan catalog, Pro subscription history, entitlement resolver, account storage quota, generation quota definition, Plan Offers, manual QRIS/WhatsApp upgrade verification, generation domain foundation, generation usage/quota runtime, Gemini MCQ provider, async generation orchestration, owner generation UI/preview, stale generation recovery, and Question Bank Batch 1–2 (schema, explicit completed-MCQ import to draft, owner list/detail, draft MCQ edit, draft→published)
- Phase 3 - Subscription & Quota Foundation: `COMPLETE`
- Phase 3.1 - Plan Domain Foundation: `COMPLETE`
- Phase 3.2 - Subscription Domain Foundation: `COMPLETE`
- Phase 3.3 - Active Entitlement Resolution: `COMPLETE`
- Phase 3.4 - Storage Quota Enforcement: `COMPLETE`
- Phase 3.5 - Generation Quota Foundation: `COMPLETE`
- Phase 3.6 - Subscription UI and Manual Payment: `COMPLETE`
- Phase 4 - AI Question Engine: `COMPLETE`
- Phase 4.1 - AI Generation Domain Foundation: `COMPLETE`
- Phase 4.2 - Generation Usage & Quota Runtime: `COMPLETE`
- Phase 4.3 + 4.4 - Gemini + structured output + async orchestration: `COMPLETE`
- Phase 4.5 - Generation Web UI / Result Preview: `COMPLETE`
- Phase 4.6 - Reliability / Stale Recovery / Phase Closure: `COMPLETE`
- Next: Phase 5 Question Bank (5.1–5.6 implemented pending review; Phase 5 remains IN PROGRESS)
- Documentation version: 0.14.0
- MVP target: Phase 0-6
- Database design: 18 domain entities

Dokumentasi adalah rancangan implementasi. Fitur yang tercantum belum dianggap selesai sampai Definition of Done pada roadmap terpenuhi. Phase 0 through Phase 4 are `COMPLETE`. Phase 5.1–5.6 are implemented pending review. Phase 5 is not `COMPLETE`.

## Architecture Decisions

- PHP 8.3+ dan Laravel 13.
- Blade + Livewire untuk UI.
- Phase 2 Material Management menggunakan Blade/controller tanpa Livewire component.
- Google OAuth only melalui Laravel Socialite.
- Google Gemini sebagai AI provider MVP.
- Queue untuk generation, extraction, serta broadcast pada Phase 7.
- Multiple choice, true/false, dan essay pada MVP.
- Laravel modular monolith dengan action/service layer.

## Documentation Map

- [Project Overview](README_PROJECT.md) - ringkasan produk dan scope.
- [Product Requirement Document](docs/product/PRD.md) - persona, requirement, acceptance criteria, dan monetization.
- [System Design](docs/design/DESIGN.md) - arsitektur, module, integration, security, dan deployment.
- [System Flow](docs/architecture/FLOW.md) - flow user, admin, state, dan failure handling.
- [Database Reference](docs/database/DATABASE_REFERENCE.md) - entity, invariant, index, dan migration order.
- [Canonical DBML](docs/database/AI_QUESTION_BANK.dbml) - schema domain yang dapat dikompilasi.
- [Prompt Engine Rules](docs/ai/PROMPT_ENGINE_RULES.md) - Gemini prompt, JSON contract, validation, dan audit.
- [Development Rules](docs/rules/DEVELOPMENT_RULES.md) - aturan coding, testing, security, dan dokumentasi.
- [Development Roadmap](docs/project-management/PHASE_ROADMAP.md) - urutan fase dan Definition of Done.
- [Change Log](docs/project-management/CHANGE_LOG.md) - riwayat keputusan dan database impact.

## Recommended Reading Order

1. `README_PROJECT.md`
2. `docs/product/PRD.md`
3. `docs/design/DESIGN.md`
4. `docs/architecture/FLOW.md`
5. `docs/database/DATABASE_REFERENCE.md`
6. `docs/ai/PROMPT_ENGINE_RULES.md`
7. `docs/rules/DEVELOPMENT_RULES.md`
8. `docs/project-management/PHASE_ROADMAP.md`

## Planned Environment

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ai_question_bank
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=
SUBSCRIPTION_WHATSAPP_NUMBER=
SUBSCRIPTION_QRIS_PATH=payment/qris.png
GEMINI_API_KEY=
GEMINI_PRIMARY_MODEL=gemini-3.5-flash-lite
GEMINI_FALLBACK_MODEL=gemini-3.7-flash
GENERATION_QUEUE_CONNECTION=database-generation
GENERATION_QUEUE=question-generation
GENERATION_QUEUE_RETRY_AFTER=360
# 1800 is the minimum safe floor; operators may configure a higher threshold.
GENERATION_STALE_AFTER_SECONDS=1800
GENERATION_STALE_RECOVERY_BATCH=50
```

Local development memakai MySQL 8+ melalui Laragon. Set `DB_CONNECTION=mysql` di `.env` dan pastikan MySQL Laragon berjalan sebelum perintah artisan database. Test otomatis memakai SQLite in-memory melalui `phpunit.xml` dan tidak mengubah koneksi aplikasi lokal.

Environment value dan credential tidak boleh dicatat dalam repository.

## Open Product Decisions

- Upload per file tetap maksimal 10 MB. Quota storage akun (Free 50 MiB / Pro 500 MiB total) adalah kontrol terpisah yang sudah ditegakkan dan tidak menggantikan batas per file.
- Format export pertama.
- Provider payment otomatis tetap post-MVP. Phase 3.6 memakai QRIS statis (public disk) dan konfirmasi WhatsApp manual. Phase 7 adalah WhatsApp CRM / broadcast.

Keputusan baru harus diselaraskan pada PRD, design, flow, database, roadmap, dan changelog.
