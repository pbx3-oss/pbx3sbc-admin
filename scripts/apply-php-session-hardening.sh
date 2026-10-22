#!/bin/sh
# Install session.upload_progress Off into PHP-FPM (+ CLI) conf.d
# (locked permanent — product unused). Idempotent: install.sh + tip ops.
#
# Usage: sudo ./scripts/apply-php-session-hardening.sh
set -eu

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
REPO_ROOT="$(CDPATH= cd -- "${SCRIPT_DIR}/.." && pwd)"
INI_SRC="${PBX3SBC_SESSION_INI:-${REPO_ROOT}/deploy/php/99-pbx3sbc-session.ini}"

log() { echo "apply-php-session-hardening: $*"; }

if [ ! -f "$INI_SRC" ]; then
	log "missing $INI_SRC — skip"
	exit 0
fi

php_short=""
if command -v php >/dev/null 2>&1; then
	php_short="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"
fi
if [ -z "$php_short" ]; then
	for cand in /etc/php/*/fpm; do
		[ -d "$cand" ] || continue
		php_short="$(basename "$(dirname "$cand")")"
		break
	done
fi

if [ -z "$php_short" ] || [ ! -d "/etc/php/${php_short}/fpm/conf.d" ]; then
	log "php-fpm conf.d not found — skip"
	exit 0
fi

cp "$INI_SRC" "/etc/php/${php_short}/fpm/conf.d/99-pbx3sbc-session.ini"
log "installed /etc/php/${php_short}/fpm/conf.d/99-pbx3sbc-session.ini"
if [ -d "/etc/php/${php_short}/cli/conf.d" ]; then
	cp "$INI_SRC" "/etc/php/${php_short}/cli/conf.d/99-pbx3sbc-session.ini"
fi

if systemctl is-active --quiet "php${php_short}-fpm" 2>/dev/null; then
	systemctl reload "php${php_short}-fpm" >/dev/null 2>&1 \
		|| systemctl restart "php${php_short}-fpm" >/dev/null 2>&1 \
		|| true
	log "reloaded php${php_short}-fpm"
fi

log "done"
