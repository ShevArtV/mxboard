#!/usr/bin/env bash
# Выкатка mxBoard на стенд MODX 3 (Hostland) и установка пакета.
#
#   ./_deploy.sh            — собрать пакет, залить, установить
#   ./_deploy.sh --no-build — только залить и установить (пакет уже собран)
#
# Стенд: hostland:~/modx3.art-sites.ru/htdocs/www/ (MODX 3 + miniShop3 + VueTools).
# Одно SSH-подключение на серию команд — на Hostland частые коннекты ловит fail2ban.

set -euo pipefail

PROJECT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MODXAPP="${HOME}/.config/composer/vendor/bin/modxapp"
REMOTE="hostland"
REMOTE_ROOT="modx3.art-sites.ru/htdocs/www"
VERSION="$(php -r '$c = require "'"${PROJECT}"'/package_builder/packages/mxboard/config.php"; echo $c["version"] . "-" . $c["release"];')"
SIGNATURE="mxboard-${VERSION}"

cd "${PROJECT}"

if [[ "${1:-}" != "--no-build" ]]; then
    echo "==> Сборка ${SIGNATURE}"

    # node_modules (59 МБ) обязаны остаться за бортом. .packignore проекта билдер
    # игнорирует — он читает его из своей install-папки (подводный камень #12 БЗ),
    # поэтому единственный надёжный способ — вынести их из дерева на время сборки.
    NM="assets/components/mxboard/js/mgr/node_modules"
    STAGE="$(mktemp -d)"
    trap '[[ -d "${STAGE}/node_modules" ]] && mv "${STAGE}/node_modules" "'"${PROJECT}"'/${NM}"; rm -rf "${STAGE}"' EXIT

    [[ -d "${NM}" ]] && mv "${NM}" "${STAGE}/node_modules"

    "${MODXAPP}" build mxboard --no-check

    [[ -d "${STAGE}/node_modules" ]] && mv "${STAGE}/node_modules" "${NM}"
    trap - EXIT
    rm -rf "${STAGE}"
fi

ZIP="core/packages/${SIGNATURE}.transport.zip"
[[ -f "${ZIP}" ]] || { echo "Нет пакета: ${ZIP}"; exit 1; }

# Мусор в пакете (подводный камень #13: phpstan/node_modules раздували sendit до 11 МБ).
JUNK="$(unzip -l "${ZIP}" | grep -cE 'node_modules|phpstan|composer\.phar|tmp-' || true)"
[[ "${JUNK}" == "0" ]] || { echo "В пакете dev-мусор (${JUNK} записей) — сборка отклонена"; exit 1; }

echo "==> Заливка на ${REMOTE}"
rsync -az --no-perms -e ssh "${ZIP}" "${REMOTE}:${REMOTE_ROOT}/core/packages/"
rsync -az --no-perms -e ssh "${PROJECT}/_install_remote.php" "${REMOTE}:${REMOTE_ROOT}/_install_remote.php"

echo "==> Установка на стенде"
ssh "${REMOTE}" "cd ~/${REMOTE_ROOT} && /usr/local/php/php-8.3/bin/php _install_remote.php ${SIGNATURE}; rm -f _install_remote.php"

# Сброс OPcache. У PHP-FPM свой кэш опкодов, и CLI-установка его не трогает: без сброса
# сайт продолжает выполнять ПРЕДЫДУЩУЮ версию классов — правка «не доезжает», хотя файлы
# на диске новые. Ловится тяжело, поэтому делаем всегда.
echo "==> Сброс OPcache (PHP-FPM)"
printf '<?php if (function_exists("opcache_reset")) { opcache_reset(); echo "opcache reset\\n"; } else { echo "no opcache\\n"; }\n' \
    | ssh "${REMOTE}" "cat > ~/${REMOTE_ROOT}/_opcache_reset.php"
curl -sS "https://modx3.art-sites.ru/_opcache_reset.php" || true
ssh "${REMOTE}" "rm -f ~/${REMOTE_ROOT}/_opcache_reset.php"

echo "==> Готово: https://modx3.art-sites.ru/manager/ → Компоненты → mxBoard"
