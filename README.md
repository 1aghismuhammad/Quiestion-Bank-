# AI Question Bank SaaS

AI Question Bank adalah aplikasi Laravel untuk menghasilkan, meninjau, dan mengelola bank soal dari materi pembelajaran menggunakan Google Gemini.

## Current Status

- Phase 1 - Authentication and User Management: `IMPLEMENTED`
- Phase 2 - Material Management: `IN PROGRESS` (canonical design approved; Phase 2 Definition of Done not yet met)
- Application code: Google OAuth, role access, profile setup, and basic dashboards; Phase 2.1–2.3 material domain, creation, MIME/extension validation, private storage, SHA-256/UUID paths, duplicate/failure safety, and storage usage accounting
- Next technical milestone: content extraction (`NOT STARTED`; requires its own planning and Composer dependency approval)
- Documentation version: 0.6.2
- MVP target: Phase 0-6
- Database design: 16 domain entities

Dokumentasi adalah rancangan implementasi. Fitur yang tercantum belum dianggap selesai sampai Definition of Done pada roadmap terpenuhi. Phase 2.3 private storage and usage is technically complete; Phase 2 overall is not complete.

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
GEMINI_API_KEY=
GEMINI_MODEL=
```

Local development memakai MySQL 8+ melalui Laragon. Set `DB_CONNECTION=mysql` di `.env` dan pastikan MySQL Laragon berjalan sebelum perintah artisan database. Test otomatis memakai SQLite in-memory melalui `phpunit.xml` dan tidak mengubah koneksi aplikasi lokal.

Environment value dan credential tidak boleh dicatat dalam repository.

## Open Product Decisions

- Nilai quota generation/storage Free dan Pro.
- Limit upload per plan yang akan menggantikan batas sementara Phase 2 sebesar 10 MB.
- Format export pertama.
- Provider payment dan WhatsApp post-MVP.

Keputusan baru harus diselaraskan pada PRD, design, flow, database, roadmap, dan changelog.