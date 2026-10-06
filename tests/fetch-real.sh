#!/usr/bin/env bash
# Download real, third-party game exports for local testing into tests/real/,
# one zip per build, zipped the way a person would (the export folder).
#
#   bash tests/fetch-real.sh          # all of them
#   bash tests/fetch-real.sh unitydemo godot-2d
#   bash tests/e2e.sh tests/real/*.zip
#
# These builds belong to their authors and several have no licence: use them
# to test locally, never commit or ship them. tests/real/ is gitignored.
set -euo pipefail

DIR="$(cd "$(dirname "$0")" && pwd)/real"
mkdir -p "$DIR"

# name | source
BUILDS=(
	"unitydemo|hf:dylanebert/UnityDemo"
	"unity-gzip-rogueci|gh:yanniboi/game-ci-test@gh-pages"
	"godot4-warlocks|gh:tsvetowntopalov/warlocks@main"
	"godot3-dodge|hf:godot-demo/godot-2d"
	"godot3-dodge-threads|hf:godot-demo/godot-2d-threads"
)

# Every file of a Hugging Face Space, recursively, keeping paths.
fetch_hf() { # space dest
	local space="$1" dest="$2"
	curl -fsS "https://huggingface.co/api/spaces/$space/tree/main?recursive=true" |
		node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>{for(const f of JSON.parse(s))if(f.type==="file")console.log(f.path)})' |
		while read -r path; do
			case "$path" in .gitattributes|README.md) continue ;; esac
			mkdir -p "$dest/$(dirname "$path")"
			curl -fsSL -o "$dest/$path" "https://huggingface.co/spaces/$space/resolve/main/$(node -e 'console.log(process.argv[1].split("/").map(encodeURIComponent).join("/"))' "$path")"
		done
}

# A GitHub branch as an archive, unpacked.
fetch_gh() { # repo@branch dest
	local repo="${1%@*}" branch="${1#*@}" dest="$2" tmp
	tmp="$(mktemp -d)"
	curl -fsSL -o "$tmp/a.zip" "https://codeload.github.com/$repo/zip/refs/heads/$branch"
	unzip -q "$tmp/a.zip" -d "$tmp/x"
	mv "$tmp"/x/*/* "$dest"/ 2>/dev/null || true
	mv "$tmp"/x/*/.[!.]* "$dest"/ 2>/dev/null || true
	rm -rf "$tmp"
}

for entry in "${BUILDS[@]}"; do
	name="${entry%%|*}" source="${entry#*|}"

	if [ $# -gt 0 ] && [[ " $* " != *" $name "* ]]; then
		continue
	fi

	work="$DIR/$name"
	rm -rf "$work" "$DIR/$name.zip"
	mkdir -p "$work"

	case "$source" in
		hf:*) fetch_hf "${source#hf:}" "$work" ;;
		gh:*) fetch_gh "${source#gh:}" "$work" ;;
	esac

	# Git LFS pointers instead of the real files would make a "build" that
	# can never load; say so rather than test it.
	if grep -rlq "^version https://git-lfs.github.com/spec" "$work" 2>/dev/null; then
		echo "$name: contains Git LFS pointers, not files — skipped" >&2
		continue
	fi

	if command -v zip >/dev/null; then
		(cd "$DIR" && zip -qr "$name.zip" "$name")
	else
		# Git Bash on Windows has no zip; PowerShell 7 does.
		pwsh -NoProfile -Command "Compress-Archive -Path '$(cygpath -w "$work" 2>/dev/null || echo "$work")' -DestinationPath '$(cygpath -w "$DIR/$name.zip" 2>/dev/null || echo "$DIR/$name.zip")'"
	fi
	printf '%-22s %s\n' "$name" "$(du -h "$DIR/$name.zip" | cut -f1)"
done
