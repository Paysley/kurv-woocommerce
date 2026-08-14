#!/usr/bin/env bash
#
# Build the installable / submittable plugin package.
#
# Usage:
#   ./bin/build-release.sh            # build from HEAD
#   ./bin/build-release.sh main       # build from a specific ref or tag
#
# The package is produced with `git archive`, so its contents come from the
# repository rather than the working directory. Files ignored by git, and files
# marked export-ignore in .gitattributes (.github, .gitignore, bin/), can never
# end up in it. WordPress.org rejects a package containing any hidden file.

set -euo pipefail

SLUG='kurv-payments-for-woocommerce'
REF="${1:-HEAD}"

cd "$( dirname "${BASH_SOURCE[0]}" )/.."
REPO_ROOT="$( pwd )"
OUT_DIR="${REPO_ROOT}/dist"

red()   { printf '\033[31m%s\033[0m\n' "$*"; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }
warn()  { printf '\033[33m%s\033[0m\n' "$*"; }

# Note on the parsers below: every `grep`/`awk` reads from a here-string rather
# than a pipe. Under `set -o pipefail`, a `grep -q` that exits early SIGPIPEs the
# process feeding it and the whole pipeline reports failure, which silently turns
# a passing check into a failing one.

# --- Version: the plugin header is the single source of truth ---------------
HEADER="$( git show "${REF}:kurv-woocommerce.php" )"
VERSION="$( awk '/^[[:space:]]*\*[[:space:]]*Version:/ { print $3; exit }' <<< "${HEADER}" )"

if [[ -z "${VERSION}" ]]; then
  red "Could not read Version from the plugin header at ${REF}."
  exit 1
fi

# --- readme.txt Stable tag must agree, or WordPress.org serves the wrong one -
README="$( git show "${REF}:readme.txt" )"
STABLE="$( awk '/^Stable tag:/ { print $3; exit }' <<< "${README}" )"

if [[ "${STABLE}" != "${VERSION}" ]]; then
  red "Version mismatch: plugin header says ${VERSION}, readme.txt Stable tag says ${STABLE}."
  red "WordPress.org serves the version named by Stable tag. Fix before releasing."
  exit 1
fi

# --- Warn if the working tree has changes that will NOT be in the package ---
if [[ "${REF}" == "HEAD" ]] && ! git diff-index --quiet HEAD -- 2>/dev/null; then
  warn "Working tree has uncommitted changes."
  warn "The package is built from ${REF}, so those changes are NOT included."
  git status --short
  echo
fi

# --- Build ------------------------------------------------------------------
mkdir -p "${OUT_DIR}"
ZIP="${OUT_DIR}/${SLUG}-${VERSION}.zip"
rm -f "${ZIP}"

git archive --format=zip --prefix="${SLUG}/" -o "${ZIP}" "${REF}"

# --- Verify: no hidden files, and the main file is where WordPress expects ---
LISTING="$( unzip -l "${ZIP}" )"

if grep -qE '/\.[^/]+$' <<< "${LISTING}"; then
  red "Package contains hidden files, which WordPress.org rejects:"
  grep -E '/\.[^/]+$' <<< "${LISTING}"
  red "Add them to .gitattributes as export-ignore."
  exit 1
fi

if ! grep -qF "${SLUG}/kurv-woocommerce.php" <<< "${LISTING}"; then
  red "Package is missing the main plugin file. Aborting."
  exit 1
fi

FILES="$( awk 'END { print $2 }' <<< "${LISTING}" )"

green "Built ${SLUG} ${VERSION} from ${REF} ($( git rev-parse --short "${REF}" ))"
echo   "  ${FILES} files, $( du -h "${ZIP}" | cut -f1 )"
echo   "  ${ZIP}"
echo
echo   "Install: WordPress admin -> Plugins -> Add New -> Upload Plugin"
