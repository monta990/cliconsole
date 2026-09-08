# Changelog - CLI Console

All notable changes to this project are documented in this file.

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

Versioning follows [Semantic Versioning](https://semver.org/).

## [1.0.1] - 2026-09-07

### Security

- Fixed short password-option redaction so attached values such as `-pSECRET` are never written to the audit log.
- Removed an unused local variable from command redaction logic.
- Hardened audit-log writes to require complete line writes and successful flushes.
- Added exception-safe worker finalization so unexpected failures terminate child processes when needed, release locks, close resources, remove worker markers, and emit a final audit event when possible.
- Preserved the standard GLPI audit log during uninstall while cleaning plugin-owned temporary session and version-cache data when no worker is active.
- Documented supported Unix/Linux environments.
- Fixed the command shell-operator validation regex that could cause a PCRE compilation warning at runtime.
- Correctly rejects shell metacharacters, backslashes, and NUL bytes without blocking valid command characters such as `x` or `0`.
- Made GLPI 12 re-authentication fail closed when `ReAuthManager` is unavailable, for both console and configuration routes.
- Validate the configured PHP executable as the actual PHP CLI interpreter before storing it and again before use; store its resolved real path.
- Added the resolved PHP binary to the audit log.
- Expanded command redaction to cover GLPI password and credential options such as `-p`, `--pass`, and `--db-password`.
- Corrected shell-operator parsing so ordinary `x` and `0` characters are accepted while NUL, backslash, and shell metacharacters remain blocked.
- Hardened audit-log creation and rotation to require restrictive `0600` permissions and to avoid silent permission failures.
- Added a per-session owner binding to prevent one Super-Admin from operating another user's session when a session identifier is known.
- Changed the output polling endpoint to POST so terminal session identifiers are not exposed in query strings or normal web access logs.

### Code quality

- Centralized worker limits in a shared `RuntimeLimits` class.
- Removed the unused execution-time constant from `CliConsole`.
- Centralized audit-log writing in `AuditLogger` instead of maintaining divergent controller/worker implementations.
- Centralized PHP CLI validation in `PhpCliValidator`.
- Cached the Super-Admin profile check for the current request.

## [1.0.0] - 2026-09-06

### Security hardening

- Added execution, command, argument, input, queue, output, and concurrency limits.
- Prevented reuse of an initialized terminal session with an atomic worker marker and lock.
- Reworked session cleanup to protect live workers using a filesystem lock and heartbeat-based stale-session handling.
- Added a protected JSON Lines audit log containing timestamp, GLPI user ID, username, sanitized command, session ID, final status, exit code, and duration.
- Added size-based audit log rotation.
- Audit events are written to GLPI's standard `GLPI_LOG_DIR/cliconsole.log` using a shared filesystem lock for concurrent writers.
- Made the active-session limit allocation atomic to prevent concurrent requests from exceeding the configured limit.
- Ready sessions reserve a slot immediately and expire after 15 minutes if not started.

### Added

- Accept GLPI console commands entered either with or without the `glpi:` prefix.
- Initial stable release of CLI Console.
- Modern GLPI Controllers under `src/Controller/`.
- Native GLPI Super-Admin access control.
- Web terminal for GLPI `bin/console` commands without direct shell access.
- Configurable absolute PHP CLI executable path.
- Execution permanently bound to the current GLPI installation's real `GLPI_ROOT/bin/console`.
- `realpath()` validation to reject a `bin/console` outside the current GLPI installation.
- Explicit rejection of shell operators in the command input.
- Interactive command input through authenticated HTTP requests.
- Process stop and EOF controls.
- Session isolation using random 256-bit session identifiers.
- GLPI 12 re-authentication support for sensitive console access.
- GitHub release version checking with HTTPS validation and cached results.
- Official GLPI CLI documentation link from the console interface.

### Compatibility

- Initial release targets GLPI 12.x only.
