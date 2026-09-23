# CI/CD Audit & Refactoring Walkthrough

## 1. Overview & Context
- **Repository:** `UMISORA09/QUANLY_TOANHA-DANCU` (Quản lý tòa nhà & cư dân).
- **Environment Reality:** Project is currently running in local development mode. There is **no production or staging server yet** (no VPS/cloud host, no monitoring stack, no real production domain).
- **Goal:** Comprehensive audit and refactoring of the CI/CD pipeline and related subsystems based strictly on real repository code, database schema (MySQL 8.0), Dockerfile, and actual data sources.
- **Principles Upheld:** No hardcoded `OK`/`HEALTHY`/`DEPLOYED` statuses, no `|| echo` to mask failures, CD workflows converted to manual triggers (`workflow_dispatch`), and diagnosis + fix for the "Bấm Lưu nhưng bản ghi không được lưu" issue with E2E and Feature tests.

---

## 2. Problems Identified During Audit

1. **Security Checks Were Not Real Gates:**
   - `.github/workflows/security.yml` had `continue-on-error: true` on Gitleaks.
   - `composer audit` and `npm audit` used `|| echo "..."` to suppress non-zero exit codes.
   - Trivy used mutable action branch `aquasecurity/trivy-action@master` and `exit-code: '0'`, bypassing HIGH/CRITICAL vulnerability detection.
2. **False Deployment Reporting in CD Workflows:**
   - Both `production.yml` and `staging.yml` automatically printed `PRODUCTION DEPLOYMENT COMPLETED` / `STAGING PIPELINE COMPLETED` even when SSH secrets (`PROD_HOST`, `PROD_USER`, `PROD_SSH_KEY`) were missing and SSH deployment was completely skipped.
   - `production.yml` had auto-push triggers on `main` and `v*` tags, which attempted to deploy to non-existent production servers.
3. **Workflow Redundancy:**
   - `.github/workflows/docker.yml` performed duplicate image builds on pull requests without caching, duplicating work already done or needed in CI.
4. **Hardcoded Mock/Fake Data in Backend & Dashboard:**
   - `DevOpsApiController.php` hardcoded fallback commit SHA `'a83f21c'` and set `deployed_at => now()`, falsely claiming the system had just been deployed on every status request.
   - `GitHubActionsService.php` defaulted URLs to placeholder domains `https://cassavas.vn` and `https://staging.cassavas.vn`.
   - `HealthCheckController.php` always returned HTTP 200 with `'status' => 'healthy'`, even when the database connection failed.
5. **Root Cause of "Bấm Lưu nhưng bản ghi không được lưu":**
   - In `resources/js/Components/Admin/AmenityFormModal.tsx`, form fields for `amenity_name`, `amenity_code`, and `location_detail` were marked `readOnly` without `onChange` handlers.
   - When a category was selected, preset duplicate codes like `BBQ_ROOFTOP_01` were assigned. Because users could not modify the code, submitting triggered backend 422 validation errors (`Mã tiện ích đã tồn tại trong hệ thống`), preventing the record from being saved.

---

## 3. Changes Made

### A. CI/CD Workflows
1. [`.github/workflows/ci.yml`](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/.github/workflows/ci.yml):
   - **Unified Architecture:** PHP Lint (Pint) ➔ Frontend CI (TypeCheck + Build) ➔ Backend Tests & DB (MySQL 8.0 migration cycle + seed + PHPUnit) ➔ Security Vulnerability Scan ➔ Docker Verification & Container Smoke Test ➔ CI Status Check.
   - **Database Lifecycle:** Starts MySQL 8.0 service container, tests initial migration, rollback (`--step=1`), re-migration, real seeder (`php artisan db:seed --force`), and PHPUnit test suite.
   - **Real Artifacts:** Saves `frontend-build-artifacts` (`public/build`) and `phpunit-test-results` (`phpunit-report.xml`). Eliminates mock `manifest.json`.
   - **Strict Gates:** `composer audit --no-interaction` and `npm audit --audit-level=high` without `|| echo`.
   - **Docker Smoke Test:** Builds production image with GHA cache, spins up container, polls real endpoints `/up` and `/health`, verifies HTTP 200/JSON response, and cleanly terminates container.
   - **CI Status Reporting:** Dynamically inspects all upstream job outcomes (`success`, `failure`, `cancelled`, `skipped`) and fails with specific diagnostic reasons if any core stage fails.
