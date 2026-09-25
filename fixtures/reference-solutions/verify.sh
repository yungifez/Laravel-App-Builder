#!/usr/bin/env bash
# Verifies the customer-app reference solutions.
#
# Copies the tracked files of fixtures/customer-app into a temporary directory,
# runs the fixture's checks on the unchanged baseline, then applies each patch
# in fixtures/reference-solutions/customer-app in order and runs the checks
# again after every patch. The repository itself is never modified.
#
# Usage: fixtures/reference-solutions/verify.sh [--with-build]
#   --with-build  also run `npm run build` (needs network access to fonts.bunny.net)
set -euo pipefail

with_build=false
[[ "${1:-}" == "--with-build" ]] && with_build=true

root="$(git rev-parse --show-toplevel)"
fixture="$root/fixtures/customer-app"
patches="$root/fixtures/reference-solutions/customer-app"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

step() { printf '\n==> %s\n' "$*"; }

run_checks() {
    step "checks: $1"
    ./vendor/bin/pint --test
    ./vendor/bin/phpstan analyse --no-progress
    php artisan config:clear --quiet
    php artisan test
    php artisan wayfinder:generate --with-form --quiet
    npx vp fmt --check
    npx vp lint
    npm run types:check
    if $with_build; then
        npm run build
    fi
}

step "copy fixture to $work"
(cd "$fixture" && git ls-files -z | tar --null -T - -cf -) | tar -xf - -C "$work"
cd "$work"

step "install dependencies"
composer install --no-interaction --prefer-dist --no-progress
npm ci --no-audit --no-fund
cp .env.example .env
php artisan key:generate --quiet

run_checks "baseline (no invitations)"

for patch in "$patches"/*.patch; do
    name="$(basename "$patch")"
    step "apply $name"
    git apply --check "$patch"
    git apply "$patch"
    run_checks "$name"
done

step "all reference solutions verified"
