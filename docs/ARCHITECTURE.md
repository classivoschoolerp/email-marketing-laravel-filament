# Architecture

Code comments across the project cite these rules by number ("ARCHITECTURE.md rule 3").
Please keep the numbering stable when you edit this file.

## Architecture rules

1. **The sending engine lives outside Filament.** Sending runs on plain Laravel jobs, commands
   and services. Filament provides the admin UI only and never sits in the send path.
2. **Driver registry pattern** (spec §2.9.2, §6.1). Sending drivers, payment gateways, AI
   engines and verification services all implement an interface and register themselves.
   Adding a new vendor never requires a change to the core.
3. **Multi-tenancy is a global scope on `customer_id`**, applied at the model layer
   (`app/Models/Concerns/BelongsToCustomer.php`) and backed by policies. Data is never
   scoped by the UI alone.
4. **Three-tier roles** (spec §0.3): locked system roles → operator global roles →
   per-tenant custom roles. Every quota and permission hangs off this structure.
5. **Queues are mandatory** for campaign sends, imports, exports, bounce/FBL polling,
   webhooks, verification and automation ticks. Nothing bulk runs inside a web request.
6. **Every send job is idempotent.** A subscriber never receives the same campaign twice,
   even if a worker dies mid-batch.

## Conventions

- PHP 8.3, `declare(strict_types=1)` in every file.
- Layering: FormRequest → DTO → Service → Controller/Livewire (spec §0.1).
- Money is stored as integer minor units, never floats.
- All timestamps are UTC in the database and rendered in the user's timezone.
- `utf8mb4` everywhere; CJK and emoji are tested end to end.
- Tests use Pest. Every feature has at least one feature test.
- All strings go through `__()`. No hardcoded English in views or Filament labels.

## Databases

Tests run on SQLite by default. Production targets MySQL 8 (or MariaDB 10.6+) with Redis
recommended for queues and cache. Never write driver-specific SQL outside guarded blocks. CI
also runs the suite on MySQL. See [deploy-mysql.md](deploy-mysql.md).

## Reference documents

- [DECISIONS.md](DECISIONS.md): design decisions, cited in code as `D-nn`.
