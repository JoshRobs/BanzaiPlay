#!/usr/bin/env bash
# Upload fixtures through the real admin form, the way a person would.
#
#   npx @wordpress/env start
#   npx @wordpress/env run cli php wp-content/plugins/BanzaiPlay/tests/make-fixtures.php
#   bash tests/e2e.sh                       # every zip in tests/output
#   bash tests/e2e.sh unity-gzip godot      # just these
#   bash tests/e2e.sh /path/to/build.zip    # any zip; slug from its name
#
# Creates one game per zip (slug = zip name) and prints where each upload
# redirected to. Inspect the results with:
#   npx @wordpress/env run cli wp option get banzaiplay_games --format=json
set -euo pipefail

BASE="${BASE:-http://localhost:8892}"
DIR="$(cd "$(dirname "$0")" && pwd)"
JAR="$(mktemp)"
trap 'rm -f "$JAR"' EXIT

curl -s -c "$JAR" -b "$JAR" "$BASE/wp-login.php" >/dev/null
curl -s -c "$JAR" -b "$JAR" -o /dev/null \
	--data-urlencode "log=admin" --data-urlencode "pwd=password" \
	--data-urlencode "testcookie=1" --data-urlencode "redirect_to=$BASE/wp-admin/" \
	"$BASE/wp-login.php"

nonce() {
	curl -s -b "$JAR" "$BASE/wp-admin/admin.php?page=banzaiplay-new" |
		grep -o 'name="_wpnonce" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'
}

upload() { # slug zip
	local n file="$2"
	# Git Bash's curl is a Windows binary and cannot open /c/... paths.
	command -v cygpath >/dev/null && file="$(cygpath -m "$file")"
	n="$(nonce)"
	[ -n "$n" ] || { echo "no nonce — not logged in?"; exit 1; }
	curl -s -b "$JAR" -o /dev/null -w "%{http_code} %{redirect_url}\n" \
		-F "action=banzaiplay_save_game" -F "_wpnonce=$n" -F "original_slug=" \
		-F "name=$1" -F "slug=$1" -F "active=1" \
		-F "build=@$file;type=application/zip" \
		"$BASE/wp-admin/admin-post.php"
}

if [ $# -gt 0 ]; then
	zips=()
	for name in "$@"; do
		if [ -f "$name" ]; then zips+=("$name"); else zips+=("$DIR/output/$name.zip"); fi
	done
else
	zips=("$DIR"/output/*.zip)
fi

for zip in "${zips[@]}"; do
	slug="$(basename "$zip" .zip | tr '[:upper:]_' '[:lower:]-')"
	printf '%-22s ' "$slug"
	upload "$slug" "$zip"
done
