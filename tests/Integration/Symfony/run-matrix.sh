#!/usr/bin/env bash
# Runs the PHPUnit job of the CI matrix for one Symfony line in Docker, with the same pins as bundle-standard's
# php-bundle.yml: composer-ci.json without the BC check and deptrac, every symfony/* it lists plus the kernel core
# pinned to the line, then the whole suite.
#   tests/Integration/Symfony/run-matrix.sh '6.4.*' [php image tag, default 8.4-cli] [phpunit arguments...]
# CORE is a checkout of msstc4php/dto-generator used instead of the published package (default
# ../../msstc4php/dto-generator); LOWEST=1 resolves the lowest versions. Needs git, docker and python3.
set -euo pipefail
if [ $# -lt 1 ]; then
    sed -n '2,7p' "$0"
    exit 2
fi

SYMFONY="$1"; PHP_IMAGE="${2:-8.4-cli}"; shift; shift || true
if ! echo "$PHP_IMAGE" | grep -qE '^[0-9]+\.[0-9]+'; then
    echo "The PHP image tag must start with its version, like 8.4-cli; got \"$PHP_IMAGE\"." >&2
    exit 2
fi
BRIDGE="$(cd "$(dirname "$0")/../../.." && pwd)"
CORE="${CORE:-$BRIDGE/../../msstc4php/dto-generator}"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
(cd "$BRIDGE" && git ls-files -co --exclude-standard | grep -v '^vendor/' | while read -r file; do [ -e "$file" ] && echo "$file"; done | tar -cf - -T -) | tar -xf - -C "$WORK"
mkdir "$WORK/core" && (cd "$CORE" && git ls-files | tar -cf - -T -) | tar -xf - -C "$WORK/core"
SYMFONY="$SYMFONY" PLATFORM="$(echo "$PHP_IMAGE" | grep -oE '^[0-9]+\.[0-9]+')" python3 - "$WORK" <<'PY'
import json, os, re, sys
work = sys.argv[1]
manifest = json.load(open(os.path.join(work, 'composer-ci.json')))
manifest['repositories'] = [{'type': 'path', 'url': './core', 'options': {'symlink': False, 'versions': {'msstc4php/dto-generator': '1.2.0'}}}]
for tool in ['roave/backward-compatibility-check', 'deptrac/deptrac']:
    manifest['require-dev'].pop(tool, None)
listed = [p for p in list(manifest.get('require', {})) + list(manifest['require-dev'])
          if re.match(r'^symfony/(?!(monolog-bundle|phpunit-bridge|flex|maker-bundle)$|polyfill-)(?!.*-contracts$)', p)]
for package in set(listed) | {'symfony/framework-bundle', 'symfony/console', 'symfony/http-kernel', 'symfony/dependency-injection', 'symfony/config'}:
    manifest['require-dev'][package] = os.environ['SYMFONY']
manifest['config']['platform'] = {'php': os.environ['PLATFORM'] + '.0'}
json.dump(manifest, open(os.path.join(work, 'composer.json'), 'w'), indent=2)
PY
USER_ID="$(id -u):$(id -g)"
docker run --rm -u "$USER_ID" -v "$WORK:/app" -w /app -e COMPOSER_HOME=/tmp composer:2 \
    update ${LOWEST:+--prefer-lowest --prefer-stable} --no-interaction --no-progress --ignore-platform-req='ext-*'
# As the standard's job does: a dependency must not have moved the cell to another Symfony major.
INSTALLED="$(docker run --rm -u "$USER_ID" -v "$WORK:/app" -w /app "php:$PHP_IMAGE" php -d error_reporting=0 -r 'require "vendor/autoload.php"; echo Composer\InstalledVersions::getPrettyVersion("symfony/http-kernel");')"
case "$INSTALLED" in
    "v${SYMFONY%%.\*}"*) echo "symfony/http-kernel $INSTALLED" ;;
    *) echo "Expected Symfony $SYMFONY, resolved symfony/http-kernel $INSTALLED" >&2; exit 1 ;;
esac
docker run --rm -u "$USER_ID" -v "$WORK:/app" -w /app "php:$PHP_IMAGE" vendor/bin/phpunit "$@"
