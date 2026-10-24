# Security Policy

At SheetGate, the security of self-hosted Google Sheet integrations and sensitive spreadsheet data is of paramount importance. This document outlines our security architecture, supported versions, and how to responsibly disclose vulnerabilities.

---

## Supported Versions

Only the latest major version receives security updates and patches.

| Version | Supported |
|---|---|
| `1.x.x` | :white_check_mark: |
| `< 1.0` | :x: |

---

## Security Architecture & Defenses

SheetGate was designed with defense-in-depth principles:

### 1. Read-Only by Design
- SheetGate is structurally incapable of writing back to connected Google Sheets.
- No code path exists to insert, update, or delete cells, rows, or worksheets.
- OAuth 2.0 scopes are strictly restricted to read-only permissions (`https://www.googleapis.com/auth/spreadsheets.readonly` and `drive.readonly`).

### 2. Server-Side Request Forgery (SSRF) Protection
- Google Sheet URLs submitted for public endpoints are validated with strict regex patterns.
- URL schemes are restricted to `https://`.
- Hostnames are validated against an allowlist permitting only `docs.google.com` and `sheets.googleapis.com`.
- cURL redirect tracking inspects the effective final host to block open redirect attacks to local/internal IP ranges (e.g., `127.0.0.1`, `169.254.169.254`, private subnets).

### 3. API Key & Credential Hashing
- Per-endpoint Bearer API keys (`sg_live_...`) are generated using cryptographically secure random bytes (`random_bytes(24)`).
- Plaintext API keys are displayed **only once** to the administrator upon generation and are never stored in plaintext.
- Keys are hashed with **SHA-256** prior to database storage.
- Admin master passwords are encrypted using PHP's native `password_hash()` with `PASSWORD_BCRYPT`.

### 4. OAuth Token Encryption at Rest
- Google OAuth 2.0 access tokens and refresh tokens are encrypted at rest using **Libsodium authenticated symmetric encryption** (`sodium_crypto_secretbox`).
- Encryption keys are derived from the 256-bit master key (`SHEETGATE_APP_KEY`).

### 5. SQL Injection Prevention
- All database interactions with SQLite use PDO prepared statements with parameterized inputs.
- No raw string interpolation is performed in SQL queries.

### 6. Rate Limiting & DoS Protection
- Per-endpoint sliding window rate limiting tracks requests per client IP or Bearer token.
- Minimum TTL floor (30 seconds) prevents clients from hammering Google endpoints.
- Request coalescing (mutex locking) prevents cache stampedes / thundering herd problems when an endpoint's cache expires.

### 7. Cross-Site Request Forgery (CSRF) & Session Security
- Admin API state changes (`POST`, `PUT`, `DELETE`) require a valid `X-CSRF-TOKEN` header validated against the active session.
- Session cookies are set with `HttpOnly`, `SameSite=Lax`, and secure lifetime flags.

### 8. Schema Drift & Lock Protection
- Column signatures are snapshotted on each sync.
- Endpoints configured in "Schema Lock" mode immediately return `409 Conflict` if the column structure shifts, preventing downstream applications from ingesting corrupted data schemas.

---

## Reporting a Vulnerability

If you discover a security vulnerability in SheetGate, please do **not** open a public issue.

### Disclosure Process:
1. **Report**: Submit a report via GitHub's [Private Vulnerability Reporting](https://github.com/spidervirus/sheetgate/security/advisories/new)
2. **Details**: Please include:
   - A description of the vulnerability and its potential impact.
   - Step-by-step instructions or proof-of-concept script to reproduce.
   - Affected versions and configurations.
3. **Response Timeline**:
   - **Acknowledgement**: Within 48 hours of receipt.
   - **Triage & Patch**: We aim to validate and release a fix within 7 days for critical issues.
   - **Public Advisory**: Published after a fix is available with credit to the reporter.

Thank you for helping keep SheetGate and our community secure!
