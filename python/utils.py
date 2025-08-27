# utils.py
import os
import logging
from pathlib import Path
from typing import Optional, Any
from dotenv import load_dotenv

# Diretório de logs
LOG_DIR = Path(__file__).resolve().parent.joinpath("..", "logs")
LOG_DIR.mkdir(parents=True, exist_ok=True)
LOG_FILE = LOG_DIR.joinpath("debug.log")


def setup_logging():
    """
    Configura logging em arquivo e console.
    Usa a variável de ambiente DEBUG para decidir o nível de log.
    """
    debug = os.getenv("DEBUG", "false").lower() == "true"
    level = logging.DEBUG if debug else logging.INFO

    logging.basicConfig(
        filename=str(LOG_FILE),
        level=level,
        format="%(asctime)s [%(levelname)s] %(message)s"
    )

    # Console também recebe logs
    console = logging.StreamHandler()
    console.setLevel(level)
    formatter = logging.Formatter("%(asctime)s [%(levelname)s] %(message)s")
    console.setFormatter(formatter)
    logging.getLogger().addHandler(console)


def log_to_file(level: str, message: str, extra: Optional[dict] = None):
    """
    Equivalente à função logToFile do PHP.
    level pode ser: "info" | "error" | "debug"
    """
    msg = message
    if extra:
        msg += f" | {extra}"

    if level.lower() == "error":
        logging.error(msg)
    elif level.lower() == "debug":
        logging.debug(msg)
    else:
        logging.info(msg)


class Database:
    """
    Stub para conexão com banco de dados.
    Substitua pelo driver real que você utiliza (MySQL, PostgreSQL, etc).
    Exemplo com psycopg2 ou mysql-connector-python.
    """

    @staticmethod
    def get_connection() -> Any:
        """
        Retorna uma conexão de banco de dados.
        Atualmente é apenas um stub. Implemente a conexão real aqui.
        """
        # Carregar variáveis do .env
        load_dotenv()

        db_host = os.getenv("DB_HOST")
        db_user = os.getenv("DB_USER")
        db_pass = os.getenv("DB_PASS")
        db_name = os.getenv("DB_NAME")

        # TODO: troque pelo driver que você usa (ex.: psycopg2, mysql-connector-python, pymysql)
        # Exemplo com psycopg2:
        #
        # import psycopg2
        # return psycopg2.connect(
        #     host=db_host,
        #     user=db_user,
        #     password=db_pass,
        #     dbname=db_name
        # )
        #
        # Exemplo com mysql-connector-python:
        #
        # import mysql.connector
        # return mysql.connector.connect(
        #     host=db_host,
        #     user=db_user,
        #     password=db_pass,
        #     database=db_name
        # )
        #
        raise NotImplementedError("Implemente a conexão real no Database.get_connection()")
