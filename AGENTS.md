# C+CLub
- Source of truth: docs/requirements.md (user-provided specification).
- All dependency resolution, builds, migrations and tests MUST run in Docker, never host PHP/npm.
- Read docs/implementation-status.md before continuing.
- Preserve user changes. Never invent lock files, successful tests or external integrations.
- PostgreSQL tests must use cclub_test, never development/production databases.
- Keep .env, tokens, uploads, dumps, logs and dependencies out of Git.
- Use policies for every object and explicit membership filtering for collections.
- Laravel 13, PHP 8.4, Vue/TypeScript/Inertia, PostgreSQL, Redis/Horizon; modular monolith.
- The upstream skeleton's host-install/Boost instructions were replaced to respect the user's Docker-only requirement.
