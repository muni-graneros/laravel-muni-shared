#!/usr/bin/env bash
#
# Corre la suite del paquete contra un MariaDB real y desechable.
#
# Existe porque la suite corre en SQLite y la producción del ecosistema corre en
# MariaDB, y esa diferencia ya escondió un defecto crítico: `desvincular()`
# fallaba SIEMPRE en MariaDB —o sea, la retención entera caída— con la suite en
# verde. Ningún comentario ni ninguna revisión lo detectó; lo detectó apuntar la
# suite al motor de producción.
#
# Obligatorio antes de publicar una versión del paquete. Que el contenedor sea
# desechable es parte del punto: no hay que preparar nada ni acordarse de nada.
#
#   ./tools/pest-mariadb.sh                       # suite completa
#   ./tools/pest-mariadb.sh tests/Privacidad      # un subconjunto
#
set -euo pipefail

CONTENEDOR=muni-shared-mariadb-test
PUERTO=${MUNI_MARIADB_PORT:-33061}
IMAGEN=${MUNI_MARIADB_IMAGE:-mariadb:11}

cd "$(dirname "$0")/.."

# Un contenedor colgado de una corrida anterior tendría el esquema de esa
# corrida: se tira y se levanta de nuevo, no se reutiliza.
docker rm -f "$CONTENEDOR" >/dev/null 2>&1 || true

docker run --rm -d --name "$CONTENEDOR" \
    -e MARIADB_ROOT_PASSWORD=secret \
    -e MARIADB_DATABASE=prueba \
    -p "$PUERTO":3306 "$IMAGEN" >/dev/null

limpiar() { docker rm -f "$CONTENEDOR" >/dev/null 2>&1 || true; }
trap limpiar EXIT

echo "Esperando a $IMAGEN en el puerto $PUERTO…"

# Por TCP y contra la base `prueba`, NO con `mariadb-admin ping` a secas: durante
# la inicialización el entrypoint levanta un servidor temporal que escucha solo
# en el socket y ya responde al ping. Esperar ese ping da la suite entera en rojo
# con «MySQL server has gone away» cuando el servidor temporal se apaga.
listo() {
    docker exec "$CONTENEDOR" \
        mariadb -h127.0.0.1 --protocol=tcp -uroot -psecret -e 'select 1' prueba >/dev/null 2>&1
}

for _ in $(seq 1 60); do
    if listo; then
        break
    fi
    sleep 1
done

if ! listo; then
    echo "El contenedor $CONTENEDOR no respondió a tiempo." >&2
    exit 1
fi

MUNI_MARIADB_HOST=127.0.0.1 MUNI_MARIADB_PORT="$PUERTO" \
    vendor/bin/pest "$@"
