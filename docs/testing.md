# Testing Strategy & Architecture Guardrails

## 1. Testing Philosophy

The platform enforces high test reliability through multi-tiered testing:
1. **Core Domain Unit Tests**: 100% in-memory, zero database/framework dependencies, fast execution.
2. **Adapter Feature Tests**: Orchestrated integration tests with database and framework container.
3. **Sandbox E2E Scenario Tests**: Complete multi-capability scenarios (NCR, Insurance, Legal, Tax).
4. **Architecture Guardrail Tests**: AST and regex-based static analysis enforcing layering, zero adapter-to-adapter coupling, zero foreign table access, and dependency declarations.
5. **Real Relational Database Verification**: Validates all database migrations from scratch against real MariaDB.

---

## 2. Test Execution Commands

### Running All Unit & Feature Tests
```bash
php C:\Users\ahmad\composer.phar test
```

### Running Architecture Guardrail Tests
```bash
php vendor/bin/phpunit --testsuite Architecture
```

### Running Sandbox Host Tests (SQLite)
```bash
cd apps/sandbox
php ../../vendor/bin/phpunit
```

### Running Sandbox Host Tests (MariaDB)
```bash
cd apps/sandbox
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3307 DB_DATABASE=intl_sandbox DB_USERNAME=intl_test DB_PASSWORD=intl_pass php ../../vendor/bin/phpunit
```

---

## 3. Architecture Guardrails Enforced by CI

The test suite in `tests/Architecture/` automatically verifies:
- **Core Package Isolation**: Core packages import no Laravel/Illuminate or adapter namespaces.
- **Zero Adapter-to-Adapter Coupling**: No `*-laravel` package imports another `*-laravel` package.
- **Zero Foreign Table Access**: No package executes queries against tables owned by other capabilities.
- **Explicit Composer Dependencies**: Every imported class from other monorepo packages is declared in `composer.json`.
- **Zero Circular Dependencies**: Dependency graph is strictly acyclic.
- **Fail-Closed Runtime**: Auth, directory, and payment adapters fail closed when misconfigured.
