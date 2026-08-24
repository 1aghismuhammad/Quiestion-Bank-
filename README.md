# AI Question Bank SaaS

AI Question Bank adalah aplikasi Laravel untuk menghasilkan, meninjau, dan mengelola bank soal dari materi pembelajaran menggunakan Google Gemini.

## Current Status

- Phase: 1 - Authentication and User Management (`IMPLEMENTED`)
- Application code: Google OAuth, role access, profile setup, and basic dashboards
- Documentation version: 0.5
- MVP target: Phase 0-6
- Database design: 16 domain entities

Dokumentasi adalah rancangan implementasi. Fitur yang tercantum belum dianggap selesai sampai Definition of Done pada roadmap terpenuhi.

## Architecture Decisions

- PHP 8.3+ dan Laravel 13.
- Blade + Livewire untuk UI.
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
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=
GEMINI_API_KEY=
GEMINI_MODEL=
```

Environment value dan credential tidak boleh dicatat dalam repository.

## Open Product Decisions

- Format dan ukuran file upload MVP.
- Nilai quota generation/storage Free dan Pro.
- Format export pertama.
- Provider payment dan WhatsApp post-MVP.

Keputusan baru harus diselaraskan pada PRD, design, flow, database, roadmap, dan changelog.