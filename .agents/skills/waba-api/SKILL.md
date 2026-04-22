```markdown
# waba-api Development Patterns

> Auto-generated skill from repository analysis

## Overview

This skill documents the development patterns, coding conventions, and key workflows for the `waba-api` TypeScript codebase. While no framework is detected, the repository follows clear conventions for file naming, code organization, and commit messages. It also defines repeatable workflows for adding database tables, repositories, middleware, and features, each with associated testing practices.

## Coding Conventions

- **File Naming:**  
  Use PascalCase for file names.  
  _Example:_  
  ```
  MessageService.ts
  UserController.ts
  ```

- **Import Style:**  
  Use relative imports for modules within the project.  
  _Example:_  
  ```typescript
  import { sendMessage } from './MessageService';
  ```

- **Export Style:**  
  Use named exports for all modules.  
  _Example:_  
  ```typescript
  // MessageService.ts
  export function sendMessage() { ... }
  ```

- **Commit Messages:**  
  Follow [Conventional Commits](https://www.conventionalcommits.org/) with prefixes such as `feat` and `test`.  
  _Example:_  
  ```
  feat: add message sending endpoint
  test: add tests for message validation
  ```

## Workflows

### Add Database Table, Model, and Factory
**Trigger:** When introducing a new entity/table to the database  
**Command:** `/new-table`

1. **Create a migration file** in `database/migrations/`  
   _Example:_  
   ```
   database/migrations/2024_06_01_000000_create_orders_table.php
   ```
2. **Create a model** in `app/Models/`  
   _Example:_  
   ```
   app/Models/Order.php
   ```
3. **Create a factory** in `database/factories/` for testing/seeding  
   _Example:_  
   ```
   database/factories/OrderFactory.php
   ```

---

### Add Restify Repository with Tests
**Trigger:** When exposing CRUD operations for a model via Restify API  
**Command:** `/new-restify-repo`

1. **Create a repository** in `app/Restify/`  
   _Example:_  
   ```
   app/Restify/OrderRepository.php
   ```
2. **Add or update policies** in `app/Policies/`  
   _Example:_  
   ```
   app/Policies/OrderPolicy.php
   ```
3. **Write feature tests** in `tests/Feature/Restify/`  
   _Example:_  
   ```
   tests/Feature/Restify/OrderRepositoryTest.php
   ```

---

### Add Middleware and Register
**Trigger:** When introducing new HTTP middleware for request handling  
**Command:** `/new-middleware`

1. **Create middleware** in `app/Http/Middleware/`  
   _Example:_  
   ```
   app/Http/Middleware/CheckOrderStatus.php
   ```
2. **Register middleware** in `bootstrap/app.php`  
   _Example:_  
   ```php
   // bootstrap/app.php
   $app->middleware([
       App\Http\Middleware\CheckOrderStatus::class,
   ]);
   ```

---

### Add Feature with Corresponding Tests
**Trigger:** When adding a new class or feature with test coverage  
**Command:** `/new-feature`

1. **Implement the feature or class** in `app/` or `app/Waba/`  
   _Example:_  
   ```
   app/Waba/NotificationService.php
   ```
2. **Write tests** in `tests/Unit/` or `tests/Feature/`  
   _Example:_  
   ```
   tests/Unit/NotificationServiceTest.php
   tests/Feature/NotificationFeatureTest.php
   ```

## Testing Patterns

- **Test File Naming:**  
  Test files use the pattern `*.test.ts`.  
  _Example:_  
  ```
  MessageService.test.ts
  ```

- **Test Location:**  
  Tests are typically placed in `tests/Unit/` or `tests/Feature/` directories, mirroring the structure of the code they test.

- **Testing Framework:**  
  The specific framework is not detected, but standard TypeScript testing practices apply.

- **Test Example:**  
  ```typescript
  // MessageService.test.ts
  import { sendMessage } from './MessageService';

  describe('sendMessage', () => {
    it('should send a message successfully', () => {
      expect(sendMessage('hello')).toBe(true);
    });
  });
  ```

## Commands

| Command             | Purpose                                                         |
|---------------------|-----------------------------------------------------------------|
| /new-table          | Add a new database table, model, and factory                    |
| /new-restify-repo   | Add a Restify repository with CRUD tests for a model            |
| /new-middleware     | Add and register new HTTP middleware                            |
| /new-feature        | Add a new feature or class with corresponding tests             |
```