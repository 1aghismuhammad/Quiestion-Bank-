# Development Rules

## General

- Maintain clean architecture.
- Every feature must have documentation.
- Avoid unnecessary complexity.

## Database

- Every table must have primary key.
- Foreign key must be indexed.
- Use timestamps.
- Keep audit history for AI processes.

## Backend

- Separate Controller, Service, Repository.
- Business logic should not live in Controller.

## AI Integration

Every AI request must store:
- User
- Material
- Prompt Version
- Model
- Token Usage
- Cost
- Output Status