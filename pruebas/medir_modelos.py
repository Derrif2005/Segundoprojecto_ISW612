"""Mide los tres modelos del informe desde la VM Ubuntu."""
import csv
import json
import subprocess
import sys
import threading
import time
from pathlib import Path
from urllib.request import Request, urlopen

API = "http://127.0.0.1:11434"
MODELOS = ("llama3.2:1b", "qwen2.5:1.5b", "gemma3:1b")
PREGUNTAS = (
    "¿Qué es una red LAN? Explicálo con un ejemplo de una oficina pequeña.",
    "¿Cuál es la diferencia entre HTTP y HTTPS y cuál se debería utilizar para un inicio de sesión?",
    "Resumí en cuatro líneas por qué se necesita hacer respaldos de una base de datos.",
    "Una empresa tiene ventas y soporte técnico. Proponé dos medidas para que cada departamento acceda solamente a sus archivos.",
    "Explicá en español sencillo qué hace un firewall y mencioná un puerto que debería permanecer cerrado al público en este proyecto.",
)
CAMPOS = ("modelo", "digest", "pregunta", "disco_bytes", "memoria_servicio_pico_mib",
          "primer_texto_s", "tokens_s", "respuesta", "exactitud_0_a_2", "claridad_0_a_2")


def solicitud(ruta, datos=None):
    """Abre la API local con un límite para cada lectura."""
    cuerpo = None if datos is None else json.dumps(datos).encode()
    return urlopen(Request(API + ruta, cuerpo, {"Content-Type": "application/json"}), timeout=900)


def medir(modelo, pregunta, memoria):
    """Mide la latencia desde el envío hasta el primer texto y el pico del servicio."""
    detener = threading.Event()
    muestras = []

    def muestrear():
        while not detener.is_set():
            muestras.append(int(memoria.read_text()))
            detener.wait(0.1)

    monitor = threading.Thread(target=muestrear)
    monitor.start()
    inicio = time.perf_counter()
    primer_texto = None
    texto = []
    final = {}
    try:
        # Mantiene el mismo modelo cargado para las cinco preguntas.
        with solicitud("/api/generate", {
            "model": modelo, "prompt": pregunta,
            "system": "Respondé en español sencillo.",
            "stream": True, "keep_alive": "15m",
            "options": {"num_predict": 180, "num_ctx": 2048, "num_thread": 2, "temperature": 0, "seed": 42},
        }) as respuesta:
            for linea in respuesta:
                parte = json.loads(linea)
                if parte.get("error"):
                    raise RuntimeError(parte["error"])
                fragmento = parte.get("response", "")
                if primer_texto is None and fragmento.strip():
                    primer_texto = time.perf_counter() - inicio
                texto.append(fragmento)
                if parte.get("done"):
                    final = parte
    finally:
        detener.set()
        monitor.join()
    if primer_texto is None or not final.get("eval_duration") or not muestras:
        raise RuntimeError(f"Medición incompleta para {modelo}")
    return {
        "memoria_servicio_pico_mib": round(max(muestras) / 2**20, 1),
        "primer_texto_s": round(primer_texto, 3),
        "tokens_s": round(final["eval_count"] * 10**9 / final["eval_duration"], 2),
        "respuesta": "".join(texto),
    }


def main():
    # Usa el directorio de memoria de systemd para incluir el proceso del modelo.
    grupo = subprocess.check_output(["systemctl", "show", "-p", "ControlGroup", "--value",
                                     "ollama"], text=True).strip()
    memoria = Path("/sys/fs/cgroup") / grupo.lstrip("/") / "memory.current"
    if not grupo or not memoria.is_file():
        sys.exit("Ollama debe correr como servicio systemd con cgroup v2.")
    with solicitud("/api/tags") as respuesta:
        instalados = {modelo["name"]: modelo for modelo in json.load(respuesta)["models"]}
    faltan = set(MODELOS) - instalados.keys()
    if faltan:
        sys.exit("Descargá primero: " + ", ".join(sorted(faltan)))
    destino = Path(sys.argv[1] if len(sys.argv) > 1 else "resultados-modelos.csv")
    # Cada fila queda guardada aunque la siguiente prueba falle.
    with destino.open("x", encoding="utf-8", newline="") as archivo:
        tabla = csv.DictWriter(archivo, fieldnames=CAMPOS)
        tabla.writeheader()
        for modelo in MODELOS:
            # Hace un calentamiento por modelo para medir latencia en caliente.
            with solicitud("/api/generate", {
                "model": modelo, "stream": False, "keep_alive": "15m",
                "options": {"num_ctx": 2048, "num_thread": 2},
            }) as precarga:
                json.load(precarga)
            for pregunta in PREGUNTAS:
                print(f"{modelo}: {pregunta}", flush=True)
                tabla.writerow({"modelo": modelo, "digest": instalados[modelo]["digest"],
                                "pregunta": pregunta, "disco_bytes": instalados[modelo]["size"],
                                **medir(modelo, pregunta, memoria)})
                archivo.flush()
            # Libera el modelo antes de medir el siguiente.
            with solicitud("/api/generate", {"model": modelo, "keep_alive": 0}) as descarga:
                json.load(descarga)


if __name__ == "__main__":
    main()
