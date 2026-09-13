# bambu-a1-monitor

Monitoramento pra Bambu Lab A1: detecta falhas de impressão (códigos HMS),
avisa progresso por WhatsApp/ntfy, guarda histórico completo num banco
externo, e mostra tudo num painel web com gráficos.

Não depende da nuvem da Bambu nem de câmera/IA — lê direto da MQTT local da
impressora.

## Como funciona

```
┌─────────────────────┐         MQTT local        ┌──────────────┐
│  printer-agent/      │◄──────────────────────────│  Bambu Lab   │
│  (roda 24h em algo   │                            │  A1          │
│  sempre ligado na    │                            └──────────────┘
│  mesma rede, ex:     │
│  Raspberry/Orange Pi)│
│                       │
│  hms_watch.py ────────────► WhatsApp / ntfy.sh (alertas + progresso)
│  collect_status.py ───────► MySQL externo (histórico, 1x/min via cron)
└───────────────────────┘              │
                                        │
                              ┌─────────▼─────────┐
                              │  dashboard/         │
                              │  (PHP, qualquer      │
                              │  hospedagem com       │
                              │  MySQL)               │
                              └───────────────────────┘
```

Duas peças, dois lugares diferentes:

- **`printer-agent/`** — dois scripts Python que rodam numa máquina sempre
  ligada e na mesma rede local da impressora (um Raspberry Pi, Orange Pi, mini
  PC — qualquer coisa serve, o consumo é mínimo). Falam MQTT direto com a
  impressora.
- **`dashboard/`** — um site PHP simples, pode ficar em qualquer hospedagem
  compartilhada com MySQL. Só lê do banco, não precisa estar na mesma rede da
  impressora.

## printer-agent/

### `hms_watch.py`

Roda continuamente (como serviço systemd). Fica ouvindo o tópico
`device/{serial}/report` da impressora:

- Decodifica os códigos **HMS** (Health Management System — os alertas de
  falha da impressora: entupimento, filamento preso, erro de AMS, etc.) e
  traduz pra texto legível usando uma tabela vendorizada do projeto
  [ha-bambulab](https://github.com/greghesp/ha-bambulab) (MIT — ver
  `hms_data/SOURCE.txt`).
- Acompanha o progresso da impressão (`gcode_state`, `mc_percent`) e notifica
  em marcos configuráveis, além de início/fim/falha.
- Notifica por WhatsApp (via um servidor
  [wwebjs-api](https://github.com/avoylenko/wwebjs-api) que você já tenha
  rodando) e/ou [ntfy.sh](https://ntfy.sh).

Só lê — nunca pausa nem cancela a impressão.

### `collect_status.py`

Roda uma vez por execução (pensado pra crontab, de 1 em 1 minuto). Conecta,
pede um dump completo (`pushall`), funde os reports que chegam por alguns
segundos (a Bambu manda em deltas, nem todo report traz todos os campos) e
grava um snapshot no MySQL: temperaturas, progresso, ventoinhas, filamento
ativo (cor/tipo via AMS), sinal wifi, e o payload cru completo.

### Setup

```bash
cd printer-agent
python3 -m venv venv
source venv/bin/activate
pip install -r requirements.txt
cp .env.example .env
```

Edite o `.env` — a tabela abaixo resume os campos principais (o arquivo tem
comentários com o resto):

| Variável | O que é |
|---|---|
| `PRINTER_IP`, `ACCESS_CODE`, `SERIAL` | Tela da impressora → Configurações → WLAN (IP e Access Code) e Device → Device Info (Serial) |
| `NOTIFY_MIN_SEVERITY` | Piso de severidade pra notificar HMS: `info` < `common` < `serious` < `fatal` |
| `PROGRESS_MILESTONES` | Percentuais pra avisar, ex `50,90` |
| `WHATSAPP_*` ou `NTFY_TOPIC` | Canal de notificação (pode preencher os dois) |
| `DB_*` | Credenciais do MySQL externo, usadas só pelo `collect_status.py` |

Teste os dois antes de virar serviço/cron:

```bash
python hms_watch.py        # deve conectar e ficar esperando (Ctrl+C pra sair)
python collect_status.py   # deve terminar com "snapshot salvo em ..."
```

**Deixando `hms_watch.py` sempre rodando** (systemd):

```bash
# ajuste WorkingDirectory/ExecStart no .service pro seu caminho real antes de copiar
sudo cp bambu-hms-watch.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now bambu-hms-watch
journalctl -u bambu-hms-watch -f
```

Se o Python fica em buffer e o log não aparece em tempo real, adicione `-u`
no `ExecStart` (`.../venv/bin/python -u .../hms_watch.py`).

**Agendando `collect_status.py`** (crontab):

```bash
crontab -e
```

```
* * * * * cd /caminho/pra/printer-agent && venv/bin/python collect_status.py >> collect.log 2>&1
```

Os dois processos usam a mesma impressora ao mesmo tempo sem conflito — ela
aceita várias conexões MQTT simultâneas.

## dashboard/

Painel web: anel de progresso, temperaturas, filamento ativo (com a cor real
do AMS), e um gráfico de temperatura ao longo do tempo. Atualiza sozinho a
cada 15s via `api.php`.

### Setup

1. Rode `schema.sql` **uma vez**, com um usuário MySQL que tenha permissão de
   `CREATE` (phpMyAdmin, Adminer, ou `mysql -u admin -p banco < schema.sql`).
   O arquivo já deixa comentado o `CREATE USER`/`GRANT` de um usuário
   restrito (só `INSERT`/`SELECT`) pro dia a dia — recomendado, já que essas
   credenciais vão ficar num `.env` numa máquina fora da hospedagem.
2. `cp config.php.example config.php` e preenche com as mesmas credenciais
   do `.env` do `printer-agent` (host, banco, usuário, senha).
3. Sobe a pasta `dashboard/` pra sua hospedagem.
4. Acessa pelo navegador.

O `schema.sql` fica em `printer-agent/schema.sql` (mesmo arquivo serve pra
criar a tabela que o `collect_status.py` alimenta e que o `dashboard/` lê).

### Se o MySQL não suportar TLS na conexão remota

Comum em hospedagem compartilhada (cPanel etc). Se `collect_status.py` falhar
com algo como `SSL is required but the server doesn't support it`, ponha
`DB_SSL=false` no `.env` do `printer-agent`. O `dashboard/` (PDO) não tem
esse problema por padrão.

### Se o host exigir IP fixo pra acesso remoto ao MySQL

Muita hospedagem só libera conexão MySQL de fora depois de você adicionar o
IP de origem numa lista (em cPanel, "Remote MySQL"). Se o IP de onde roda o
`printer-agent` for dinâmico (comum em conexão residencial), considere um
DDNS pra ter um nome fixo, ou libere de forma mais aberta compensando com o
usuário de banco restrito (só `INSERT`/`SELECT`) e senha forte.

## Créditos

- Decodificação dos códigos HMS e as tabelas de tradução em
  `printer-agent/hms_data/` vêm do projeto
  [ha-bambulab](https://github.com/greghesp/ha-bambulab) (MIT).
- [bambu-ai](https://github.com/abe238/bambu-ai) — ferramenta complementar,
  faz detecção visual de falha (spaghetti) via câmera + IA; esse repositório
  cobre a parte sem câmera (códigos HMS + progresso + histórico).

## Licença

MIT — ver [LICENSE](LICENSE).