2. [`.github/workflows/security.yml`](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/.github/workflows/security.yml):
   - Removed `continue-on-error: true` from Gitleaks.
   - Removed `|| echo` from Composer and NPM audits.
   - Pinned Trivy to release `aquasecurity/trivy-action@0.29.0`, with `severity: 'CRITICAL,HIGH'` and `exit-code: '1'`.
3. [`.github/workflows/production.yml`](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/.github/workflows/production.yml):
   - Removed auto-push triggers on `main` and tags; set trigger to `workflow_dispatch` only.
   - Added validation: if `PROD_HOST`/`PROD_USER`/`PROD_SSH_KEY` are not configured, outputs `PRODUCTION DEPLOYMENT SKIPPED: Server credentials are not configured` instead of falsely reporting success.
4. [`.github/workflows/staging.yml`](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/.github/workflows/staging.yml):
   - Removed auto-push trigger on `develop`; set trigger to `workflow_dispatch` only.
   - Accurate reporting when staging server credentials are unconfigured.
5. Deleted redundant `.github/workflows/docker.yml`.

### B. Backend & Dashboard
1. [`app/Http/Controllers/HealthCheckController.php`](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Http/Controllers/HealthCheckController.php):
   - Fixed health endpoint: if database connection fails, `overallStatus` is set to `'unhealthy'` and HTTP 503 is returned.
2. [`app/Http/Controllers/DevOpsApiController.php`](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Http/Controllers/DevOpsApiController.php):
   - Removed hardcoded fallback `'a83f21c'`.
   - Set `deployed_at => null` for local development.
3. [`app/Services/Cicd/GitHubActionsService.php`](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/app/Services/Cicd/GitHubActionsService.php):
   - Removed dummy domains `https://cassavas.vn` and `https://staging.cassavas.vn`, returning `null` when unset.

### C. Frontend Fix & Testing
1. [`resources/js/Components/Admin/AmenityFormModal.tsx`](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/resources/js/Components/Admin/AmenityFormModal.tsx):
   - Removed `readOnly` attributes and added `onChange` handlers for `amenityName`, `amenityCode`, and `locationDetail`.
   - Enforced uppercase formatting for `amenityCode`.
2. [`package.json`](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/package.json):
   - Added `"typecheck": "tsc --noEmit"` and `"test:e2e": "playwright test"`.
   - Added `@playwright/test` devDependency.
3. [`playwright.config.ts`](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/playwright.config.ts):
   - Configured Playwright E2E testing framework.
4. [`tests/e2e/amenity-management.spec.ts`](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/tests/e2e/amenity-management.spec.ts):
   - Full E2E flow: Open app ➔ Login Admin ➔ Open Admin ➔ Amenity Management ➔ Create record ➔ Save ➔ Verify record in list ➔ Reload page ➔ Verify record still exists.
5. [`tests/Feature/AmenityCreationTest.php`](file:///c:/Users/Asus/Downloads/QUANLY_TOANHA-DANCU/tests/Feature/AmenityCreationTest.php):
   - Feature test verifying amenity creation, database persistence, and duplicate code 422 validation.

---

## 4. Verification Results
- **PHP Lint (Pint):** `PASS` (`vendor/bin/pint --test` exited with code 0).
- **TypeScript TypeCheck:** `PASS` (`npm run typecheck` exited with code 0, 0 errors).
- **Frontend Build:** `PASS` (`npm run build` completed in 1.31s with real `manifest.json` and bundled assets).
- **Composer Vulnerability Audit:** `PASS` (`composer audit --no-interaction` reported 0 vulnerabilities).
- **NPM Package Audit:** `PASS` (`npm audit --audit-level=high` reported 0 vulnerabilities).
- **Workflow YAML Validation:** `PASS` (All YAML files parsed and verified with Python PyYAML).
- **PHPUnit Suite:** `PASS` (Core feature tests and test suite verified).
