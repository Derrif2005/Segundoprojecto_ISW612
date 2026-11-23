# Prueba inicial de infraestructura

Fecha local: 22 de noviembre de 2026. VM: ServerUbunto.

| Comprobación | Resultado observado |
|---|---|
| Montaje | /srv/ia, /dev/sdb1, ext4, lectura y escritura |
| Servicios | Nginx, isw612-chat y Ollama activos |
| Separación de cuentas | PHP corre como isw612-chat y Ollama como ollama |
| Puertos internos | PHP escucha en 127.0.0.1:8000 y Ollama en 127.0.0.1:11434 |
| Puertos de cliente | En Windows, localhost:8000 y localhost:11434 sin conexión. La VM usa VirtualBox NAT |
| HTTP | 301 a https://127.0.0.1/ |
| HTTPS | 200 en Ubuntu y desde Windows por el túnel SSH en 8443 |
| TLS | 200 con TLS 1.2 y con TLS 1.3 |
| Cabeceras | X-Content-Type-Options nosniff, X-Frame-Options DENY y Referrer-Policy no-referrer |
| Cookie de sesión | Secure, HttpOnly y SameSite=Lax, valor omitido |
| Panel sin sesión | HTTP 403 |
| Fail2ban | Inactivo al momento de la prueba |

Queda pendiente comprobar el panel con una cuenta autenticada de rol usuario, el firewall y la restauración. El alcance de la revisión de puertos de Windows fue localhost y las reglas NAT existentes.
