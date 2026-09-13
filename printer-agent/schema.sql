-- Roda isso UMA VEZ no banco externo (phpMyAdmin, Adminer, ou `mysql -u seu_admin -p nome_do_banco < schema.sql`)
-- com um usuario que tenha permissao de CREATE. O collect_status.py depois so precisa de INSERT/SELECT.

CREATE TABLE IF NOT EXISTS snapshots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    captured_at DATETIME NOT NULL,

    -- estado / progresso
    gcode_state VARCHAR(20),
    print_type VARCHAR(20),
    subtask_name VARCHAR(255),
    mc_percent INT,
    mc_remaining_time INT,
    layer_num INT,
    total_layer_num INT,

    -- temperaturas (C)
    nozzle_temper FLOAT,
    nozzle_target_temper FLOAT,
    bed_temper FLOAT,
    bed_target_temper FLOAT,
    chamber_temper FLOAT,

    -- ventoinhas (escala 0-15 do firmware) e velocidade
    cooling_fan_speed INT,
    heatbreak_fan_speed INT,
    big_fan1_speed INT,
    big_fan2_speed INT,
    spd_lvl INT,
    spd_mag INT,

    -- hardware / rede
    wifi_signal VARCHAR(20),
    print_error INT,
    nozzle_diameter VARCHAR(10),
    nozzle_type VARCHAR(30),

    -- estruturas variaveis (MySQL valida e deixa consultar por dentro do JSON
    -- com JSON_EXTRACT se precisar, ex: cor/tipo de cada carretel pros icones)
    hms_json JSON,
    ams_json JSON,
    vt_tray_json JSON,
    lights_json JSON,

    -- payload cru completo, pra nunca perder nenhum campo que nao virou coluna
    raw_json JSON,

    INDEX idx_captured_at (captured_at)
);

-- Usuario dedicado pro coletor, só com o que ele precisa (troque a senha e,
-- se seu MySQL exigir host especifico em vez de '%', use o IP da sua casa -
-- lembrando que IP residencial costuma ser dinamico, ver observação no chat).
-- CREATE USER 'bambu_writer'@'%' IDENTIFIED BY 'TROQUE_ESSA_SENHA';
-- GRANT INSERT, SELECT ON nome_do_banco.snapshots TO 'bambu_writer'@'%';
-- FLUSH PRIVILEGES;
