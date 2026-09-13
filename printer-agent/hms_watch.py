#!/usr/bin/env python3
"""
bambu-hms-watch
================
Monitora os codigos HMS (Health Management System) de uma impressora Bambu
Lab via MQTT local e notifica quando um alerta ativo aparece (ou some).

Nao mexe na impressao (nao pausa nem cancela) - so le e avisa. Rode junto
com o bambu-ai (deteccao visual de spaghetti) se quiser as duas camadas.

A decodificacao do codigo HMS (attr/code -> HMS_AAAA_BBBB_CCCC_DDDD, severidade
e modulo) segue o mesmo esquema usado pelo projeto ha-bambulab (MIT License,
https://github.com/greghesp/ha-bambulab). Os textos de erro em hms_data/*.json.gz
sao vendorizados desse mesmo projeto - ver hms_data/SOURCE.txt.
"""

import gzip
import json
import os
import ssl
import time
from pathlib import Path

import paho.mqtt.client as mqtt
import requests
from dotenv import load_dotenv

load_dotenv()

PRINTER_IP = os.environ["PRINTER_IP"]
ACCESS_CODE = os.environ["ACCESS_CODE"]
SERIAL = os.environ["SERIAL"]
DEVICE_TYPE = os.environ.get("DEVICE_TYPE", "A1")
LANGUAGE = os.environ.get("HMS_LANGUAGE", "pt")

NOTIFY_MIN_SEVERITY = os.environ.get("NOTIFY_MIN_SEVERITY", "serious")
WHATSAPP_WEBHOOK_URL = os.environ.get("WHATSAPP_WEBHOOK_URL", "").strip()
WHATSAPP_API_KEY = os.environ.get("WHATSAPP_API_KEY", "").strip()
WHATSAPP_CHAT_ID = os.environ.get("WHATSAPP_CHAT_ID", "").strip()
NTFY_TOPIC = os.environ.get("NTFY_TOPIC", "").strip()

# marcos de progresso (%) pra notificar - ex "50,90" avisa ao passar de 50% e 90%
PROGRESS_MILESTONES = sorted(
    {int(x) for x in os.environ.get("PROGRESS_MILESTONES", "").split(",") if x.strip()}
)

DATA_DIR = Path(__file__).parent / "hms_data"

# attr/code -> texto, severidade e modulo: mesmo esquema do ha-bambulab
# (code >> 16 = severidade; (attr >> 24) & 0xFF = modulo)
SEVERITY_LEVELS = {1: "fatal", 2: "serious", 3: "common", 4: "info"}
SEVERITY_ORDER = {"info": 0, "common": 1, "serious": 2, "fatal": 3}
MODULES = {0x03: "mc", 0x05: "mainboard", 0x07: "ams", 0x08: "toolhead", 0x0C: "xcam"}


def load_error_table(lang: str) -> dict:
    path = DATA_DIR / f"hms_{lang}.json.gz"
    if not path.exists():
        return {}
    with gzip.open(path, "rt", encoding="utf-8") as f:
        return json.load(f).get("device_hms", {})


ERROR_TABLE = load_error_table(LANGUAGE)
ERROR_TABLE_EN = load_error_table("en") if LANGUAGE != "en" else ERROR_TABLE


def describe(code_hex: str) -> str:
    """code_hex: 16 caracteres hex sem separador, ex '0300010000010007'."""
    entry = ERROR_TABLE.get(code_hex) or ERROR_TABLE_EN.get(code_hex)
    if not entry:
        return "codigo sem descricao na tabela (confira o link da wiki abaixo)"
    for msg, models in entry.items():
        if not models or DEVICE_TYPE in models:
            return msg
    return next(iter(entry))  # nenhuma msg especifica pro modelo -> pega a primeira


def decode(attr: int, code: int) -> dict:
    code_hex = f"{attr>>16:04X}{attr&0xFFFF:04X}{code>>16:04X}{code&0xFFFF:04X}"
    grouped = "_".join(code_hex[i:i + 4] for i in range(0, 16, 4))
    return {
        "code": f"HMS_{grouped}",
        "severity": SEVERITY_LEVELS.get(code >> 16, "unknown"),
        "module": MODULES.get((attr >> 24) & 0xFF, "unknown"),
        "description": describe(code_hex),
        "wiki": f"https://wiki.bambulab.com/en/{DEVICE_TYPE.lower()}/troubleshooting/hmscode/{grouped}",
    }


