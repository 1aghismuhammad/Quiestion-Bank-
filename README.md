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
- Application code: Google OAuth, role access, profile setup, dashboards, owner-scoped Blade Material Management, Plan catalog, Pro subscription history, entitlement resolver, account storage quota, generation quota definition, Plan Offers, manual QRIS/WhatsApp upgrade verification, generation domain foundation, generation usage/quota runtime, Gemini MCQ/True-False/Essay provider, async generation orchestration, owner generation UI/preview, stale generation recovery, Question Bank Phase 5 (`COMPLETE`; schema, explicit completed-MCQ import to draft, owner list/detail, draft MCQ edit, draft→published), Phase 5.7G Run import to typed draft (MCQ/TF/Essay), typed draft edit/publish, student/teacher question DOCX, Question Blueprint (manual/AI fill/confirmed kisi-kisi DOCX, Simple and Advanced, mixed types), multi-credit SUM ledger, Simple and Advanced Generation Runs, deterministic MCQ presentation shuffle, and typed True/False and Essay Run results
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
- Phase 5 - Question Bank: `COMPLETE` (MCQ, True/False, and Essay MVP: completed-Generation import to draft, owner list/detail, draft edit, atomic whole-set save, `draft → published`, published read-only)
- Phase 5.7 - Pre-Phase-6 enhancements: `COMPLETE`
- Phase 5.7A - Upload-only Material Transition: `COMPLETE` (new Material creation is upload-only; legacy `source_type=text` rows remain readable/editable)
- Phase 5.7B1 - Material Profile Foundation: `COMPLETE` (persistence, hashing, splitting, eligibility, tokens, leases, recovery)
- Phase 5.7B2 - Sequential Material Profile Map/Reduce Provider Calls: `COMPLETE` (dedicated provider boundary, lossless bounded reduce, fingerprint revalidation, sequential `material-intelligence` jobs, no generation credits)
- Phase 5.7B3 - Owner Activation, Progress, Review, and Regeneration UI: `COMPLETE` (owner start, status polling, review, regenerate; no element editing)
- Phase 5.7C - Question Blueprint domain, AI fill, and confirmed kisi-kisi DOCX: `COMPLETE` (completed and committed before the Phase 5.7E baseline)
- Phase 5.7D - Multi-credit SUM ledger and Simple Generation Runs: `COMPLETE` (completed and committed before the Phase 5.7E baseline)
- Phase 5.7E - Advanced MCQ, Pro gating, mixed difficulty, deterministic shuffle: `COMPLETE`
- Phase 5.7F - True/False and Essay generation: `COMPLETE`
- Phase 5.7G - Run-to-Question-Bank import, typed edit/publish, question DOCX, and final hardening: `COMPLETE`
- Next numbered main phase: Phase 6 Admin Dashboard (`PLANNED`)
- Documentation version: 0.15.14
- MVP target: Phase 0-6
- Database design: 30 domain runtime (33 canonical) entities documented in the canonical DBML

Dokumentasi adalah rancangan implementasi. Fitur yang tercantum belum dianggap selesai sampai Definition of Done pada roadmap terpenuhi. Phase 0 through Phase 5 are `COMPLETE`. Phase 5 Question Bank MVP delivered MCQ, True/False, and Essay import/edit/publish; Phase 5.7G adds Run import and typed MCQ/True-False/Essay edit/publish plus question DOCX. Phase 5.7 is `COMPLETE`; Phase 5.7A through Phase 5.7B3 are `COMPLETE`. Phase 5.7C+D was completed and committed before the Phase 5.7E baseline. Phase 5.7E Advanced MCQ is `COMPLETE` (v0.15.12; source review and owner manual QA accepted as PASS). Phase 5.7F True/False and Essay is `COMPLETE` (v0.15.13; code committed; source review passed). Phase 5.7G is `COMPLETE` (v0.15.14). Simple Mode remains available to Free and Pro. New Material creation is upload-only; legacy `source_type=text` rows remain readable and editable. Phase 6 remains `PLANNED`.

## Architecture Decisions

- PHP 8.3+ dan Laravel 13.
- Blade + Vanilla JS untuk UI.
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
- [Local Development](docs/operations/LOCAL_DEVELOPMENT.md) - topologi worker, startup runbook, dan troubleshooting aman.
- [Database Backup & Recovery](docs/operations/DATABASE_BACKUP_RECOVERY.md) - kebijakan backup, restore konsisten, dan preservasi data persisten.
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

## Production queue workers

Material extraction stays on its existing worker. Do not change that worker or `database.retry_after` / `DB_QUEUE_RETRY_AFTER` (90).

Question generation and Material Profile analysis share connection `database-generation` (`GENERATION_QUEUE_RETRY_AFTER` 360). Profile jobs use queue `material-intelligence`, `$timeout = 270`, `$tries = 3`. Production must consume `material-intelligence`. The chosen operator command prioritizes question generation first:

```text
php artisan queue:work database-generation \
  --queue=question-generation,material-intelligence \
  --timeout=270 \
  --tries=3
```

Keep `retry_after` 360 greater than timeout 270. A dedicated `material-intelligence` worker with the same timeout and tries is also valid. Blueprint AI fill jobs use the same `material-intelligence` queue. Generation Run children use `question-generation`.

## Open Product Decisions

- Upload per file tetap maksimal 10 MB. Quota storage akun (Free 50 MiB / Pro 500 MiB total) adalah kontrol terpisah yang sudah ditegakkan dan tidak menggantikan batas per file.
- Format export pertama.
- Provider payment otomatis tetap post-MVP. Phase 3.6 memakai QRIS statis (public disk) dan konfirmasi WhatsApp manual. Phase 7 adalah WhatsApp CRM / broadcast.

Keputusan baru harus diselaraskan pada PRD, design, flow, database, roadmap, dan changelog.
