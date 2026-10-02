# Start the database server the app asks for in its .env, private to this
# workspace, unless it already runs. Laravel's own SQLite needs no server.
#
# A MySQL or MariaDB app gets its own mysqld, and a PostgreSQL app its own
# postgres. Each listens only on a socket in the workspace's temp folder,
# so no other workspace can reach it, and lets in any user: the app keeps
# the names and passwords it was written with. The socket goes into .env
# (DB_SOCKET, or DB_HOST for PostgreSQL), which never leaves the
# workspace. The same goes into .git/environment, which the box gives to
# every command as its environment: an app whose tests read a committed
# .env.testing still finds its server, and no file of the app changes.
# Each database the app or its tests name is created.
#
# Run from the app's folder, as one `sh -c` script, so no file of ours is
# left in the app.
set -eu

value() {
    sed -n "s/^$1=//p" .env | tail -n 1 | tr -d "\"' \r"
}

# The database names in .env and in the test settings (DB_DATABASE), kept
# only when they are plain names.
names() {
    {
        value DB_DATABASE
        for file in phpunit.xml phpunit.xml.dist phpunit.dist.xml; do
            if [ -f "$file" ]; then
                grep -o 'name="DB_DATABASE" value="[^"]*"' "$file" | sed 's/.*value="//; s/"$//'
            fi
        done
    } | grep -E '^[A-Za-z0-9_]+$' | sort -u || true
}

# Set NAME=value in a file, in place of any NAME line it has.
set_in() {
    { grep -v "^$2=" "$1" 2> /dev/null || true; } > "$1.next"
    printf '%s=%s\n' "$2" "$3" >> "$1.next"
    chmod 600 "$1.next"
    mv "$1.next" "$1"
}

# Point the app at its server: in .env, and for every command when the
# workspace keeps its history.
put() {
    set_in .env "$1" "$2"
    if [ -d .git ]; then set_in .git/environment "$1" "$2"; fi
}

ours() {
    echo "The workspace could not start the app's $1 database. This is our fault." >&2
    if [ -f "$2" ]; then tail -n 20 "$2" >&2; fi
    exit 1
}

[ -f .env ] || exit 0

connection=$(value DB_CONNECTION)
base="${TMPDIR:-/tmp}/database-$(pwd | cksum | cut -d ' ' -f 1)"

case "$connection" in
mysql | mariadb)
    socket="$base/mysqld.sock"

    if ! mysqladmin --socket="$socket" --user=root ping > /dev/null 2>&1; then
        command -v mysqld > /dev/null || ours MySQL /dev/null
        mkdir -p "$base"
        chmod 700 "$base"

        if [ ! -d "$base/data" ]; then
            mysqld --no-defaults --initialize-insecure --datadir="$base/data" --secure-file-priv="$base" > "$base/error.log" 2>&1 || ours MySQL "$base/error.log"
        fi

        # Small settings: one app's development data, beside other workspaces.
        mysqld --no-defaults --daemonize --datadir="$base/data" --socket="$socket" \
            --pid-file="$base/mysqld.pid" --log-error="$base/error.log" --tmpdir="$base" --secure-file-priv="$base" \
            --skip-grant-tables --skip-networking --mysqlx=OFF --performance-schema=OFF \
            --innodb-buffer-pool-size=32M > /dev/null 2>&1 || ours MySQL "$base/error.log"

        tries=0
        until mysqladmin --socket="$socket" --user=root ping > /dev/null 2>&1; do
            tries=$((tries + 1))
            [ "$tries" -lt 60 ] || ours MySQL "$base/error.log"
            sleep 0.5
        done
    fi

    for name in $(names); do
        mysql --socket="$socket" --user=root -e "CREATE DATABASE IF NOT EXISTS \`$name\`"
    done

    put DB_SOCKET "$socket"
    ;;
pgsql)
    bin=$(ls -d /usr/lib/postgresql/*/bin 2> /dev/null | sort -V | tail -n 1)
    user=$(value DB_USERNAME)
    user=${user:-postgres}

    [ -x "$bin/postgres" ] || ours PostgreSQL /dev/null
    mkdir -p "$base"
    chmod 700 "$base"

    # PostgreSQL and its tools will not run as a user with no name, and a
    # workspace's user has only a number. nss_wrapper names it for them.
    if ! id -un > /dev/null 2>&1; then
        wrapper=$(ls /usr/lib/*/libnss_wrapper.so 2> /dev/null | head -n 1)
        [ -n "$wrapper" ] || ours PostgreSQL /dev/null
        printf 'workspace:x:%s:%s::%s:/bin/sh\n' "$(id -u)" "$(id -g)" "$HOME" > "$base/passwd"
        printf 'workspace:x:%s:\n' "$(id -g)" > "$base/group"
        export LD_PRELOAD="$wrapper" NSS_WRAPPER_PASSWD="$base/passwd" NSS_WRAPPER_GROUP="$base/group"
    fi

    if ! "$bin/pg_isready" -q -h "$base" -U "$user" 2> /dev/null; then

        if [ ! -d "$base/data" ]; then
            "$bin/initdb" -D "$base/data" -U "$user" -A trust > "$base/error.log" 2>&1 || ours PostgreSQL "$base/error.log"
        fi

        "$bin/pg_ctl" -D "$base/data" -l "$base/error.log" -w -t 30 \
            -o "-c listen_addresses='' -c unix_socket_directories='$base' -c shared_buffers=16MB" start > /dev/null || ours PostgreSQL "$base/error.log"
    fi

    for name in $(names); do
        "$bin/psql" -h "$base" -U "$user" -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname = '$name'" | grep -q 1 \
            || "$bin/createdb" -h "$base" -U "$user" "$name"
    done

    put DB_HOST "$base"
    ;;
esac
