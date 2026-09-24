#!/bin/sh
set -e

: "${DB_HOST:=127.0.0.1}"
: "${DB_PORT:=3306}"
: "${DB_NAME:=ispadmin}"
: "${DB_USER:=ispadmin}"
: "${RADIUS_BIND:=127.0.0.1}"
export DB_HOST DB_PORT DB_NAME DB_USER RADIUS_BIND

if [ -z "$DB_PASS" ] || [ -z "$RADIUS_LOCAL_SECRET" ]; then
    echo "freeradius: DB_PASS and RADIUS_LOCAL_SECRET must be set (see .env.example)" >&2
    exit 1
fi

# The test client (ISPadmin) sends from the address it connects to.
if [ "$RADIUS_BIND" = "*" ] || [ "$RADIUS_BIND" = "0.0.0.0" ]; then
    echo "freeradius: WARNING - RADIUS_BIND=$RADIUS_BIND listens on every interface. Bind it to the management network." >&2
    RADIUS_LOCAL_CLIENT=127.0.0.1
else
    RADIUS_LOCAL_CLIENT="$RADIUS_BIND"
fi
export RADIUS_LOCAL_CLIENT

# Check the configuration first, so a mistake shows up clearly in `docker compose logs`.
freeradius -C -l stdout >/dev/null || { freeradius -XC -l stdout | tail -n 30; exit 1; }

exec "$@"
