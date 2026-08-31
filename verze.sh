#!/bin/bash
#
# THE MOVE :: přerazítkování CSS a JS v HTML souborech.
#
# Prohlížeče si style.css a main.js drží v mezipaměti měsíc (viz .htaccess).
# Po změně těchto souborů by návštěvníci ještě dlouho viděli starou verzi,
# proto se do odkazů vkládá otisk obsahu:  css/style.css?v=a1b2c3d4
# Změní se obsah → změní se otisk → prohlížeč stáhne nový soubor.
#
# Spusťte po každé úpravě css/style.css nebo js/main.js, před nahráním
# na hosting:
#
#   ./verze.sh
#
set -e
cd "$(dirname "$0")"

otisk() {
  # Prvních 8 znaků otisku obsahu souboru.
  md5 -q "$1" 2>/dev/null | cut -c1-8 || md5sum "$1" | cut -c1-8
}

VERZE_CSS=$(otisk css/style.css)
VERZE_JS=$(otisk js/main.js)

for soubor in *.html; do
  # Případná stará verze se přepíše, chybějící se doplní.
  sed -i '' -E \
    -e "s~(href=\"css/style\.css)(\?v=[a-f0-9]+)?\"~\1?v=${VERZE_CSS}\"~g" \
    -e "s~(src=\"js/main\.js)(\?v=[a-f0-9]+)?\"~\1?v=${VERZE_JS}\"~g" \
    "$soubor"
done

echo "style.css → ?v=${VERZE_CSS}"
echo "main.js   → ?v=${VERZE_JS}"
echo "Přerazítkováno souborů: $(ls -1 *.html | wc -l | tr -d ' ')"
