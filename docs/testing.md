# SaferNET Testing & Quality Assurance Guide

## 1. Test Philosophy & Automated Verification

SaferNET validates correctness across all three software deliverables:
1. **Laravel Platform**: PostgreSQL-backed feature and unit tests.
2. **Browser Extension**: Manifest integrity checks, ES syntax parsing, and ESLint rule audits.
3. **Windows Endpoint Agent**: Multi-threaded xUnit tests verifying packet parsing, DNS resolution, and cache resilience.

---

## 2. Running Test Suites

### 2.1 Backend Tests (PHP & Laravel)
```bash
# Run Pint syntax formatting check
vendor/bin/pint --test

# Run feature test suite against PostgreSQL
php artisan test
```

### 2.2 Frontend & Extension Audits (JavaScript)
```bash
# Runs ESLint, extension syntax check, manifest verification, and Vite build
npm run check
```

Sub-commands:
- `npm run lint`: Runs ESLint on all React components and extension scripts.
- `npm run check:extension`: Validates syntax of `background.js`, `content.js`, `options.js`, `popup.js`, and `blocked.js`.
- `npm run check:manifest`: Asserts all files declared in `manifest.json` exist on disk.
- `npm run build`: Executes Vite production bundling.

### 2.3 Windows Agent Tests (.NET)
```bash
# Run agent xUnit test suite
dotnet test agent/windows/tests/SaferNet.Agent.Tests --configuration Release --nologo
```
*Current test suite status: 56 unit/integration tests passing (0 failures, 0 skipped).*
