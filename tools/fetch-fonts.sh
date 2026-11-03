#!/usr/bin/env bash
set -Eeuo pipefail
root="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd -P)"
cd "$root"
dir="public/assets/fonts"
mkdir -p "$dir"
# Immutable reviewed Fontsource commit, Inter v20, SIL Open Font License.
ref="5a72889057f592bb0659629e38337359f94d53b2"
base="https://raw.githubusercontent.com/fontsource/font-files/$ref/fonts/google/inter"
for weight in 400 600 700; do
  dest="$dir/inter-latin-$weight.woff2"
  curl --fail --location --silent --show-error --retry 3 --max-time 50 \
    "$base/files/inter-latin-$weight-normal.woff2" -o "$dest"
  test "$(head -c4 "$dest")" = "wOF2" || { echo "Unexpected font format" >&2;exit 1; }
  size="$(wc -c < "$dest")"
  test "$size" -gt 15000 && test "$size" -lt 60000 || { echo "Unexpected font size" >&2;exit 1; }
done
curl --fail --location --silent --show-error --retry 3 --max-time 40 \
  "$base/LICENSE" -o "$dir/OFL.txt"
grep -q "SIL OPEN FONT LICENSE" "$dir/OFL.txt" || { echo "Missing font license" >&2;exit 1; }
cat > "$dir/SOURCE.txt" <<EOF
WaBridge local Inter fonts — SIL Open Font License 1.1
Source: https://github.com/fontsource/font-files
Immutable commit: $ref
Font: Inter v20, latin normal weights 400/600/700
To retain correct license, keep OFL.txt with deployed files.
EOF
sha256sum "$dir"/inter-*.woff2
