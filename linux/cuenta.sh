#!/usr/bin/env bash
# Detiene el comando si falta una variable o falla alguna operación.
set -euo pipefail
# Pide la contraseña sin mostrarla ni pasarla como argumento.
read -rsp 'Contraseña (mínimo 12 bytes): ' password
printf '\n'
# Ejecuta PHP con la misma cuenta y ruta que usa el servicio.
printf '%s\n' "$password" | sudo -u isw612-chat php /opt/isw612-chat/app/cuenta.php "$@"
