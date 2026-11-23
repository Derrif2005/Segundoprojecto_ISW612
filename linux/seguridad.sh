#!/usr/bin/env bash
# Ejecutá desde una sesión SSH abierta con llave y conservá la consola de VirtualBox.
set -euo pipefail
[[ $EUID == 0 && -n ${SUDO_USER:-} && -n ${SSH_CONNECTION:-} ]] || {
    echo 'Entrá por SSH con llave y ejecutá sudo --preserve-env=SSH_CONNECTION bash linux/seguridad.sh.'
    exit 1
}
# Comprueba que existe una cuenta con llave antes de deshabilitar contraseñas.
home_usuario=$(getent passwd "$SUDO_USER" | cut -d: -f6)
test -s "$home_usuario/.ssh/authorized_keys"
apt-get update
apt-get install -y ufw fail2ban
# Habilita los puertos requeridos antes de activar la política de entrada.
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable
# Valida OpenSSH antes de recargarlo para las conexiones nuevas.
install -m 644 linux/sshd.conf /etc/ssh/sshd_config.d/00-isw612.conf
sshd -t
systemctl reload ssh
# Utiliza el journal del servicio SSH para detectar fallos.
install -m 644 linux/fail2ban.local /etc/fail2ban/jail.d/isw612-sshd.local
systemctl enable --now fail2ban
systemctl restart fail2ban
ufw status verbose
sshd -T | awk '/^(permitrootlogin|passwordauthentication|kbdinteractiveauthentication|pubkeyauthentication) /'
fail2ban-client status sshd
