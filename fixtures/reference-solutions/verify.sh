#!/usr/bin/env bash
# Verifies the customer-app reference solutions against their protected
# acceptance suites.
#
# Copies the tracked files of fixtures/customer-app into a temporary directory,
# runs the fixture's checks on the unchanged starter, then applies each patch
# in fixtures/reference-solutions/customer-app (in manifest order) and runs the
# checks again after every patch.
#
# At every stage it also copies in the platform-owned acceptance tests from
# fixtures/acceptance/customer-app (listed per solution in manifest.json) and
# runs them. They live outside the application, so a solution cannot edit
# them. The starter must FAIL the first solution's acceptance tests, which proves
# a prebuilt feature is not counted as generated. Each solution must PASS its
# own. The repository itself is never modified.
#
# Usage: fixtures/reference-solutions/verify.sh [--with-build]
#   --with-build  also run `npm run build` (needs network access to fonts.bunny.net)
set -euo pipefail

with_build=false
[[ "${1:-}" == "--with-build" ]] && with_build=true

root="$(git rev-parse --show-toplevel)"
fixture="$root/fixtures/customer-app"
solutions="$root/fixtures/reference-solutions/customer-app"
acceptance="$root/fixtures/acceptance/customer-app"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

step() { printf '\n==> %s\n' "$*"; }

# Print a manifest field for every solution, one line per solution:
# "patch|acceptance-file acceptance-file ...".
solution_lines() {
    php -r '
        $manifest = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
        foreach ($manifest["solutions"] as $solution) {
            echo $solution["patch"], "|", implode(" ", $solution["acceptance"] ?? []), PHP_EOL;
        }
    ' "$solutions/manifest.json"
}

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

# run_acceptance <label> <pass|fail> <files...>
run_acceptance() {
    local label="$1" expected="$2"
    shift 2

    step "acceptance ($label): expecting $expected"
    rm -rf tests/Acceptance
    mkdir -p tests/Acceptance
    cp -R "$acceptance/Support" tests/Acceptance/
    cp "$acceptance/phpunit.xml" tests/Acceptance/phpunit.xml
    for file in "$@"; do
        mkdir -p "tests/Acceptance/$(dirname "$file")"
        cp "$acceptance/$file" "tests/Acceptance/$file"
    done

    set +e
    php vendor/bin/phpunit --configuration tests/Acceptance/phpunit.xml
    local status=$?
    set -e
    rm -rf tests/Acceptance

    if [[ "$expected" == "pass" && $status -ne 0 ]]; then
        echo "Acceptance tests failed for $label." >&2
        exit 1
    fi
    if [[ "$expected" == "fail" && $status -eq 0 ]]; then
        echo "Acceptance tests unexpectedly passed on $label: the starter must not already contain the feature." >&2
        exit 1
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

mapfile -t lines < <(solution_lines)

run_checks "starter (no invitations)"
IFS=' ' read -r -a first_acceptance <<< "${lines[0]#*|}"
run_acceptance "starter" fail "${first_acceptance[@]}"

for line in "${lines[@]}"; do
    patch="${line%%|*}"
    IFS=' ' read -r -a files <<< "${line#*|}"

    step "apply $patch"
    git apply --check "$solutions/$patch"
    git apply "$solutions/$patch"
    run_checks "$patch"
    run_acceptance "$patch" pass "${files[@]}"
done

step "all reference solutions verified"
