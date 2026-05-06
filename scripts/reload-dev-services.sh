#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ARTISAN="$ROOT_DIR/artisan"

RELOAD_FPM=0
FPM_SERVICE=""
DRY_RUN=0

usage() {
    cat <<'EOF'
Usage: scripts/reload-dev-services.sh [options]

Restarts Laravel runtime services so code changes are picked up.

Options:
  --dry-run                 Print actions without executing them.
  --reload-fpm              Try to reload php-fpm (auto-detect service name).
  --reload-fpm=<service>    Reload the specific php-fpm service.
  -h, --help                Show this help message.

Examples:
  scripts/reload-dev-services.sh
  scripts/reload-dev-services.sh --dry-run
  scripts/reload-dev-services.sh --reload-fpm
  scripts/reload-dev-services.sh --reload-fpm=php8.3-fpm
EOF
}

for arg in "$@"; do
    case "$arg" in
        --dry-run)
            DRY_RUN=1
            ;;
        --reload-fpm)
            RELOAD_FPM=1
            ;;
        --reload-fpm=*)
            RELOAD_FPM=1
            FPM_SERVICE="${arg#*=}"
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "Unknown option: $arg"
            echo
            usage
            exit 1
            ;;
    esac
done

if [[ ! -f "$ARTISAN" ]]; then
    echo "Could not find artisan at: $ARTISAN"
    exit 1
fi

run_cmd() {
    local cmd="$1"
    echo "→ $cmd"
    if [[ "$DRY_RUN" -eq 0 ]]; then
        eval "$cmd"
    fi
}

echo "Project root: $ROOT_DIR"

pushd "$ROOT_DIR" >/dev/null

run_cmd "php artisan optimize:clear"

ARTISAN_COMMANDS="$(php artisan list --raw | awk '{print $1}')"

has_artisan_command() {
    local command_name="$1"
    grep -qx "$command_name" <<<"$ARTISAN_COMMANDS"
}

if has_artisan_command "queue:restart"; then
    run_cmd "php artisan queue:restart"
fi

if has_artisan_command "horizon:terminate"; then
    run_cmd "php artisan horizon:terminate"
fi

if has_artisan_command "reverb:restart"; then
    run_cmd "php artisan reverb:restart"
fi

if has_artisan_command "octane:reload"; then
    run_cmd "php artisan octane:reload"
fi

if [[ "$RELOAD_FPM" -eq 1 ]]; then
    if ! command -v systemctl >/dev/null 2>&1; then
        echo "systemctl not found; skipping php-fpm reload."
    else
        if [[ -z "$FPM_SERVICE" ]]; then
            if systemctl list-unit-files 2>/dev/null | grep -q '^php-fpm\.service'; then
                FPM_SERVICE="php-fpm"
            else
                FPM_SERVICE="$(systemctl list-unit-files 2>/dev/null | awk '/^php[0-9.]+-fpm\.service/ {print $1}' | head -n1 | sed 's/\.service$//')"
            fi
        fi

        if [[ -n "$FPM_SERVICE" ]]; then
            if command -v sudo >/dev/null 2>&1; then
                run_cmd "sudo systemctl reload $FPM_SERVICE"
            else
                run_cmd "systemctl reload $FPM_SERVICE"
            fi
        else
            echo "Could not auto-detect a php-fpm service name; skipping php-fpm reload."
        fi
    fi
fi

popd >/dev/null

echo "Done. Runtime services should now be using the latest code."