# Segundoprojecto_ISW612
Este es el segundo proyecto de ISW612

## Chat local ISW-612

Editá esta carpeta en Visual Studio Code en Windows. El código PHP, SQLite, Nginx y Ollama se ejecutan en la VM Ubuntu. Windows usa el navegador para mostrar el HTML recibido.

## Flujo

1. El navegador envía el formulario por HTTPS a Nginx.
2. Nginx reenvía al PHP local en 127.0.0.1:8000.
3. PHP comprueba la sesión, el token del formulario y el rol. Valida contraseñas con bcrypt.
4. La pregunta se envía a Ollama en 127.0.0.1:11434. PHP guarda el mensaje y la respuesta en SQLite con el ID del usuario.
5. La página muestra el historial de ese usuario. El panel permite bloquear cuentas normales y contar turnos.

## Instalación en Ubuntu 24.04 LTS

1. Creá una VM con 2 a 4 vCPU y al menos 4 GB de RAM. Agregá un disco de datos. El proyecto usó 25 GiB.
2. Identificá el disco de datos con `lsblk -f`. Para un disco nuevo y vacío, creá una partición GPT y formateala en ext4. Agregá en /etc/fstab la línea `UUID=UUID_REAL /srv/ia ext4 defaults 0 2`, creá /srv/ia, montalo con `sudo mount /srv/ia` y verificá el montaje después de reiniciar con `findmnt /srv/ia`.
3. Copiá este repositorio a la VM con Git o SCP. Desde la raíz ejecutá `sudo bash linux/despliegue.sh`.
4. Creá las dos cuentas con `bash linux/cuenta.sh administrador admin` y `bash linux/cuenta.sh empleado user`. El comando pide cada contraseña de forma oculta.
5. En VirtualBox NAT, reenviá el puerto 443 de Windows al 443 de Ubuntu, el 80 al 80 y el 2222 al 22. Usá 127.0.0.1 como IP del anfitrión. En Windows abrí https://127.0.0.1. El certificado autofirmado se genera en la VM.
6. Comprobá los servicios con `systemctl status ollama isw612-chat nginx`. Verificá con `ss -ltn` que 8000 y 11434 estén en localhost. Probá ambas cuentas, un turno de chat y el bloqueo desde el panel.

## Seguridad y respaldo

El despliegue instala el chat local, Nginx y Ollama. Configurá aparte UFW con entrada denegada por defecto y solo 22, 80 y 443. Probá SSH con llave antes de deshabilitar la contraseña y el acceso root. Activá fail2ban para SSH. Comprobá la rotación de los logs de Nginx y la retención del journal del servicio. El servidor incorporado de PHP se usa como servidor local del laboratorio detrás de Nginx.

Ejecutá `sudo bash linux/respaldo.sh` después de guardar un turno. El script copia la base con SQLite, archiva las configuraciones y verifica una restauración temporal de la base. Para restaurar las configuraciones, descomprimí config.tar.gz en una carpeta temporal, revisá los contenidos e instalalos con el servicio detenido si aplica. Conservá la llave TLS por separado o regenerala en la VM.

No publiques la base, respaldos, contraseñas ni llaves privadas. Registrá las pruebas reales y la comparación de modelos en el informe. Guardá los usos de IA en BITACORA_IA.md.

## Pruebas del proyecto

La carpeta pruebas/ contiene el registro de evidencias y el script medir_modelos.py para ejecutar las cinco preguntas en los tres modelos. Las mediciones y calificaciones de calidad se registran al ejecutar las pruebas en Ubuntu.
