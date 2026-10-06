#!/usr/bin/env bash
# Runs the integration suite against one Symfony line in Docker, as the CI matrix does:
#   tests/Integration/Symfony/run-matrix.sh '6.4.*' [php image tag, default 8.4-cli] [phpunit arguments...]
# CORE points at a checkout of msstc4php/dto-generator (default: ../../msstc4php/dto-generator); LOWEST=1 resolves the
# lowest versions. Needs git, docker and python3.
set -euo pipefail
SYMFONY="$1"; PHP_IMAGE="${2:-8.4-cli}"; shift; shift || true
BRIDGE="$(cd "$(dirname "$0")/../../.." && pwd)"
CORE="${CORE:-$BRIDGE/../../msstc4php/dto-generator}"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
(cd "$BRIDGE" && git ls-files -co --exclude-standard | grep -v '^vendor/' | tar -cf - -T -) | tar -xf - -C "$WORK"
mkdir "$WORK/core" && (cd "$CORE" && git ls-files | tar -cf - -T -) | tar -xf - -C "$WORK/core"
SYMFONY="$SYMFONY" PLATFORM="${PHP_IMAGE%%-*}" python3 - "$WORK" <<'PY'
import json, os, sys
work = sys.argv[1]
manifest = json.load(open(os.path.join(work, 'composer-ci.json')))
manifest['repositories'] = [{'type': 'path', 'url': './core', 'options': {'symlink': False, 'versions': {'msstc4php/dto-generator': '1.0.0'}}}]
for tool in ['deptrac/deptrac', 'friendsofphp/php-cs-fixer', 'infection/infection', 'phpstan/extension-installer', 'phpstan/phpstan', 'phpstan/phpstan-strict-rules', 'rector/rector', 'roave/backward-compatibility-check', 'roave/security-advisories']:
    manifest['require-dev'].pop(tool, None)
for package in manifest['require-dev']:
    if package.startswith('symfony/'):
        manifest['require-dev'][package] = os.environ['SYMFONY']
manifest['config']['platform'] = {'php': os.environ['PLATFORM'] + '.0'}
manifest['config']['allow-plugins'] = {'msstc4php/dto-generator': False}
json.dump(manifest, open(os.path.join(work, 'composer.json'), 'w'), indent=2)
PY
USER_ID="$(id -u):$(id -g)"
docker run --rm -u "$USER_ID" -v "$WORK:/app" -w /app -e COMPOSER_HOME=/tmp composer:2 \
    update ${LOWEST:+--prefer-lowest --prefer-stable} --no-interaction --no-progress --ignore-platform-req='ext-*'
docker run --rm -u "$USER_ID" -v "$WORK:/app" -w /app "php:$PHP_IMAGE" vendor/bin/phpunit --testsuite integration "$@"
