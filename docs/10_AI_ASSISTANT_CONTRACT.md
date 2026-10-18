# BMS AI Assistant Contract

## Purpose

AI helps users prepare management data and decisions.
AI does not become a parallel system of record.

## Required flow

1. Domain resolves company and user permissions.
2. Domain prepares bounded context.
3. AI orchestration receives only authorized context.
4. AI returns structured proposal.
5. User reviews.
6. User confirms.
7. Canonical domain service validates.
8. Domain service writes.
9. Audit records the action as appropriate.

## Forbidden

- direct AI SQL writes
- bypassing domain validation
- cross-company retrieval
- hidden autonomous lifecycle transitions
- inventing canonical IDs
- letting AI-generated labels become permanent identity
- exposing secrets to model context

## Preferred response form

Structured proposal:
- action type
- target canonical UUID
- proposed values
- rationale
- warnings
- fields requiring user confirmation

Domain service is final authority.

## BMOS future extension (proposal, not implemented)

Digital functional posts and agent teams are future separate release modules with human accountability, permission-scoped tool access, limits, traceability and explicit approval for high-risk actions. Do not treat registering an AI agent as granting Moodle RBAC or bypassing human confirmation. See `docs/18_BMOS_AI_AGENTS_FUTURE.md`.
