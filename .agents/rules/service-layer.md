---
description: Service layer for business logic and orchestration
alwaysApply: true
---

# Service Layer

Services own business rules, orchestration, and side effects. Controllers stay thin and HTTP-focused.

Prefer **Service classes** over `*Action` classes for domain workflows in this project.

## Structure

```
app/Services/{Domain}/{Domain}Service.php
```

Examples: `app/Services/User/UserService.php`, `app/Services/Order/OrderService.php`.

Do not place services under `app/Domains/`.

## Conventions

- One primary service per domain (`UserService`, `OrderService`); add focused collaborators under the same folder when the domain grows
- Constructor-inject repository **interfaces** and external contracts — never `app()` or `resolve()`
- Accept validated arrays or DTOs — never `Request` objects
- Wrap multi-step writes in `DB::transaction()`
- Dispatch events/notifications via `DB::afterCommit()` (or `ShouldDispatchAfterCommit`) — not mid-transaction, and not from controllers/repositories
- Orchestrate multiple repositories when a workflow spans entities
- Reuse the same service methods from jobs and commands

## Does / Does Not

| Does | Does not |
| --- | --- |
| Business rules, workflows, transactions | HTTP responses, status codes, headers |
| Coordinate repositories and external APIs | Eloquent queries or `Model::` calls |
| Fire domain events after successful commit | Input validation (use Form Requests upstream) |
| Enforce domain invariants / throw domain exceptions | Authorization checks that belong in policies/gates |

## Controller Flow

```
// Writes & business operations
Form Request → Controller → Service → Repository → API Resource

// Simple reads (listing, filtering)
Form Request → Controller → Repository → API Resource
```

API controllers **accept Form Requests** (never inline `$request->validate()`) and **return API Resources** (never raw models/arrays via `response()->json($model)`). Exception: tiny non-entity payloads like `{ "ok": true }`.

## Quick Check

- Logic decides *what should happen*? → Service
- Logic decides *how to fetch/store data*? → Repository
- Controller has `Model::`, `DB::`, or `event()`? → Move to service or repository
- Controller validates inline or returns raw JSON for an entity? → Form Request + API Resource

For full examples, activate the `service-layer` skill.
