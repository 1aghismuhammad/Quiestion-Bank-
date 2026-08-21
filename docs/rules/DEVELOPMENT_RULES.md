# Development Rules

## General
- Gunakan clean architecture.
- Setiap fitur wajib terdokumentasi.
- Perubahan besar harus melalui review.

## Database
- Gunakan migration.
- Semua FK memiliki index.
- Simpan audit AI generation.

## AI
Setiap request AI harus menyimpan:
- User
- Material
- Prompt Version
- Model
- Token Usage
- Cost
- Status