# HANDOFF — CLI Console 1.0.5

## Release status

- Target version: 1.0.5
- Base version: 1.0.4
- Branch: `fix/1.0.5-config-set-abbreviations`
- Purpose: close Teclib/Claude LOW finding from 2026-11-05 concerning abbreviated Symfony command names bypassing audit redaction
- Status: implementation prepared for review; GitHub Actions and GLPI 12 integration verification must complete before publishing a release

## Changes

- `normalizeCommand()` now requires the first token to be a complete registered command name, with optional exact `glpi:` prefix normalization retained.
- Abbreviated and unknown commands are rejected before the worker is started.
- Options before the command are rejected to prevent Symfony's global-option parsing from moving the command token outside the validated position. Command-specific options following the command remain supported.
- The command registry is read fail-closed. Failure to start `bin/console list --raw`, a non-zero exit, timeout, empty output, or output overflow rejects execution.
- Added smoke coverage for canonical names, optional prefix variants, abbreviations, unknown commands, leading global options, and the positional-secret examples from the 1.0.2 and 1.0.4 reviews.
- Updated `setup.php`, `plugin.xml`, `README.md`, and `CHANGELOG.md` to 1.0.5.

## Architecture and security context

The browser controller parses user input, resolves the configured PHP CLI binary, verifies the GLPI installation's command list, normalizes the exact command name, and only then stores sanitized command metadata and creates the detached worker. The worker still receives the original normalized execution arguments through an argument-array `proc_open()` call with `bypass_shell`.

The audit protection for `glpi:config:set` and `config:set` continues to withhold the full argument list. The new validation closes the gap where Symfony could execute a valid abbreviation while the audit logic saw a different literal token.

## Package generation

- Run `php tools/build-zip.php` from the repository root (PHP 8.3+ with ext-zip).
- Default output: `dist/cliconsole-1.0.5.zip`.
- An output directory or an explicit ZIP path can be supplied as the first argument.
- The ZIP contains the installable `cliconsole/` directory and excludes CI tooling, GitHub workflows, the builder, and HANDOFF.md.
- The plugin workflow builds and uploads the same ZIP as a downloadable GitHub Actions artifact.

## Validation to perform

1. Confirm GitHub Actions passes PHP 8.3, 8.4, 8.5, and the experimental PHP 8.6 lane.
2. Confirm runtime smoke tests reject `glpi:config:s`, `config:s`, `glpi:conf`, `glpi:config`, unknown names, and global options before the command.
3. Confirm the exact registered command and optional complete-prefix forms still normalize successfully.
4. On a GLPI 12 installation, run the Teclib regression matrix with non-production test values and verify no positional values appear in either `GLPI_LOG_DIR/cliconsole.log` or session `metadata.json`.
5. Re-run the Teclib/Claude security review before publishing the 1.0.5 release asset.

## Deployment notes

- No database migration is required.
- Replace the existing plugin directory with the 1.0.5 package after backup.
- Keep the existing GLPI audit log; do not delete `cliconsole.log` during upgrade.
- The PHP CLI binary and GLPI root configuration remain unchanged.
- No Composer or third-party runtime dependencies are introduced.

## Known limitation / behavior change

The command name must be the first token. Symfony global options placed before the command are intentionally unsupported. Put the exact command first and use its options afterwards. This constraint is documented in the README and is part of the security boundary.

## Next steps

- Review the generated pull request and its CI results.
- Verify behavior on the target GLPI 12 server.
- Obtain Teclib's confirmation, then publish the 1.0.5 ZIP and release tag.
