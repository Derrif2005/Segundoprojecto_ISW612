#!/usr/bin/env bash
# Ejecuta desde Ubuntu con sudo. Conserva el disco y los modelos existentes.
set -euo pipefail
cd "$(dirname "$0")/.."
# Evita escribir en el disco raíz si falta el montaje de datos.
mountpoint -q /srv/ia || { echo 'Montá /srv/ia antes de desplegar.'; exit 1; }
apt-get update
apt-get install -y php-cli php-curl php-sqlite3 sqlite3 nginx openssl curl zstd
# Instala Ollama solo si la VM aún no lo tiene.
if ! command -v ollama >/dev/null; then
    curl -fsSL https://ollama.com/install.sh | sh
fi
# Separa la cuenta de servicio y sus datos del código.
id isw612-chat >/dev/null 2>&1 || useradd --system --user-group --shell /usr/sbin/nologin isw612-chat
install -d -m 755 /opt/isw612-chat/app/public
install -m 644 app/public/index.php /opt/isw612-chat/app/public/index.php
install -m 644 app/cuenta.php /opt/isw612-chat/app/cuenta.php
install -d -o isw612-chat -g isw612-chat -m 750 /srv/ia/app
install -d -o ollama -g ollama -m 750 /srv/ia/models
install -d -m 755 /etc/systemd/system/ollama.service.d
install -m 644 linux/ollama.conf /etc/systemd/system/ollama.service.d/override.conf
install -m 644 linux/isw612-chat.service /etc/systemd/system/isw612-chat.service
systemctl daemon-reload
systemctl enable ollama
systemctl restart ollama
# Espera a que la API local esté lista antes de descargar el modelo.
for attempt in {1..30}; do
    if curl -fsS http://127.0.0.1:11434/api/tags >/dev/null 2>&1; then break; fi
    sleep 1
done
ollama pull qwen2.5:1.5b
# Genera la llave TLS directamente en Ubuntu y conserva certificados previos.
install -d -m 700 /etc/nginx/tls
if [[ ! -s /etc/nginx/tls/isw612.key || ! -s /etc/nginx/tls/isw612.crt ]]; then
    umask 077
    openssl req -x509 -newkey rsa:2048 -sha256 -days 365 -nodes -subj /CN=127.0.0.1 -addext subjectAltName=IP:127.0.0.1 -keyout /etc/nginx/tls/isw612.key -out /etc/nginx/tls/isw612.crt
    umask 022
fi
install -m 644 linux/nginx.conf /etc/nginx/sites-available/isw612-chat
ln -sfn /etc/nginx/sites-available/isw612-chat /etc/nginx/sites-enabled/isw612-chat
if [[ -L /etc/nginx/sites-enabled/default ]]; then unlink /etc/nginx/sites-enabled/default; fi
nginx -t
systemctl enable --now nginx isw612-chat
systemctl reload nginx
systemctl restart isw612-chat
