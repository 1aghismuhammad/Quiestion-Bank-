# Change Log

Perubahan dicatat berdasarkan keputusan dan artefak dokumentasi. Status `Added` pada fase perencanaan tidak berarti fitur sudah diimplementasikan.

## Entry Format

```text
Date:
Version:
Phase:
Type:

Added:
-

Changed:
-

Fixed:
-

Database Impact:
-

Notes:
-
```

## v0.6 Phase 2 Material Design Alignment

- Date: 24 August 2026
- Version: 0.6
- Phase: Phase 2 - Material Management
- Type: Canonical design decision

Added:

- Standalone Material Management flow from the user dashboard.
- Canonical PDF, DOCX, and TXT allowlist with a temporary 10 MB per-file limit.
- Owner restore transition from archived material back to ready.

Changed:

- Phase 2 remains Blade/controller based without Livewire.
- Material content uses LONGTEXT.
- Upload metadata requires internal path, size, MIME type, SHA-256 hash, and extraction status.
- Duplicate protection uses unique `(user_id, file_hash)`.
- Empty chapter and sub-chapter values normalize to non-null empty strings.
- Storage usage counts every non-deleted upload, including archived and extraction failed.
- Material lifecycle is `draft|ready -> archived` and `archived -> ready`.

Database Impact:

- Canonical `materials` and `material_topics` definitions are aligned before migrations are implemented.
- No migration or application code is included in this documentation-only decision.

Notes:

- Phase 2 remains `PLANNED`.
- PDF/DOCX extraction dependencies still require explicit approval before Composer changes.
- Plan-specific quota values remain deferred to Phase 3.

## v0.5 Phase 1 Authentication

- Date: 21 August 2026
- Version: 0.5
- Phase: Phase 1 - Authentication and User Management
- Type: Feature implementation

Added:

- Google OAuth-only authentication with Laravel Socialite.
- User provisioning and Google profile synchronization.
- USER and ADMIN roles with composite pivot relation.
- First-login phone setup backed by `whatsapp_contacts`.
- Account status, profile completeness, and role middleware.
- User and admin dashboard.
- Authentication, relationship, profile, and authorization tests.

Changed:

- Phase 1 uses Laravel conventional `id` primary keys.
- Free subscription provisioning is deferred to Phase 3.
- WhatsApp contact identity is created in Phase 1; CRM operations remain Phase 7.
- Socialite compatibility uses Guzzle 7 instead of the previous Guzzle 8 lock.

Database Impact:

- Added Google identity, profile, consent, status, and login fields to users.
- Removed local password and password reset storage.
- Added roles, role_user, and whatsapp_contacts.
- Framework sessions, cache, and jobs tables remain infrastructure tables.
- OAuth migration requires empty legacy authentication data and blocks rollback while users exist.

Notes:

- No material, subscription, AI, question bank, monitoring, or broadcast feature is implemented.
- Google credentials must be configured outside the repository.

## v0.4 System Design Alignment

- Date: 21 August 2026
- Version: 0.4
- Phase: Phase 0 - Foundation
Type: Documentation and database design

Added:

- Canonical DBML with 16 domain entities.
- Database enums, indexes, unique constraints, and relationship rules.
- Detailed user flow with OAuth, material, quota, queue, Gemini, review, and failure paths.
- Detailed admin flow with MVP management/monitoring plus the deferred Phase 7 broadcast branch.
- JSON output contract for multiple choice, true/false, and essay.
- Definition of Done for every development phase.

Changed:

- Authentication is explicitly Google OAuth only.
- Frontend is explicitly Blade + Livewire.
- Google Gemini is the MVP AI provider.
- AI generation is asynchronous and audited.
- Subscription/quota foundation is moved before AI generation.
- MVP boundary is Phase 0-6 with manual subscription handling.
- Admin uses the same OAuth flow and is authorized by role.
- Question output is discriminated by question type; essay no longer requires options.
- Institution features and WhatsApp CRM are post-MVP.

Fixed:

- Resolved conflict between essay questions and mandatory options.
- Resolved missing runtime quota source using `ai_usage_logs`.
- Resolved missing topic relation from generation to material topic.
- Resolved unclear distinction between assessment type and question type.
- Corrected Phase 0 status from implied completion to `IN PROGRESS`.

Database Impact:

- `users.google_id` and `email` are required and unique; no local password.
- Added enum-backed status/type domains.
- Added audit fields for Gemini response, token, cost, retry, and queue timestamps.
- Added subscription relation to AI usage for quota calculation.
- Added review fields to question sets.
- Added consent, opt-out, and provider tracking to WhatsApp/broadcast tables.

Notes:

- This release changes documentation and DBML only.
- Laravel migrations and application modules have not been implemented.
- Source design: `docs/database/AI_QUESTION_BANK.dbml`.

## v0.3 Documentation

- Date: Before 21 August 2026
- Version: 0.3
- Phase: Phase 0 - Foundation
Type: Documentation

Added:

- Initial PRD.
- Initial system design.
- Initial prompt engine rules.
- Initial development rules.

Notes:

- Documents were high-level and were expanded in v0.4.

## v0.2 Database Design

- Date: Before 21 August 2026
- Version: 0.2
- Phase: Phase 0 - Foundation
Type: Database planning

Added:

- List of 16 core entities.
- Subscription layer.
- CRM layer.

Notes:

- This version contained an entity inventory, not a complete executable schema.

## v0.1 Project Planning

- Date: Before 21 August 2026
- Version: 0.1
- Phase: Phase 0 - Foundation
Type: Product planning

Added:

- Product concept.
- Initial user flow.
- Initial admin flow.
- Initial database direction.