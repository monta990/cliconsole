# CLI Console

CLI Console is a GLPI plugin that provides an authenticated web terminal for `bin/console` commands.

It is intended for shared hosting and managed servers where a GLPI administrator can use the web interface but cannot open an SSH or local shell session.

## Features

- Modern GLPI Controllers under `src/Controller/`.
- Twig-based UI.
- Real-time streamed command output in the browser.
- Configurable absolute PHP CLI executable path (for example `/usr/bin/php85`).
- Execution permanently bound to the current GLPI installation's real `bin/console`.
- `realpath()` validation rejects a `bin/console` that resolves outside `GLPI_ROOT/bin`.
- `proc_open()` receives an argument array with `bypass_shell`, so user input is not interpreted by a shell.
- Shell operators are explicitly rejected from the command field.
- Access restricted to GLPI's native Super-Admin profiles.
- GLPI 12 re-authentication (`sudo mode`) is requested before the sensitive console page is opened.
- English source strings and an `es_MX` translation catalog.
- No Composer or third-party runtime dependency.

## Requirements

- GLPI 11.x or 12.x.
- PHP 8.2 or newer.
- `proc_open()` must not be disabled for the PHP web SAPI.
- The configured PHP CLI executable must exist and be executable by the web server account.

## Installation

1. Extract the `cliconsole` directory into the GLPI `plugins` directory.
2. Open **Setup > Plugins**.
3. Install and activate **CLI Console**.
4. Open **Tools > CLI Console > Configuration** (or the plugin configuration entry, depending on the GLPI menu presentation).
5. Enter the absolute PHP CLI path. On the user's shared-hosting environment this can be, for example:

```text
/usr/bin/php85
```

6. Open **Tools > CLI Console** and type a GLPI command, for example:

```text
migration:timestamps
```

## Security boundary

CLI Console is **not** a general-purpose server shell.

The interface never asks the user for a script path. It always resolves `GLPI_ROOT/bin/console` and verifies that its real path is directly under the GLPI installation's `bin` directory. A symlink escaping that directory is rejected.

The PHP executable path is an administrator-controlled setting. It is used only as the executable in `proc_open()`; the script argument is always the resolved GLPI `bin/console`.

The user's input is parsed into arguments and passed to `proc_open()` as an array. No shell command string is constructed, so shell metacharacters are not interpreted. Common shell operators are also rejected as an additional defensive measure.

This does not make GLPI console commands harmless. A Super-Admin may still execute any command exposed by GLPI's own `bin/console`, including commands that alter configuration or data. Access is intentionally limited to GLPI's native Super-Admin profiles.

On GLPI 12, the page itself requests GLPI's re-authentication window before the console is available. If that window expires while the page is open, the streaming action is refused until the console page is reloaded and re-authenticated again.

## GLPI 11 compatibility

Controllers are supported by GLPI 11+. The execution endpoint is a `POST` route under `/ajax`, and includes the CSRF token expected by GLPI 11. The router issue affecting non-GET plugin routes was fixed in GLPI 11.0.7, so current 11.x releases are recommended.

## Uninstall

Deactivation removes the menu entry. Uninstallation removes the stored PHP CLI path. The plugin creates no database table and does not create a custom profile right.

## License

GPLv3+.

## GitHub version check

The configuration page includes an optional version check against the official GitHub repository at `https://github.com/monta990/cliconsole`. The check is performed server-side and fails gracefully when GitHub is unavailable or the repository does not yet expose a readable plugin version.
