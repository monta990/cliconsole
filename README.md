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


### Spanish (Mexico)

The plugin includes the `es_MX` gettext catalog in `locales/es_MX.po` and
`locales/es_MX.mo`. The source strings remain in English and use the `cliconsole`
translation domain, so GLPI's selected `Español (México)` locale can load the
plugin catalog through the normal plugin localization lifecycle.


### Interactive terminal

CLI Console uses a long-lived HTTP streaming request for the running
`bin/console` process and separate authenticated HTTP requests for terminal
input and control actions. It releases the PHP session lock before the
long-running request so the same GLPI session can submit input concurrently.
This avoids requiring a WebSocket server or an external worker daemon and is
suitable for shared hosting.

### Spanish (Mexico)

The plugin includes complete `es_MX` gettext catalogs in `locales/es_MX.po`
and `locales/es_MX.mo`.


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
