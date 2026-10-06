# INTL Directory v1 — Agent Runbook

## Purpose
Implement the post-baseline `Directory v1` capability on top of the accepted `INTL Platform v0.2` baseline.

This is NOT Phase 9 and must not reopen/redesign the accepted v0.2 platform. It is a new bounded capability added after the frozen baseline.

Expected current verified baseline:
- Root: 238 tests / 670 assertions
- Sandbox: 271 tests / 768 assertions
- MariaDB: 10.6 verified
- Accepted marker name: `intl-platform-v0.2-baseline`

## Execution mode
Work continuously, task by task, until all tasks in `02_TASKS.md` are complete or a documented HARD STOP occurs.

At the start:
1. Read repository `AGENTS.md` if present and obey it.
2. Read this file.
3. Read `01_DIRECTORY_V1_SPEC.md`.
4. Read `02_TASKS.md`.
5. Read `03_ACCEPTANCE.md`.
6. Read/update `04_STATE.md`.

Do not ask the user to relay intermediate steps unless a HARD STOP is reached.

After each completed task:
- update `04_STATE.md`;
- record files changed;
- record targeted verification commands/results;
- select the next eligible task and continue.

## Token-efficiency rules
- Do not repeat a repository-wide architecture audit.
- Do one targeted preflight only.
- Prefer targeted search/read of relevant files over scanning the full repository.
- Do not re-read stable spec files after every task; use `04_STATE.md` to resume.
- Do not run Root + full Sandbox after each task.
- Run targeted tests per task, then full suites once near final verification.
- Reuse already-verified v0.2 invariants unless this capability directly touches them.
- Keep console narration concise; prioritize implementation and verification.
- Do not produce long explanations while executing. The final report is enough.
- Do not modify unrelated packages/files merely for cleanup.

## Existing baseline ownership
Preserve all user changes and unrelated unstaged files.

Never overwrite or reset:
- `AGENTS.md`;
- unrelated user modifications;
- previously accepted package behavior.

If a file that must be changed already contains unexpected user modifications, inspect and merge conservatively. Do not discard user work.

## Scope
Create/implement:
- `intl/directory`
- `intl/directory-laravel`

Minimal integration changes are allowed only where required to connect Directory to the existing authentication/principal/ActorContext flow.

Directory v1 owns:
- Organization
- OrganizationRole
- User
- Membership
- source/external reference mapping needed for identity resolution
- active/inactive lifecycle for those records
- deterministic fail-closed Directory resolution

Directory v1 does NOT own:
- authentication credentials or password verification;
- External Auth ticket/session validation;
- Authorization roles/permissions;
- Audit history;
- Reporting/analytics;
- invitations/team management;
- tenancy;
- ServiceRecipient;
- business request ownership semantics outside integration references.

## Core separation
Keep this direction:

Authentication
    -> authenticated identity/reference
    -> Directory mapping/resolution
    -> local Organization + optional User
    -> Principal / ActorContext
    -> Authorization

Never reverse it.

Directory must not authenticate users.
Directory must not infer permissions.
OrganizationRole must not grant Authorization permissions.
External claims must not create OrganizationRole or local permissions.

## HARD STOP conditions
Stop and report clearly only if one of these occurs:
1. The current repository does not match the accepted v0.2 baseline in a way that materially blocks Directory verification.
2. Existing `ActorContext` / `Principal` contracts make the required model impossible without a breaking change that cannot be made additively.
3. The same authenticated source reference is intentionally required by existing production behavior to resolve to multiple conflicting Directory targets and no explicit context exists to disambiguate it.
4. Required MariaDB verification infrastructure is unavailable and cannot be started using existing project tooling.
5. A required change would overwrite unresolved user modifications.
6. A real architectural contradiction exists that requires a product decision not defined in `01_DIRECTORY_V1_SPEC.md`.

Do NOT stop for:
- ordinary test failures you can diagnose;
- migration/test fixture issues;
- naming choices already defined in the spec;
- implementation details that can be resolved from repository conventions.

## Completion
Do not stop after package scaffolding.

Completion requires:
- pure package;
- Laravel bridge;
- migration/persistence;
- resolution;
- minimal auth/principal integration;
- required reference scenarios;
- MariaDB migration verification;
- full regression;
- documentation update;
- final status.

End final report with exactly one:

`DIRECTORY V1 STATUS: PASS`

or

`DIRECTORY V1 STATUS: NOT READY`
