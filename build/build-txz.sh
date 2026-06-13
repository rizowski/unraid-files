#!/bin/bash
# Pack source/ into a Slackware .txz package for the Modern File Viewer plugin.
#
# Usage:
#   build/build-txz.sh [--update-plg]
#
# Produces archive/modern.file.viewer-<version>.txz and prints its MD5.
# With --update-plg, writes the MD5 into the <!ENTITY md5 ...> line of the .plg.
#
# The package layout mirrors how Unraid expects it:
#   install/slack-desc, install/doinst.sh   -> package metadata + post-install
#   usr/local/emhttp/plugins/<name>/...      -> extracts to the live webGUI tree
#
# makepkg (Slackware) is used on an Unraid box. On a dev machine without makepkg
# this falls back to building an equivalent tar.xz with the same internal layout.

set -euo pipefail

NAME="modern.file.viewer"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="${ROOT}/source"
PLG="${ROOT}/${NAME}.plg"
OUT_DIR="${ROOT}/archive"

# Read the version from the .plg so there is a single source of truth.
# Portable across BSD (macOS) and GNU grep.
VERSION="$(grep -Eo 'ENTITY[[:space:]]+version[[:space:]]+"[^"]+"' "${PLG}" \
  | head -n1 | grep -Eo '"[^"]+"' | tr -d '"')"
if [ -z "${VERSION}" ]; then
  echo "ERROR: could not read version from ${PLG}" >&2
  exit 1
fi

TXZ="${NAME}-${VERSION}.txz"
OUT="${OUT_DIR}/${TXZ}"
mkdir -p "${OUT_DIR}"

echo "Building ${TXZ} from ${SRC}"

# Stage a clean copy so we control permissions/ownership inside the package.
STAGE="$(mktemp -d)"
trap 'rm -rf "${STAGE}"' EXIT
cp -a "${SRC}/." "${STAGE}/"

# Ensure the post-install script is executable inside the package.
chmod 0755 "${STAGE}/install/doinst.sh"
find "${STAGE}/usr" -type d -exec chmod 0755 {} \;
find "${STAGE}/usr" -type f -exec chmod 0644 {} \;

if command -v makepkg >/dev/null 2>&1; then
  # Native Slackware path (on Unraid). makepkg reads install/ for metadata.
  ( cd "${STAGE}" && makepkg -l n -c n "${OUT}" >/dev/null )
else
  echo "NOTE: makepkg not found; building an equivalent tar.xz with the same layout." >&2
  ( cd "${STAGE}" && tar --owner=0 --group=0 -cJf "${OUT}" . )
fi

MD5="$(md5sum "${OUT}" | awk '{print $1}')"
echo "Built: ${OUT}"
echo "MD5:   ${MD5}"

if [ "${1:-}" = "--update-plg" ]; then
  # Replace the md5 entity value in the .plg (ERE works on BSD and GNU sed).
  sed -E -i.bak "s/(<!ENTITY[[:space:]]+md5[[:space:]]+\")[0-9a-fA-F]*(\")/\1${MD5}\2/" "${PLG}"
  rm -f "${PLG}.bak"
  echo "Updated ${PLG} md5 entity -> ${MD5}"
fi
