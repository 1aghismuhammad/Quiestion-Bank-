# AI Question Bank SaaS - Project Overview

## Product

AI Question Bank membantu pendidik mengubah materi pembelajaran menjadi bank soal yang dapat direview dan diedit. Google Gemini menghasilkan soal berdasarkan topik, tujuan assessment, tipe soal, tingkat kesulitan, dan jumlah yang ditentukan user.

## MVP Experience

```text
Landing
-> Google OAuth
-> Dashboard
-> Material upload/text
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
- Authentication: Google OAuth only.
- AI: Google Gemini.
- Processing: queue for long-running operation.
- Database: 16 domain entities documented in canonical DBML.

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

- Phase 0: foundation dan dokumentasi.
- Phase 1: authentication.
- Phase 2: material management.
- Phase 3: subscription/quota foundation.
- Phase 4: AI question engine.
- Phase 5: question bank.
- Phase 6: admin dashboard dan MVP release.
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