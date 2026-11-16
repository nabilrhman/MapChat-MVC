#!/bin/sh
# The container's main process. Starts PHP-FPM and Apache at once, so the
# site answers right away after a wake-up; brings an older database up to
# date (database/migrate.php); then starts the chat socket and the chat
# worker, and starts either again if it stops.
#
# On SIGTERM (a deploy or a restart; SIGQUIT and SIGINT too), everything gets SIGTERM: the chat
# socket closes each page's socket with "restarting" (1012) and the pages
# reconnect to the next container, catching up from the last message they
# have. If Apache stops, the container exits, so the host restarts it.

# Runs a command, and runs it again a second after it stops, until SIGTERM
keep_running() {
    (
        trap 'exit 0' TERM INT
        while :; do
            "$@"
            echo "$* stopped (exit $?), starting it again" >&2
            sleep 1
        done
    ) &
}

stop_all() {
    # Ignore the TERM sent to the whole group below, then wait for the rest
    trap '' TERM INT QUIT
    kill -TERM 0
    # PHP-FPM puts itself in a group of its own
    kill -QUIT "$fpm" 2>/dev/null
    wait
    exit 0
}
trap stop_all TERM INT QUIT

php-fpm -F &
fpm=$!

# A pid file left by the last run (a restarted container) would stop Apache
# starting, if its old pid now belongs to another process
rm -f /var/run/apache2/apache2.pid
apache2ctl -D FOREGROUND &
apache=$!

(
    php database/migrate.php
    # After the upgrade: the socket saves messages with columns it adds
    keep_running php bin/chat-socket.php
    keep_running php bin/chat-worker.php
    wait
) &

wait "$apache"
echo "Apache stopped, stopping the container" >&2
stop_all
