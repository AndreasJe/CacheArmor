#!/usr/bin/env bash
# Builds the wordpress.org distributable into dist/cachearmor/.
#
# Only the files the plugin needs at runtime are copied. Tests, dev config
# and dotfiles are deliberately left out: Plugin Check scans whatever is in
# the uploaded package and rejects files like .distignore.
set -euo pipefail

SLUG="cachearmor"
OUT="dist/$SLUG"
SHIP=( "$SLUG.php" admin.php uninstall.php assets/admin.css assets/admin.js readme.txt LICENSE.txt )

rm -rf "$OUT"
mkdir -p "$OUT"
for f in "${SHIP[@]}"; do
	mkdir -p "$OUT/$(dirname "$f")"
	cp "$f" "$OUT/$f"
done

# The plugin header version, readme Stable tag and the VERSION constant must agree.
header=$(sed -n 's/^ \* Version: *\(.*\)$/\1/p' "$SLUG.php" | tr -d '[:space:]')
stable=$(sed -n 's/^Stable tag: *\(.*\)$/\1/p' readme.txt | tr -d '[:space:]')
const=$(sed -n "s/.*CACHEARMOR_VERSION', *'\([^']*\)'.*/\1/p" "$SLUG.php" | tr -d '[:space:]')

if [ "$header" != "$stable" ] || [ "$header" != "$const" ]; then
	echo "version mismatch: header=$header stable-tag=$stable constant=$const" >&2
	exit 1
fi

echo "built $OUT (version $header)"
find "$OUT" -type f | sort

# Package as a zip with a single lowercase top-level folder.
#
# This matters: if a zip has no single top-level directory, WordPress names
# the installed plugin folder after the ZIP FILENAME instead. A file called
# a capitalised Foo.zip then yields wp-content/plugins/Foo/, and Plugin Check
# derives the expected text domain from that folder - reporting a mismatch
# against code that is actually correct. The wordpress.org slug is always
# lowercase, so the folder must be too.
rm -f "dist/$SLUG.zip"
if command -v zip >/dev/null 2>&1; then
	( cd dist && zip -rq "$SLUG.zip" "$SLUG" )
else
	powershell -NoProfile -ExecutionPolicy Bypass -File package.ps1 -Slug "$SLUG"
fi
