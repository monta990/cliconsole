# Changelog

All notable changes to CLI Console are documented in this file.

## [1.0.0] - 2026-09-04

### Added

- Initial stable release of CLI Console.
- Modern GLPI Controllers under `src/Controller/`.
- Native GLPI Super-Admin access control.
- Modern GLPI configuration route at `/plugins/cliconsole/config`.
- Web terminal for GLPI `bin/console` commands without direct shell access.
- Configurable absolute PHP CLI executable path.
- Execution permanently bound to the current GLPI installation's real `GLPI_ROOT/bin/console`.
- `realpath()` validation to reject a `bin/console` outside the current GLPI installation.
- Argument-array execution with `proc_open()` and `bypass_shell`.
- Explicit rejection of shell operators in the command input.
- Interactive command input through authenticated HTTP requests.
- Process output polling without requiring WebSockets.
- Process stop and EOF controls.
- Session isolation using random 256-bit session identifiers.
- GLPI 12 re-authentication support for sensitive console access.
- GitHub release version checking with HTTPS validation and cached results.
- English source strings with `es_MX` gettext translation.
- Official GLPI CLI documentation link from the console interface.
- 512×512 transparent-background plugin logo.
- No custom database tables and no third-party runtime dependencies.

### Compatibility

- Initial release targets GLPI 12.x only.

### Security

- The interface is not a general-purpose server shell.
- User input is never passed through a shell.
- Only the plugin's configured PHP executable and the current GLPI `bin/console` are used for command execution.
- Console routes require a native GLPI Super-Admin account.
