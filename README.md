<p align="center">
  <img src="https://raw.githubusercontent.com/monta990/cliconsole/main/logo.png" alt="Responsivas logo" width="96">
</p>
<h1 align="center">CLI Console</h1>
<p align="center">
  <strong>GLPI plugin — Automatically generates PDF responsibility documents and loan contracts for IT assets assigned to users</strong>
</p>
<p align="center">
  <a href="https://github.com/glpi-project/glpi" target="_blank"><img src="https://img.shields.io/badge/GLPI-12.0%2B-blue" alt="GLPI compatibility"></a>
  <a href="https://www.gnu.org/licenses/gpl-3.0.html" target="_blank"><img src="https://img.shields.io/badge/License-GPL%20v3%2B-green" alt="License"></a>
  <a href="https://php.net/" target="_blank"><img src="https://img.shields.io/badge/PHP-%3E%3D8.2-purple" alt="PHP"></a>
  <a href="https://github.com/monta990/cliconsole/releases" target="_blank"><img alt="GitHub Downloads (all assets, all releases)" src="https://img.shields.io/github/downloads/monta990/cliconsole/total"></a>
</p>

---

## Overview

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
- English source strings and an Spanish México translation catalog.
- No Composer or third-party runtime dependency.

## Requirements

- GLPI 12.x only.
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

## GLPI 12 compatibility

CLI Console targets GLPI 12.x only and uses the native GLPI re-authentication (`sudo mode`) available in GLPI 12 for sensitive console access. The plugin uses modern Controllers and GLPI 12's native request protections.

## Uninstall

Deactivation removes the menu entry. Uninstallation removes the stored PHP CLI path. The plugin creates no database table and does not create a custom profile right.

## License

GPLv3+.

## GitHub version check

The configuration page includes an optional version check against the official GitHub repository at `https://github.com/monta990/cliconsole`. The check is performed server-side and fails gracefully when GitHub is unavailable or the repository does not yet expose a readable plugin version.

### Interactive terminal

CLI Console uses a long-lived HTTP streaming request for the running
`bin/console` process and separate authenticated HTTP requests for terminal
input and control actions. It releases the PHP session lock before the
long-running request so the same GLPI session can submit input concurrently.
This avoids requiring a WebSocket server or an external worker daemon and is
suitable for shared hosting.

## Marketplace release

The Marketplace metadata is provided in `plugin.xml`.

For the Marketplace `download_url` to become valid, create a public GitHub
release tagged `1.0.0` and upload the exact plugin archive as:

`cliconsole-1.0.0.zip`

The archive must contain the plugin in its top-level technical directory:

```text
cliconsole/
```

The published archive should be built from the same Git tag represented by
the submitted `plugin.xml`.

### Resource limits and audit log

To reduce denial-of-service and accidental resource exhaustion risks, the plugin enforces bounded command and argument sizes, a maximum of three active terminal sessions, a 15-minute execution limit per command, a 5 MiB limit for each captured output stream, a 64 KiB input queue, an 8 KiB input payload limit, and a 256 KiB maximum output chunk per HTTP response.

Completed sessions are retained temporarily and cleaned only after inactivity. Active sessions are protected by a heartbeat and worker marker; a running session with a fresh heartbeat is never removed by normal cleanup.

Each command execution is recorded in a JSON Lines audit log at:

```text
<GLPI_LOG_DIR>/cliconsole.log
```

The log directory follows GLPI's configured `GLPI_LOG_DIR` location (for example, `files/_log` in a basic installation). The plugin does not place audit records inside its own plugin directory.

The log records the UTC timestamp, event (`start` or `finish`), GLPI user ID, username, session ID, sanitized command, final state, exit code, and duration when available. The log is protected with filesystem permissions and rotates to `cliconsole.log.1` when it reaches 5 MiB. Interactive input values are not written to the audit log, which avoids recording passwords or other values entered interactively.
