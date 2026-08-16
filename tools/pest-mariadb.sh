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

# Nombre y puerto POR CORRIDA, y esto no es prolijidad: este repo lo editan
# sesiones en paralelo. Con el nombre fijo que había antes, la segunda corrida
# empezaba con un `docker rm -f` que le volaba la base a la primera EN PLENA
# EJECUCIÓN —comprobado: dos corridas simultáneas del mismo subconjunto se
# tumbaron mutuamente, las dos con salida 2 y fallos que no eran del código—, y
# además chocaban por el puerto fijo. El sufijo es el PID, que el sistema
# garantiza único entre procesos vivos.
CONTENEDOR=${MUNI_MARIADB_CONTENEDOR:-muni-shared-mariadb-test-$$}
# Puerto 0 = que lo elija el kernel entre los libres; se consulta después con
# `docker port`. Se puede fijar con MUNI_MARIADB_PORT, pero fijarlo devuelve el
# choque: es para depurar una corrida sola, no para el uso normal.
PUERTO_PEDIDO=${MUNI_MARIADB_PORT:-0}
IMAGEN=${MUNI_MARIADB_IMAGE:-mariadb:11}

cd "$(dirname "$0")/.."

# Un contenedor colgado de una corrida anterior CON ESTE MISMO NOMBRE tendría el
# esquema de esa corrida: se tira y se levanta de nuevo, no se reutiliza. Con el
# nombre por PID esto solo puede alcanzar a un cadáver de un PID reciclado,
# nunca a la corrida de otra sesión.
docker rm -f "$CONTENEDOR" >/dev/null 2>&1 || true

docker run --rm -d --name "$CONTENEDOR" \
    -e MARIADB_ROOT_PASSWORD=secret \
    -e MARIADB_DATABASE=prueba \
    -p "127.0.0.1:$PUERTO_PEDIDO:3306" "$IMAGEN" >/dev/null

limpiar() { docker rm -f "$CONTENEDOR" >/dev/null 2>&1 || true; }
trap limpiar EXIT

# El puerto REAL, que con 0 lo asignó el kernel y solo lo sabe Docker.
PUERTO=$(docker port "$CONTENEDOR" 3306/tcp | head -n1 | sed 's/.*://')

if [ -z "$PUERTO" ]; then
    echo "No se pudo averiguar el puerto publicado de $CONTENEDOR." >&2
    exit 1
fi

echo "Esperando a $IMAGEN en el puerto $PUERTO (contenedor $CONTENEDOR)…"

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

# Y ahora la pregunta que el verde de arriba no contesta: ¿corrió CONTRA ESTO?
#
# Sin la variable, la misma suite pasa en SQLite y sale con 0. O sea que este
# script podría estar levantando un contenedor, ignorándolo y felicitándose.
# Se comprueba donde no se puede fingir: si la suite corrió acá, las migraciones
# dejaron las tablas del módulo y los dos triggers del guardia.
consultar() {
    docker exec "$CONTENEDOR" \
        mariadb -h127.0.0.1 --protocol=tcp -uroot -psecret -N -B -e "$1"
}

tablas=$(consultar "select count(*) from information_schema.tables
    where table_schema='prueba' and table_name in ('privacidad_bitacora','privacidad_textos');")
triggers=$(consultar "select count(*) from information_schema.triggers
    where trigger_schema='prueba'
      and trigger_name in ('privacidad_bitacora_inmutable_bu','privacidad_textos_inmutable_bu');")

if [ "$tablas" != "2" ] || [ "$triggers" != "2" ]; then
    echo "La suite NO corrió contra este MariaDB: la base «prueba» quedó sin el esquema del módulo" >&2
    echo "(tablas=$tablas, triggers=$triggers). El verde de arriba no vale." >&2
    exit 1
fi

echo "Verificado contra el motor: la base «prueba» tiene el esquema y los $triggers triggers del guardia."
