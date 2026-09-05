# Roadmap

## Laravel rewrite

The current plain-PHP architecture will be frozen as the final stable legacy
line. The replacement will be developed as a separate Laravel application and
released only after feature parity and production-like cutover validation are
complete. It will start with fresh operational data; no legacy data migration
or production rollback path is planned.

See the [Laravel rewrite plan](laravel-rewrite.md).

## Authentication

### TODO: Replace the custom Google OAuth client with a maintained package

**Status:** Blocked on dependency compatibility.

Revisit `league/oauth2-google` (or another actively maintained OpenID Connect
client) once it supports this project's PHP 8.5 and Guzzle 8 dependency stack
without requiring a downgrade. The current custom implementation in
`src/GoogleAuth.php` exists because the available package dependency chain is
not compatible with those versions.

Migration acceptance criteria:

- Add the package through Composer without downgrading PHP, Guzzle, or other direct dependencies.
- Replace the custom authorization URL, token exchange, and userinfo HTTP code in `GoogleAuth` with a thin package adapter.
- Keep `GoogleAuthFlow` and its one-time, 10-minute state handling unless the replacement offers equivalent testable behavior.
- Preserve PKCE, exact redirect URI handling, verified-email checks, and server-side authorization against Google's Workspace `hd` claim.
- Preserve multi-domain allowlisting, RBAC role assignment, Google-only mode, and the dedicated access-denied page.
- Port the existing mocked success and failure tests to the package adapter; no test may require a live Google request.
- Run the full PHPUnit suite, frontend build, Composer validation, and dependency security audits before removing the custom code.
