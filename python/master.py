#!/usr/bin/env python3
# master.py
import os
import time
import logging
from dotenv import load_dotenv
from pathlib import Path
from typing import Callable, Optional, Any

# Importa os scripts convertidos
import wazealerts
import notifications
import worker_notifications
import wazejobtraficc
import dadoscemadem
import hidrologicocemadem
import gerar_xml
import alerts_por_email

# Opcional: gerar_json e wazejob foram enviados também
import gerar_json
import wazejob

# TODO: importe sua classe Database quando enviar config (ex: from config.configbd import Database)

LOG_DIR = Path(__file__).resolve().parent.joinpath("..", "logs")
LOG_DIR.mkdir(parents=True, exist_ok=True)
LOG_FILE = LOG_DIR.joinpath("debug.log")

def setup_logging():
    level = logging.DEBUG if os.getenv("DEBUG", "false").lower() == "true" else logging.INFO
    logging.basicConfig(
        filename=str(LOG_FILE),
        level=level,
        format="%(asctime)s [%(levelname)s] %(message)s"
    )
    console = logging.StreamHandler()
    console.setLevel(level)
    formatter = logging.Formatter("%(asctime)s [%(levelname)s] %(message)s")
    console.setFormatter(formatter)
    logging.getLogger().addHandler(console)

def log_to_file(level: str, message: str, extra: Optional[dict] = None):
    """Stub que replica a função logToFile do PHP. Você pode substituir por sua implementação."""
    msg = f"{message}"
    if extra:
        msg += f" | {extra}"
    if level.lower() == "error":
        logging.error(msg)
    else:
        logging.info(msg)

def execute_script_with_logging(script_name: str, func: Callable[[Any], None], conn: Any):
    start = time.time()
    try:
        log_to_file("info", f"Iniciando script: {script_name}", {"script": script_name})
        script_start = time.time()
        func(conn)
        script_end = time.time()
        duration = round(script_end - script_start, 2)
        print(f"✅ Script finalizado: {script_name} em {duration} segundos")
        log_to_file("info", f"Finalizando script: {script_name}", {"tempo_execucao": duration})
    except Exception as e:
        log_to_file("error", f"Erro ao executar {script_name}", {"message": str(e)})
        logging.exception(f"Erro em {script_name}: {e}")

def main():
    start_time = time.time()

    # Carrega .env
    load_dotenv()
    setup_logging()
    log_to_file("info", ".env carregado com sucesso")

    # TODO: obter conexão do banco assim que enviar config/configbd.py
    conn = None  # Ex: conn = Database.get_connection()

    print("🟡 Iniciando execução de scripts...")
    scripts = [
        ("wazealerts", wazealerts.run),
        ("notifications", notifications.run),
        ("worker_notifications", worker_notifications.run),
        ("wazejobtraficc", wazejobtraficc.run),
        ("dadoscemadem", dadoscemadem.run),
        ("hidrologicocemadem", hidrologicocemadem.run),
        ("gerar_xml", gerar_xml.run),
        ("alerts_por_email", alerts_por_email.run)
    ]

    # Caso queira rodar também gerar_json e wazejob (não estavam no master PHP original),
    # deixei como comentário — descomente se quiser:
    # scripts.append(("gerar_json", gerar_json.run))
    # scripts.append(("wazejob", wazejob.run))

    for name, func in scripts:
        print(f"\n🔹 Executando: {name}")
        execute_script_with_logging(name, func, conn)

    total_time = round(time.time() - start_time, 2)
    print("\n✅ Todos os scripts concluídos.")
    print(f"⏱️ Tempo total de execução: {total_time} segundos")
    log_to_file("info", "Tempo total de execução do master script", {"totalTime": total_time})

if __name__ == "__main__":
    main()
