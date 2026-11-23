#!/usr/bin/env bash
# Ejecuta con sudo después de crear una cuenta y guardar una conversación.
set -euo pipefail
umask 077
mountpoint -q /srv/ia
test -s /srv/ia/app/chat.sqlite
# Crea una carpeta única y copia la base con la herramienta de SQLite.
install -d -m 700 /srv/ia/backups
destino=$(mktemp -d /srv/ia/backups/backup.XXXXXXXX)
sqlite3 /srv/ia/app/chat.sqlite ".backup '$destino/chat.sqlite'"
# Empaqueta configuraciones sin incluir la llave privada TLS.
tar -czf "$destino/config.tar.gz" -C / etc/nginx/sites-available/isw612-chat etc/systemd/system/isw612-chat.service etc/systemd/system/ollama.service.d/override.conf
# Verifica que la base restaurada en una carpeta temporal se puede leer.
restaurado=$(mktemp -d)
trap 'rm -f -- "$restaurado/chat.sqlite"; rmdir -- "$restaurado"' EXIT
cp "$destino/chat.sqlite" "$restaurado/chat.sqlite"
test "$(sqlite3 "$restaurado/chat.sqlite" 'PRAGMA integrity_check;')" = ok
sqlite3 "$restaurado/chat.sqlite" 'SELECT COUNT(*) FROM messages;'
printf 'Respaldo verificado: %s\n' "$destino"
