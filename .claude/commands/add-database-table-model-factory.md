---
name: add-database-table-model-factory
description: Workflow command scaffold for add-database-table-model-factory in waba-api.
allowed_tools: ["Bash", "Read", "Write", "Grep", "Glob"]
---

# /add-database-table-model-factory

Use this workflow when working on **add-database-table-model-factory** in `waba-api`.

## Goal

Adds a new database table with its Eloquent model and factory for testing/seeding.

## Common Files

- `database/migrations/*_create_*_table.php`
- `app/Models/*.php`
- `database/factories/*Factory.php`

## Suggested Sequence

1. Understand the current state and failure mode before editing.
2. Make the smallest coherent change that satisfies the workflow goal.
3. Run the most relevant verification for touched files.
4. Summarize what changed and what still needs review.

## Typical Commit Signals

- Create a migration file in database/migrations/
- Create a model in app/Models/
- Create a factory in database/factories/

## Notes

- Treat this as a scaffold, not a hard-coded script.
- Update the command if the workflow evolves materially.