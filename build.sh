#!/usr/bin/env bash
# Собрать установочный архив OpenCart OCMOD: onecatalog.ocmod.zip
# Структура архива: install.xml + upload/  (как требует «Установка расширений»).
set -euo pipefail
cd "$(dirname "$0")"
ver=$(grep -oE '<version>[^<]+' install.xml | head -1 | sed 's/<version>//')
out="onecatalog-opencart-${ver:-dev}.ocmod.zip"
rm -f "$out"
zip -rq "$out" install.xml upload -x '*.DS_Store'
echo "✔ $out"
