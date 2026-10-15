#!/bin/sh
# Configure these two variables in cPanel cron, using actual absolute paths.
set -eu
umask 077
: "${GESTIONALE_ROOT:?Set GESTIONALE_ROOT outside public_html}"
: "${GESTIONALE_PHP:?Set absolute PHP 8.3+ CLI path}"
case "$GESTIONALE_ROOT:$GESTIONALE_PHP" in /*:/*) ;; *) exit 64 ;; esac
command -v flock >/dev/null 2>&1 || { echo 'flock richiesto per coordinare cron e backup.' >&2; exit 69; }
cd "$GESTIONALE_ROOT"
exec 9>storage/framework/operations.lock
flock -w 55 9 || exit 0
case "${1:-}" in
 schedule) exec "$GESTIONALE_PHP" artisan schedule:run ;;
 queue)
   command -v timeout >/dev/null 2>&1 || { echo "timeout richiesto per limitare il worker." >&2; exit 69; }
   exec timeout --signal=TERM --kill-after=5 50 "$GESTIONALE_PHP" artisan queue:work database --queue=communications,default --stop-when-empty --max-time=35 --timeout=30 --tries=3 --sleep=1
   ;;
 check) exec "$GESTIONALE_PHP" artisan operations:check --alert ;;
 backup|backup-force)
   was_down=0
   [ ! -f storage/framework/down ] || was_down=1
   cleanup() { [ "$was_down" = 1 ] || "$GESTIONALE_PHP" artisan up; }
   trap cleanup EXIT
   trap 'trap - EXIT; echo "Backup interrotto: lasciare manutenzione fino a verifica." >&2; exit 130' HUP INT TERM
   "$GESTIONALE_PHP" artisan down --retry=60
   if [ "$1" = backup-force ]; then "$GESTIONALE_PHP" artisan operations:backup --force
   else "$GESTIONALE_PHP" artisan operations:backup; fi
   ;;
 *) echo 'Modalità: schedule, queue, check, backup, backup-force.' >&2; exit 64 ;;
esac
