#!/bin/sh
# Post-extract script run by upgradepkg as root.
# Seeds the persistent config on the flash and fixes permissions.
# Touches NOTHING outside our own plugin directories.

PLUGIN="modern.file.viewer"
EMHTTP="/usr/local/emhttp/plugins/${PLUGIN}"
CONFIG="/boot/config/plugins/${PLUGIN}"
OVERRIDES="${CONFIG}/filetypes.json"

mkdir -p "${CONFIG}"

# Seed an empty override store on first install; never overwrite an existing one.
if [ ! -f "${OVERRIDES}" ]; then
  printf '%s\n' '{ "version": 1, "byBasename": {}, "byPath": {} }' > "${OVERRIDES}"
fi
chown nobody:users "${CONFIG}" "${OVERRIDES}" 2>/dev/null
chmod 0664 "${OVERRIDES}" 2>/dev/null

# Normalise permissions of the installed web assets.
if [ -d "${EMHTTP}" ]; then
  find "${EMHTTP}" -type d -exec chmod 0755 {} \;
  find "${EMHTTP}" -type f -exec chmod 0644 {} \;
fi

exit 0
