---
name: add-restify-repository-with-tests
description: Workflow command scaffold for add-restify-repository-with-tests in waba-api.
allowed_tools: ["Bash", "Read", "Write", "Grep", "Glob"]
---

# /add-restify-repository-with-tests

Use this workflow when working on **add-restify-repository-with-tests** in `waba-api`.

## Goal

Adds a new Restify repository for an entity, with associated CRUD tests.

## Common Files

- `app/Restify/*.php`
- `app/Policies/*.php`
- `tests/Feature/Restify/*Test.php`

## Suggested Sequence

1. Understand the current state and failure mode before editing.
2. Make the smallest coherent change that satisfies the workflow goal.
3. Run the most relevant verification for touched files.
4. Summarize what changed and what still needs review.

## Typical Commit Signals

- Create a repository in app/Restify/
- Add related policies or update existing ones in app/Policies/
- Write feature tests in tests/Feature/Restify/

## Notes

- Treat this as a scaffold, not a hard-coded script.
- Update the command if the workflow evolves materially.