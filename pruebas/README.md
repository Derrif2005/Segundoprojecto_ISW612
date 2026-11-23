# Evidencias de ISW-612

Fecha y responsable de cada prueba: completar al ejecutar. Guardá evidencia de cuentas y conversaciones de práctica. Cuidá que las capturas no muestren contraseñas, cookies, llaves privadas ni historiales personales.

| Prueba | Resultado esperado | Estado |
|---|---|---|
| Reiniciar y comprobar montaje y servicios | /srv/ia en ext4 y Nginx, PHP y Ollama activos | Pendiente de repetir el conjunto |
| Cuenta normal y cuenta administradora | Ingreso válido, cada una ve únicamente sus turnos | Pendiente |
| Panel con cuenta normal | HTTP 403 | Pendiente |
| Bloqueo desde el panel | El usuario bloqueado pierde acceso y recupera acceso al desbloquear | Pendiente |
| Login inválido y expiración | Evento en el journal, bloqueo temporal y sesión vencida | Pendiente |
| HTTPS, cabeceras y redirección | HTTP 301 de 80 a 443, TLS 1.2 o 1.3, cabeceras configuradas | Pendiente |
| Puertos observados desde cliente propio | Solo servicios previstos alcanzables, 11434 inaccesible | Pendiente |
| UFW, SSH y fail2ban | Entrada denegada por defecto, SSH por llave y evidencia de fail2ban | Pendiente |
| Límites de uso | HTTP 429 al superar límite, rechazo de mensajes largos | Pendiente |
| Permisos y logs | Cuentas de servicio dedicadas, registros disponibles y rotación | Pendiente |
| Respaldo y restauración | La copia SQLite recupera un turno real de práctica y el archivo de configuraciones puede extraerse | Pendiente |
| Cinco pruebas de prompt injection | Documentar prompt, respuesta, impacto observado y mitigación | Pendiente |

## Comparación de modelos

Descargá en la VM `llama3.2:1b`, `qwen2.5:1.5b` y `gemma3:1b` con `ollama pull`. Cerrá otras conversaciones durante la medición y ejecutá `python3 pruebas/medir_modelos.py resultados-modelos.csv`. El CSV se crea solo si no existe. El tamaño viene de la API de Ollama. La memoria es el pico del cgroup de todo el servicio e incluye la memoria que el kernel carga a ese grupo, como caché. Se precarga un modelo a la vez y se mide el tiempo al primer texto con el modelo caliente. Se usan dos hilos de inferencia por modelo. Para tokens/s se divide eval_count entre eval_duration en segundos. Calificá exactitud y claridad de 0 a 2 cada una según la respuesta real y justificá las puntuaciones en el informe.

Fuentes: [Generación](https://docs.ollama.com/api/generate) y [lista de modelos](https://docs.ollama.com/api/tags).
