#!/usr/bin/env python3
# master.py

import os
import time
import logging
from pathlib import Path
from dotenv import load_dotenv
from typing import Any, Callable, Optional
from utils import log_to_file, setup_logging, Database


# Importar os módulos Python que correspondem aos scripts PHP
import wazealerts
import notifications
import worker_notifications
import wazejobtraficc
import dadoscemadem
import hidrologicocemadem
import gerar_xml
import alerts_por_email

# TODO: substituir pelo seu Database quando enviar config/configbd.php convertido
# from configbd import Database

# Diretório de logs
LOG_DIR = Path(__file__).resolve().parent.joinpath("..", "logs")
LOG_DIR.mkdir(parents=True, exist_ok=True)
LOG_FILE = LOG_DIR.joinpath("debug.log")

def setup_logging():
    """Configura logging em arquivo + console"""
    debug = os.getenv("DEBUG", "false").lower() == "true"
    level = logging.DEBUG if debug else logging.INFO
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
    """Simula a função logToFile do PHP"""
    msg = f"{message}"
    if extra:
        msg += f" | {extra}"
    if level.lower() == "error":
        logging.error(msg)
    else:
        logging.info(msg)

def execute_script_with_logging(script_name: str, func: Callable[[Any], None], conn: Any):
    """Executa um script Python e mede o tempo de execução"""
    try:
        log_to_file("info", f"Iniciando script: {script_name}")
        script_start = time.time()
        func(conn)  # executa a função run(conn) do módulo
        duration = round(time.time() - script_start, 2)
        print(f"✅ Script finalizado: {script_name} em {duration} segundos")
        log_to_file("info", f"Finalizando script: {script_name}", {"tempo_execucao": duration})
    except Exception as e:
        log_to_file("error", f"Erro ao executar {script_name}", {"message": str(e)})
        logging.exception(f"Erro em {script_name}: {e}")

def main():
    start_time = time.time()

    # Carrega .env
    env_path = Path(__file__).resolve().parent.joinpath(".env")
    if not env_path.exists():
        log_to_file("error", f"Arquivo .env não encontrado no caminho: {env_path}")
        print("Arquivo .env não encontrado.")
        return

    load_dotenv(dotenv_path=env_path)
    setup_logging()
    conn = Database.get_connection()
    log_to_file("info", ".env carregado com sucesso")

    # TODO: quando você enviar a classe Database em Python
    conn = None  # Exemplo: conn = Database.get_connection()

    # Data/hora de referência
    current_time = time.strftime("%Y-%m-%d %H:%M:%S", time.localtime())
    print(f"Horário de referência: {current_time}")

    print("🟡 Iniciando execução de scripts...")

    scripts = [
        ("wazealerts", wazealerts.run),
        ("notifications", notifications.run),
        ("worker_notifications", worker_notifications.run),
        ("wazejobtraficc", wazejobtraficc.run),
        ("dadoscemadem", dadoscemadem.run),
        ("hidrologicocemadem", hidrologicocemadem.run),
        ("gerar_xml", gerar_xml.run),
        ("alerts_por_email", alerts_por_email.run),
    ]

    for name, func in scripts:
        print(f"\n🔹 Executando: {name}")
        execute_script_with_logging(name, func, conn)

    total_time = round(time.time() - start_time, 2)
    print("\n✅ Todos os scripts concluídos.")
    print(f"⏱️ Tempo total de execução: {total_time} segundos")
    log_to_file("info", "Tempo total de execução do master script", {"totalTime": total_time})

if __name__ == "__main__":
    main()
