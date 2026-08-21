# System Design

## Architecture Overview

Frontend
|
Backend Laravel API
|
Database
|
AI Provider
|
Storage


## Main Modules

1. Authentication
- Google OAuth
- User profile
- Role management

2. Material Management
- Upload material
- Text input
- Topic extraction

3. AI Generation Engine
- Prompt versioning
- AI model integration
- Output validation

4. Question Bank
- Question sets
- Questions
- Options

5. Subscription
- Plans
- Quota tracking

6. CRM
- WhatsApp contact
- Broadcast campaign


## Design Principle

Simple user experience with complex backend orchestration.

User only configures:
- Material
- Assessment
- Difficulty

System handles:
- Prompt construction
- AI request
- Storage
- Logging