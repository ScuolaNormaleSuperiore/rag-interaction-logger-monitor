#!/usr/bin/env bash
# Block high-confidence secrets in staged changes.
set -euo pipefail

echo "[RILM pre-commit] Scanning staged changes for secrets..." >&2

if ! command -v git >/dev/null 2>&1; then
	exit 0
fi

if git rev-parse --verify HEAD >/dev/null 2>&1; then
	diff_output="$(git diff --cached --unified=0 --no-color --diff-filter=ACMRTUXB)"
else
	empty_tree="$(git hash-object -t tree /dev/null)"
	diff_output="$(git diff --cached --unified=0 --no-color --diff-filter=ACMRTUXB "$empty_tree")"
fi

if [ -z "$diff_output" ]; then
	exit 0
fi

added_lines="$(printf '%s\n' "$diff_output" | awk '
	/^\+\+\+ / { next }
	/^\+/ {
		sub(/^\+/, "", $0)
		print
	}
')"

if [ -z "$added_lines" ]; then
	exit 0
fi

patterns=(
	'-----BEGIN (RSA |EC |DSA |OPENSSH |PGP )?PRIVATE KEY-----'
	'(AKIA|ASIA)[A-Z0-9]{16}'
	'gh[pousr]_[A-Za-z0-9]{36,255}'
	'github_pat_[A-Za-z0-9_]{20,}'
	'xox[baprs]-[A-Za-z0-9-]{10,}'
	'sk-[A-Za-z0-9]{20,}'
	'[A-Za-z][A-Za-z0-9+.-]*://[^[:space:]]+:[^[:space:]]+@[^[:space:]]+'
	'(api[_-]?key|client[_-]?secret|access[_-]?token|refresh[_-]?token|password|passwd|pwd|secret)[[:space:]]*[:=][[:space:]]*["'"'][^"'"']{8,}["'"']'
)

matches_file="$(mktemp "${TMPDIR:-/tmp}/rilm-secret-scan.XXXXXX")"
trap 'rm -f "$matches_file"' EXIT

found=0
for pattern in "${patterns[@]}"; do
	if printf '%s\n' "$added_lines" | grep -E -i -n "$pattern" >"$matches_file" 2>/dev/null; then
		if [ "$found" -eq 0 ]; then
			echo "[RILM pre-commit] Potential secret detected in staged changes:" >&2
			found=1
		fi
		cat "$matches_file" >&2
	fi
done

if [ "$found" -eq 1 ]; then
	echo >&2
	echo "Commit blocked. Remove the secret or keep a demonstrably safe example out of staging." >&2
	exit 1
fi
