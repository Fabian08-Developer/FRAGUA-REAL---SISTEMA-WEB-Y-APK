#!/usr/bin/env bash
# Despliegue (15/10/2026) — Copia de seguridad diaria de Fragua Real: la base de datos (pg_dump) y los
# archivos privados (soportes de compras, fotos de productos, reportes exportados).
#
# Corre como root desde /etc/cron.d/fragua-real (ver cron-fragua-real). Deja los respaldos en
# /var/backups/fragua-real y borra los de más de DIAS días. Ajustar las rutas y el nombre de la base
# si no son estos. Probarlo a mano una vez:  sudo bash respaldo-fragua-real.sh
#
# Un respaldo que solo vive en el mismo VPS se pierde con el VPS: copiar la carpeta a otro lugar
# (README-ENTREGA, "Copias de seguridad").
set -euo pipefail

BASE="fragua_real"
APP="/var/www/fragua-real/backend"
DESTINO="/var/backups/fragua-real"
DIAS=14

FECHA="$(date +%Y-%m-%d_%H%M)"
umask 077
mkdir -p "$DESTINO"

# Formato propio de PostgreSQL (-Fc): comprimido y se restaura con pg_restore.
runuser -u postgres -- pg_dump -Fc "$BASE" > "$DESTINO/base-$FECHA.dump"

tar -czf "$DESTINO/archivos-$FECHA.tar.gz" -C "$APP/storage/app" private

find "$DESTINO" -type f \( -name 'base-*.dump' -o -name 'archivos-*.tar.gz' \) -mtime +"$DIAS" -delete
