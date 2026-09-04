# AI Question Bank SaaS - Project Overview

## Product

AI Question Bank membantu pendidik mengubah materi pembelajaran menjadi bank soal yang dapat direview dan diedit. Google Gemini menghasilkan soal berdasarkan topik, tujuan assessment, tipe soal, tingkat kesulitan, dan jumlah yang ditentukan user.

## MVP Experience

```text
Landing
-> Google OAuth
-> Dashboard
-> Material upload
-> Topic and assessment configuration
-> Quota check
-> Gemini generation
-> Review and edit
-> Save and publish question bank
```

Question type MVP:

- Multiple choice.
- True/false.
- Essay.

## Technology Direction

- Backend: Laravel 13 on PHP 8.3+.
- Frontend: Blade + Livewire.
- Phase 2 UI: Blade/controller only; no Livewire component.
- Authentication: Google OAuth only.
- AI: Google Gemini.
- Processing: queue for long-running operation.
- Database: MySQL 8+ (Laragon for local development; `DB_CONNECTION=mysql`). 23 domain entities documented in the canonical DBML.
- Automated tests: SQLite in-memory via `phpunit.xml`.

## Product Modules

1. Authentication and role.
2. Material management.
3. Subscription and quota.
4. AI question engine.
5. Question bank.
6. Admin dashboard.
7. WhatsApp CRM after MVP.

## User and Admin

User mengelola materi, mengonfigurasi generation, mereview output, dan menyimpan question bank.

Admin menggunakan Google login yang sama dengan role admin untuk mengelola user dan question bank, memonitor generation/usage, memverifikasi subscription, serta menjalankan broadcast pada fase CRM.

## Monetization Direction

- Free Plan: quota terbatas.
- Pro Plan: quota dan fitur lebih besar.
- Institution Plan: arah post-MVP setelah organization, membership, dan shared ownership dirancang.

## Delivery Stages

- Phase 0: foundation dan dokumentasi (`COMPLETE`).
- Phase 1: authentication (`COMPLETE`).
- Phase 2: standalone material management dari dashboard (`COMPLETE`). Originally delivered upload (PDF, DOCX, TXT) and manual-text creation. Phase 5.7A later retired HTTP/UI text creation; new Material creation is upload-only. Legacy `source_type=text` rows remain readable/editable.
- Phase 3: subscription/quota foundation (`COMPLETE`).
- Phase 4: AI question engine (`COMPLETE`; 4.1–4.6 including owner generation UI and stale recovery).
- Phase 5: question bank (`COMPLETE`; MCQ-only MVP: schema, explicit completed-MCQ import to draft, owner list/detail, draft edit, atomic save, `draft → published`, published read-only). True/false and essay Question Bank remain later.
- Phase 5.7: pre-Phase-6 enhancements (`IN PROGRESS`). Phase 5.7A upload-only create is `COMPLETE`. Phase 5.7B1 Material Profile foundation is `COMPLETE`. Phase 5.7B2 sequential map/reduce provider calls is `COMPLETE`. Phase 5.7B3 owner profile review UI is `COMPLETE`. Phase 5.7C has not started.
- Phase 6: admin dashboard dan MVP release (`PLANNED`).
- Phase 7: WhatsApp CRM.
- Phase 8: optimization dan scaling.

## Documentation

Mulai dari [README](README.md) untuk peta lengkap dokumentasi, lalu gunakan:

- [PRD](docs/product/PRD.md)
- [System Design](docs/design/DESIGN.md)
- [System Flow](docs/architecture/FLOW.md)
- [Database Reference](docs/database/DATABASE_REFERENCE.md)
- [Prompt Engine Rules](docs/ai/PROMPT_ENGINE_RULES.md)
- [Development Rules](docs/rules/DEVELOPMENT_RULES.md)
- [Development Roadmap](docs/project-management/PHASE_ROADMAP.md)
- [Change Log](docs/project-management/CHANGE_LOG.md)