# Changelog - CLI Console

All notable changes to this project are documented in this file.

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

Versioning follows [Semantic Versioning](https://semver.org/).

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
