#!/usr/bin/env bash
# Package the 1pay.cx OpenCart 4 extension as onepaycx.ocmod.zip.
#
# OpenCart 4.0.x derives the extension code from the file name
# (basename minus ".ocmod.zip") and copies EVERY archive entry into
# extension/onepaycx/ (install.json and LICENSE included), so the archive root
# must hold install.json plus the admin/, catalog/ and system/ folders directly
# (no wrapping directory).
#
# Usage: plugins/opencart/build.sh [output-dir]   (default: plugins/opencart/)
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
out="${1:-$here}"
mkdir -p "$out"

for f in $(find "$here/upload" -name '*.php'); do
  php -l "$f" >/dev/null || { echo "LINT FAIL $f"; exit 1; }
done
php "$here/test/compile-check.php" >/dev/null || { echo "COMPILE HARNESS FAIL"; exit 1; }

rm -f "$out/onepaycx.ocmod.zip"
( cd "$here/upload" && zip -rq "$out/onepaycx.ocmod.zip" install.json admin catalog system -x '*.DS_Store' '*/.*' )
# The MIT text ships at the archive root (-j drops the plugins/opencart/ path).
zip -jq "$out/onepaycx.ocmod.zip" "$here/LICENSE"

echo "Built $out/onepaycx.ocmod.zip"
unzip -l "$out/onepaycx.ocmod.zip"