def notify(text: str) -> None:
    sent = False
    if WHATSAPP_WEBHOOK_URL and WHATSAPP_CHAT_ID:
        try:
            headers = {"x-api-key": WHATSAPP_API_KEY} if WHATSAPP_API_KEY else {}
            requests.post(
                WHATSAPP_WEBHOOK_URL,
                headers=headers,
                json={"chatId": WHATSAPP_CHAT_ID, "contentType": "string", "content": text},
                timeout=10,
            )
            sent = True
        except requests.RequestException as e:
            print(f"[!] falha ao notificar via WhatsApp: {e}")
    if NTFY_TOPIC:
        try:
            requests.post(f"https://ntfy.sh/{NTFY_TOPIC}", data=text.encode("utf-8"), timeout=10)
            sent = True
        except requests.RequestException as e:
            print(f"[!] falha ao notificar via ntfy: {e}")
    if not sent:
        print("[!] nenhum canal de notificacao configurado (WHATSAPP_* ou NTFY_TOPIC no .env)")


# codigos HMS atualmente ativos, pra nao ficar reenviando notificacao a cada
# report (a impressora publica o estado a cada poucos segundos)
active_codes: set[str] = set()


def handle_report(payload: dict) -> None:
    hms = payload.get("print", {}).get("hms", [])
    current = set()
    for entry in hms:
        attr, code = entry.get("attr", 0), entry.get("code", 0)
        if not attr or not code:
            continue
        info = decode(attr, code)
        current.add(info["code"])
        if info["code"] in active_codes:
            continue  # ja notificado, esperando ele sumir ou o print acabar
        active_codes.add(info["code"])
        print(f"[{info['severity'].upper()}] {info['code']} ({info['module']}): {info['description']}")
        if SEVERITY_ORDER.get(info["severity"], -1) >= SEVERITY_ORDER.get(NOTIFY_MIN_SEVERITY, 2):
            notify(
                f"🖨️ Alerta na impressora [{info['severity']}]\n"
                f"{info['description']}\n"
                f"Codigo: {info['code']}\n"
                f"{info['wiki']}"
            )
    resolved = active_codes - current
    for code in resolved:
        print(f"[OK] {code} resolvido/limpo")
    active_codes.intersection_update(current)


# estado do job atual, pra saber quando comecou um print novo e quais marcos
# ja foram notificados (zera quando detecta um RUNNING novo / subtask diferente)
progress_state = {"gcode_state": None, "subtask_name": None, "notified": set()}


def handle_progress(payload: dict) -> None:
    p = payload.get("print", {})
    if "gcode_state" not in p and "mc_percent" not in p:
        return  # report parcial que nao trouxe campo de progresso

    state = p.get("gcode_state", progress_state["gcode_state"])
    subtask = p.get("subtask_name", progress_state["subtask_name"])
    percent = p.get("mc_percent")
    remaining = p.get("mc_remaining_time")

    prev_state = progress_state["gcode_state"]
    prev_subtask = progress_state["subtask_name"]

    # print novo comecando (troca pra RUNNING, ou subtask mudou sem passar por IDLE)
    if state == "RUNNING" and (prev_state != "RUNNING" or subtask != prev_subtask):
        progress_state["notified"] = set()
        print(f"[PROGRESS] iniciou: {subtask}")

    if state == "RUNNING" and percent is not None:
        for m in PROGRESS_MILESTONES:
            if percent >= m and m not in progress_state["notified"]:
                progress_state["notified"].add(m)
                eta = f" — faltam ~{remaining} min" if remaining else ""
                print(f"[PROGRESS] {percent}% ({subtask}){eta}")
                notify(f"🖨️ {subtask}: passou de {m}% (atual: {percent}%){eta}")

    if state == "FINISH" and prev_state != "FINISH":
        print(f"[PROGRESS] concluido: {subtask}")
        notify(f"✅ Impressão concluída: {subtask}")

    if state == "FAILED" and prev_state != "FAILED":
        print(f"[PROGRESS] falhou: {subtask}")
        notify(f"❌ Impressão falhou: {subtask}")

    progress_state["gcode_state"] = state
    progress_state["subtask_name"] = subtask


def on_connect(client, userdata, flags, rc, properties=None):
    print(f"conectado ao broker MQTT em {PRINTER_IP} (rc={rc})")
    client.subscribe(f"device/{SERIAL}/report")


def on_message(client, userdata, msg):
    try:
        payload = json.loads(msg.payload)
    except json.JSONDecodeError:
        return
    handle_report(payload)
    handle_progress(payload)


def main():
    client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
    client.username_pw_set("bblp", ACCESS_CODE)
    # a impressora usa certificado autoassinado no MQTT local - por isso
    # desabilita a verificacao (mesma abordagem usada pelo pybambu/ha-bambulab)
    client.tls_set(cert_reqs=ssl.CERT_NONE)
    client.tls_insecure_set(True)
    client.on_connect = on_connect
    client.on_message = on_message

    print(f"bambu-hms-watch iniciado — severidade minima p/ notificar: {NOTIFY_MIN_SEVERITY}")
    while True:
        try:
            client.connect(PRINTER_IP, 8883, keepalive=30)
            client.loop_forever()
        except Exception as e:
            print(f"[!] conexao caiu ({e}) — tentando de novo em 10s")
            time.sleep(10)


if __name__ == "__main__":
    main()
