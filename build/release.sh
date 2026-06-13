#!/bin/bash
# Build the Modern File Viewer package and publish it as a GitHub release.
#
# Steps:
#   1. Build source/ -> archive/<name>-<version>.txz and stamp its MD5 into the .plg.
#   2. Commit any pending changes (the .plg MD5 always changes) and push the branch.
#   3. Create or update the GitHub release tagged <version> and upload the .txz.
#   4. Print the install URL (the raw .plg) to paste into Unraid.
#
# The Unraid install flow needs two things to agree, which this script guarantees:
#   - the .txz attached to the release, and
#   - the .plg on the branch whose <MD5> matches that .txz.
#
# Requirements: gh (authenticated), git, and an 'origin' GitHub remote.
#
# Usage:
#   build/release.sh [-y|--yes] [--draft] [--no-push] [--notes "text"]
#
#   -y, --yes     Do not prompt for confirmation.
#   --draft       Publish the release as a draft.
#   --no-push     Skip git commit/push (release-only). NOTE: the install URL will
#                 not serve the new MD5 until the .plg is committed and pushed.
#   --notes TEXT  Override the release notes (default: the latest <CHANGES> block).

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT}"

NAME="modern.file.viewer"
PLG="${ROOT}/${NAME}.plg"

ASSUME_YES=0
DRAFT=0
NO_PUSH=0
NOTES=""

while [ $# -gt 0 ]; do
  case "$1" in
    -y|--yes)   ASSUME_YES=1 ;;
    --draft)    DRAFT=1 ;;
    --no-push)  NO_PUSH=1 ;;
    --notes)    NOTES="${2:-}"; shift ;;
    *) echo "Unknown option: $1" >&2; exit 2 ;;
  esac
  shift
done

# --- preflight ---------------------------------------------------------------
command -v gh  >/dev/null 2>&1 || { echo "ERROR: gh (GitHub CLI) is not installed." >&2; exit 1; }
command -v git >/dev/null 2>&1 || { echo "ERROR: git is not installed." >&2; exit 1; }
gh auth status >/dev/null 2>&1 || { echo "ERROR: gh is not authenticated. Run: gh auth login" >&2; exit 1; }

REMOTE_URL="$(git remote get-url origin 2>/dev/null || true)"
[ -n "${REMOTE_URL}" ] || { echo "ERROR: no 'origin' git remote configured." >&2; exit 1; }

# Derive owner/repo slug from git@github.com:owner/repo.git or https://github.com/owner/repo(.git)
SLUG="$(printf '%s' "${REMOTE_URL}" | sed -E 's#^(git@github\.com:|https://github\.com/)##; s#\.git$##')"
BRANCH="$(git symbolic-ref --short HEAD 2>/dev/null || echo main)"

VERSION="$(grep -Eo 'ENTITY[[:space:]]+version[[:space:]]+"[^"]+"' "${PLG}" \
  | head -n1 | grep -Eo '"[^"]+"' | tr -d '"')"
[ -n "${VERSION}" ] || { echo "ERROR: could not read version from ${PLG}" >&2; exit 1; }

TXZ="${ROOT}/archive/${NAME}-${VERSION}.txz"
INSTALL_URL="https://raw.githubusercontent.com/${SLUG}/${BRANCH}/${NAME}.plg"

echo "Repo:        ${SLUG} (branch ${BRANCH})"
echo "Version/tag: ${VERSION}"
echo "Package:     ${TXZ##*/}"
echo "Install URL: ${INSTALL_URL}"
echo

if gh release view "${VERSION}" >/dev/null 2>&1; then
  echo "A release tagged ${VERSION} already exists; its asset will be replaced."
  echo "(Bump <!ENTITY version ...> in the .plg for a brand-new release.)"
  echo
fi

if [ "${ASSUME_YES}" -ne 1 ]; then
  printf "Proceed with build + publish? [y/N] "
  read -r ans
  case "${ans}" in y|Y|yes|YES) ;; *) echo "Aborted."; exit 0 ;; esac
fi

# --- 1. build ----------------------------------------------------------------
echo ">> Building package..."
bash "${ROOT}/build/build-txz.sh" --update-plg
[ -f "${TXZ}" ] || { echo "ERROR: expected package not found: ${TXZ}" >&2; exit 1; }

# --- 2. commit + push --------------------------------------------------------
if [ "${NO_PUSH}" -ne 1 ]; then
  if [ -n "$(git status --porcelain)" ]; then
    echo ">> Committing changes..."
    git add -A
    git commit -m "Release ${VERSION}" >/dev/null
  else
    echo ">> Nothing to commit."
  fi
  echo ">> Pushing ${BRANCH}..."
  if git rev-parse --abbrev-ref --symbolic-full-name '@{u}' >/dev/null 2>&1; then
    git push
  else
    git push -u origin "${BRANCH}"
  fi
else
  echo ">> --no-push: skipping git commit/push."
  echo "   WARNING: ${INSTALL_URL} will not serve the new MD5 until you commit and push ${NAME}.plg."
fi

# --- 3. release + asset ------------------------------------------------------
NOTE_ARGS=()
if [ -n "${NOTES}" ]; then
  NOTE_ARGS=(--notes "${NOTES}")
else
  # Use the most recent <CHANGES> block as the notes, if present.
  CHANGES="$(awk '/<CHANGES>/{f=1;next} /<\/CHANGES>/{f=0} f' "${PLG}")"
  if [ -n "${CHANGES}" ]; then
    NOTE_ARGS=(--notes "${CHANGES}"$'\n\n'"Install URL: ${INSTALL_URL}")
  else
    NOTE_ARGS=(--notes "Modern File Viewer ${VERSION}"$'\n\n'"Install URL: ${INSTALL_URL}")
  fi
fi

DRAFT_ARGS=()
[ "${DRAFT}" -eq 1 ] && DRAFT_ARGS=(--draft)

if gh release view "${VERSION}" >/dev/null 2>&1; then
  echo ">> Updating existing release ${VERSION}..."
  gh release upload "${VERSION}" "${TXZ}" --clobber
else
  echo ">> Creating release ${VERSION}..."
  # ${arr[@]+"${arr[@]}"} guards against "unbound variable" when an array is
  # empty under `set -u` (notably macOS's bash 3.2).
  gh release create "${VERSION}" "${TXZ}" \
    --title "Modern File Viewer ${VERSION}" \
    --target "${BRANCH}" \
    ${NOTE_ARGS[@]+"${NOTE_ARGS[@]}"} ${DRAFT_ARGS[@]+"${DRAFT_ARGS[@]}"}
fi

# Also attach the .plg to the release. raw.githubusercontent.com caches for
# ~5 min, so right after a push the raw URL can serve a stale .plg; the release
# asset is fresh immediately and gives a working install URL with no wait.
echo ">> Uploading .plg to the release..."
gh release upload "${VERSION}" "${PLG}" --clobber

ASSET_URL="https://github.com/${SLUG}/releases/download/${VERSION}/${NAME}.plg"

echo
echo "============================================================"
echo " Published Modern File Viewer ${VERSION}"
echo
echo " Install in Unraid -> Plugins -> Install Plugin."
echo
echo " Install now (fresh, version-pinned):"
echo "   ${ASSET_URL}"
echo
echo " Canonical URL (stable across versions; may be CDN-cached"
echo " for up to ~5 min right after a release):"
echo "   ${INSTALL_URL}"
echo
echo "============================================================"
