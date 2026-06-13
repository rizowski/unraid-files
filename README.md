# Modern File Viewer (Unraid plugin)

A modern file viewer for Unraid's built-in file manager (**Shares → Browse**).

Unraid's stock file manager shows **"Unsupported preview"** for almost every file
and has no syntax highlighting. This plugin augments the existing Browse view —
without replacing it — to add:

- **Image previews** for common web formats: png, jpg/jpeg, gif, webp, svg, bmp, ico, avif (with zoom).
- **Syntax highlighting** for config/text formats: json, yaml/yml, toml, ini/conf/cfg/env, xml, sh, Dockerfile, markdown, logs, and 80+ more.
- **Content-based type detection** for files with no extension (shebang, JSON/XML/YAML/INI heuristics, binary/magic-byte detection).
- **"Save as type"** — remember a filename → language mapping so the file highlights correctly next time.
- **Editing** as an explicit, permission-gated action. Edits preserve the file's original owner/group/mode.

## How it works

The plugin installs only under its own directory
(`/usr/local/emhttp/plugins/modern.file.viewer/`) and persists settings under
`/boot/config/plugins/modern.file.viewer/`. It **never modifies** any
`dynamix.file.manager` file. On the Browse page it wraps the stock preview
function at runtime: supported file types open in the modern viewer, and anything
it can't handle falls back to the built-in preview. If a future Unraid release
changes the file manager internals, the plugin degrades gracefully to stock
behaviour rather than breaking.

It reuses the ACE editor already bundled with Unraid's file manager, so it ships
no second editor.

## Building

```sh
build/build-txz.sh
```

This packs `source/` into `archive/modern.file.viewer-<version>.txz`, prints its
MD5, and (with `--update-plg`) writes the MD5 into `modern.file.viewer.plg`.

## Releasing

```sh
build/release.sh            # prompts before publishing
build/release.sh --yes      # no prompt
```

`release.sh` builds the package, commits and pushes the version + MD5 change,
then creates (or updates) the GitHub release tagged `<version>` and uploads the
`.txz`. It prints the install URL at the end. Bump `<!ENTITY version ...>` in
`modern.file.viewer.plg` for each new release; re-running with the same version
replaces the existing release asset.

Requires the [GitHub CLI](https://cli.github.com/) (`gh auth login`) and an
`origin` GitHub remote. Flags: `--draft`, `--no-push`, `--notes "text"`.

## Installing

In Unraid: **Plugins → Install Plugin**, then paste the install URL:

```
https://raw.githubusercontent.com/rizowski/unraid-files/main/modern.file.viewer.plg
```

## License

MIT — see [LICENSE](LICENSE).
