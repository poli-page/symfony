#!/usr/bin/env bash
# scripts/check-release-versions.sh: a release's major.minor must be the
# pinned API contract's and the required poli-page/sdk's.
#
# Usage: scripts/check-release-versions.sh X.Y.Z   (or vX.Y.Z, or a prerelease)
#
# The bundle shares its major.minor with the Poli Page API, and integration
# 0.N requires poli-page/sdk 0.N.x. This script:
#   - reads the contract commit pinned in .github/workflows/ci.yml (never its
#     `# vX.Y.Z` comment), lists the contract repository's tags, and fails
#     unless that commit carries a stable tag vX.Y.Z with the release's
#     major.minor;
#   - fails unless composer.json and example-app/composer.json require
#     poli-page/sdk as ^X.Y or ^X.Y.Z with the release's major.minor.
# Patch numbers are free.
#
# Run by the Release workflow. API_CONTRACT_REMOTE overrides the contract
# repository (to check against a local one).

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKFLOW="$REPO_ROOT/.github/workflows/ci.yml"
REMOTE="${API_CONTRACT_REMOTE:-https://github.com/poli-page/api-contract.git}"

fail() { echo "check-release-versions: $1" >&2; exit 1; }

[ "$#" -eq 1 ] || fail "usage: $(basename "$0") X.Y.Z"
version="${1#v}"
if ! [[ "$version" =~ ^([0-9]+)\.([0-9]+)\.[0-9]+(-[A-Za-z0-9.-]+)?$ ]]; then
    fail "release version '$1' is not X.Y.Z"
fi
release_minor="${BASH_REMATCH[1]}.${BASH_REMATCH[2]}"

# poli-page/sdk: ^X.Y or ^X.Y.Z of the release's major.minor, in the package
# and in the example app.
for manifest in composer.json example-app/composer.json; do
    constraint="$(jq -r '.require["poli-page/sdk"] // empty' "$REPO_ROOT/$manifest")"
    [ -n "$constraint" ] || fail "$manifest does not require poli-page/sdk"
    if ! [[ "$constraint" =~ ^\^([0-9]+)\.([0-9]+)(\.[0-9]+)?$ ]]; then
        fail "release v$version needs poli-page/sdk ^$release_minor, but $manifest requires '$constraint'"
    fi
    sdk_minor="${BASH_REMATCH[1]}.${BASH_REMATCH[2]}"
    if [ "$sdk_minor" != "$release_minor" ]; then
        fail "release v$version and the required poli-page/sdk $constraint ($manifest) differ in major.minor: require poli-page/sdk ^$release_minor, or release v$sdk_minor.x"
    fi
done
echo "poli-page/sdk ^$release_minor is required, matching release v$version"

contract_sha="$(sed -n 's|.*uses: poli-page/api-contract@\([0-9a-f]\{40\}\).*|\1|p' "$WORKFLOW" | head -n 1)"
[ -n "$contract_sha" ] || fail "no poli-page/api-contract pin found in .github/workflows/ci.yml"

tags="$(git ls-remote --tags "$REMOTE")" || fail "cannot list the tags of $REMOTE"

# An annotated tag is listed twice: its own object, then `^{}` with the commit
# it points at. A lightweight tag is listed once, with its commit.
stable_tags="$(
    awk -v sha="$contract_sha" '$1 == sha { ref = $2; sub(/^refs\/tags\//, "", ref); sub(/\^\{\}$/, "", ref); print ref }' <<<"$tags" \
        | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$' | sort -u -V || true
)"
short_sha="${contract_sha:0:8}"
if [ -z "$stable_tags" ]; then
    fail "release v$version needs the API contract v$release_minor.x, but the pinned contract commit $short_sha has no stable vX.Y.Z tag: pin a released contract"
fi

while IFS= read -r tag; do
    if [[ "$tag" =~ ^v([0-9]+)\.([0-9]+)\. ]] && [ "${BASH_REMATCH[1]}.${BASH_REMATCH[2]}" = "$release_minor" ]; then
        echo "API contract $tag ($short_sha) matches release v$version"
        exit 0
    fi
done <<<"$stable_tags"

pinned="$(tr '\n' ' ' <<<"$stable_tags" | sed 's/ $//')"
# The newest of the commit's tags, which may carry two (an unchanged contract).
[[ "$(tail -n 1 <<<"$stable_tags")" =~ ^v([0-9]+)\.([0-9]+)\. ]]
fail "release v$version and the pinned API contract $pinned ($short_sha) differ in major.minor: pin the contract v$release_minor.x, or release v${BASH_REMATCH[1]}.${BASH_REMATCH[2]}.x"
