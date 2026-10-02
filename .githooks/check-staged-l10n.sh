#!/usr/bin/env bash
# Keep a direct-access guard in staged Loco Translate PHP files.
set -euo pipefail

echo "[RILM pre-commit] Checking staged translation files..." >&2

if ! command -v git >/dev/null 2>&1; then
	exit 0
fi

staged_l10n="$(git diff --cached --name-only --diff-filter=ACMR | grep '^languages/.*\.l10n\.php$' || true)"

if [ -z "$staged_l10n" ]; then
	exit 0
fi

repo_root="$(git rev-parse --show-toplevel)"
fixed=0

while IFS= read -r file; do
	absolute_path="$repo_root/$file"
	if ! grep -q "defined.*ABSPATH" "$absolute_path" 2>/dev/null; then
		echo "[RILM pre-commit] ABSPATH guard missing; adding it to $file" >&2
		awk 'NR == 1 && /^<\?php$/ { print; print "defined( '\''ABSPATH'\'' ) || exit;"; next } { print }' "$absolute_path" >"$absolute_path.tmp"
		mv "$absolute_path.tmp" "$absolute_path"
		git add "$absolute_path"
		fixed=1
	fi
done <<< "$staged_l10n"

if [ "$fixed" -eq 1 ]; then
	echo "[RILM pre-commit] Fixed translation files have been re-staged." >&2
fi
