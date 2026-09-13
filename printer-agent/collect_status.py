#!/usr/bin/env python3
"""
collect_status.py
==================
Coleta um snapshot do estado completo da impressora via MQTT local e grava
num banco MySQL/MariaDB externo (ex: o mesmo host onde fica o site/HTML).
Feito pra rodar via crontab a cada minuto: conecta na impressora, pede um
dump completo ("pushall"), acumula os reports que chegam por alguns
segundos (a Bambu manda em deltas — nem todo report traz todos os campos,
por isso a fusão), grava uma linha na tabela `snapshots` e sai.

Reaproveita o mesmo .env do hms_watch.py (PRINTER_IP, ACCESS_CODE, SERIAL) -
roda os dois a partir da mesma pasta sem conflito (a impressora aceita
varios clientes MQTT simultaneos).

A tabela precisa existir antes (ver schema.sql) - esse script so faz
INSERT, de proposito, pra poder rodar com um usuario de banco restrito
(sem permissao de CREATE/ALTER/DROP).

Uso: python collect_status.py
"""

import json
import os
import ssl
import sys
import time
from datetime import datetime, timezone

import paho.mqtt.client as mqtt
import pymysql
from dotenv import load_dotenv

load_dotenv()

PRINTER_IP = os.environ["PRINTER_IP"]
ACCESS_CODE = os.environ["ACCESS_CODE"]
SERIAL = os.environ["SERIAL"]

DB_HOST = os.environ["DB_HOST"]
DB_PORT = int(os.environ.get("DB_PORT", "3306"))
DB_NAME = os.environ["DB_NAME"]
DB_USER = os.environ["DB_USER"]
DB_PASSWORD = os.environ["DB_PASSWORD"]
DB_SSL = os.environ.get("DB_SSL", "true").strip().lower() != "false"

COLLECT_TIMEOUT = float(os.environ.get("COLLECT_TIMEOUT", "10"))

# campos escalares extraidos como coluna propria - o resto do objeto "print"
# fica preservado no raw_json mesmo assim
SCALAR_FIELDS = [
    "gcode_state", "print_type", "subtask_name", "mc_percent", "mc_remaining_time",
    "layer_num", "total_layer_num", "nozzle_temper", "nozzle_target_temper",
    "bed_temper", "bed_target_temper", "chamber_temper", "cooling_fan_speed",
    "heatbreak_fan_speed", "big_fan1_speed", "big_fan2_speed", "spd_lvl", "spd_mag",
    "wifi_signal", "print_error", "nozzle_diameter", "nozzle_type",
]


def collect() -> dict:
    """Conecta, pede pushall, funde os reports recebidos por COLLECT_TIMEOUT
    segundos (a Bambu manda deltas, por isso a fusao) e retorna o dict
    completo de 'print' acumulado."""
    state: dict = {}

    def on_connect(client, userdata, flags, rc, properties=None):
        client.subscribe(f"device/{SERIAL}/report")
        client.publish(
            f"device/{SERIAL}/request",
            json.dumps({"pushing": {"sequence_id": "0", "command": "pushall"}}),
        )

    def on_message(client, userdata, msg):
        try:
            payload = json.loads(msg.payload)
        except json.JSONDecodeError:
            return
        p = payload.get("print")
        if p:
            state.update(p)

    client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
    client.username_pw_set("bblp", ACCESS_CODE)
    client.tls_set(cert_reqs=ssl.CERT_NONE)
    client.tls_insecure_set(True)
    client.on_connect = on_connect
    client.on_message = on_message

    client.connect(PRINTER_IP, 8883, keepalive=30)
    client.loop_start()
    time.sleep(COLLECT_TIMEOUT)
    client.loop_stop()
    client.disconnect()

    return state


def to_row(p: dict) -> dict:
    row = {"captured_at": datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")}
    for field in SCALAR_FIELDS:
        row[field] = p.get(field)
    row["hms_json"] = json.dumps(p.get("hms", []), ensure_ascii=False)
    row["ams_json"] = json.dumps(p.get("ams", {}), ensure_ascii=False)
    row["vt_tray_json"] = json.dumps(p.get("vt_tray", {}), ensure_ascii=False)
    row["lights_json"] = json.dumps(p.get("lights_report", []), ensure_ascii=False)
    row["raw_json"] = json.dumps(p, ensure_ascii=False)
    return row


def save(row: dict) -> None:
    ssl_ctx = ssl.create_default_context() if DB_SSL else None
    conn = pymysql.connect(
        host=DB_HOST,
        port=DB_PORT,
        user=DB_USER,
        password=DB_PASSWORD,
        database=DB_NAME,
        ssl=ssl_ctx,
        connect_timeout=10,
    )
    try:
        cols = ", ".join(f"`{c}`" for c in row.keys())
        placeholders = ", ".join("%s" for _ in row)
        with conn.cursor() as cur:
            cur.execute(
                f"INSERT INTO snapshots ({cols}) VALUES ({placeholders})",
                list(row.values()),
            )
        conn.commit()
    finally:
        conn.close()


def main() -> None:
    state = collect()
    if not state:
        print(f"[!] nenhum report recebido em {COLLECT_TIMEOUT}s — impressora offline?", file=sys.stderr)
        sys.exit(1)

    row = to_row(state)
    try:
        save(row)
    except pymysql.MySQLError as e:
        print(f"[!] falha ao gravar no MySQL ({DB_HOST}): {e}", file=sys.stderr)
        sys.exit(1)
    print(
        f"snapshot salvo em {DB_USER}@{DB_HOST}/{DB_NAME} — {row['captured_at']} "
        f"estado={row['gcode_state']} {row['mc_percent']}% "
        f"(campos capturados: {len(state)})"
    )


if __name__ == "__main__":
    main()
