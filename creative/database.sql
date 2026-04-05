-- ============================================================
--  KONEX CONECTA — Banco de Dados Completo
--  Versão: 2.0  |  Charset: utf8mb4
--  (Editado para uso em hospedagens com cPanel/similares)
-- ============================================================

-- ------------------------------------------------------------
-- Tabela de usuários / compradores
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `email`        VARCHAR(180) NOT NULL UNIQUE,
  `senha_hash`   VARCHAR(255) NOT NULL,          -- bcrypt
  `nome`         VARCHAR(120) DEFAULT NULL,
  `cpf`          VARCHAR(20)  DEFAULT NULL,       -- CPF para marca d'agua no PDF
  `creditos`     INT UNSIGNED NOT NULL DEFAULT 0,
  `plano`        ENUM('avulso','basico','profissional','agencia','admin') NOT NULL DEFAULT 'avulso',
  `ativo`        TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_email` (`email`),
  INDEX `idx_ativo` (`ativo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Tabela de transações de crédito
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `transacoes` (
  `id`           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `usuario_id`   INT UNSIGNED NOT NULL,
  `tipo`         ENUM('compra','consumo','bonus','estorno','manual') NOT NULL,
  `quantidade`   INT NOT NULL,                   -- positivo = entrada, negativo = saída
  `descricao`    VARCHAR(255) DEFAULT NULL,
  `referencia`   VARCHAR(120) DEFAULT NULL,      -- ID pagamento MP etc.
  `ip`           VARCHAR(45) DEFAULT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE,
  INDEX `idx_usuario` (`usuario_id`),
  INDEX `idx_tipo` (`tipo`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Tabela de pedidos / pagamentos
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `pedidos` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `usuario_id`   INT UNSIGNED DEFAULT NULL,
  `email`        VARCHAR(180) NOT NULL,
  `plano`        VARCHAR(60) NOT NULL,
  `creditos`     INT UNSIGNED NOT NULL,
  `valor`        DECIMAL(10,2) NOT NULL,
  `status`       ENUM('pendente','aprovado','cancelado','reembolsado') NOT NULL DEFAULT 'pendente',
  `gateway`      VARCHAR(60) DEFAULT 'mercadopago',
  `gateway_id`   VARCHAR(120) DEFAULT NULL,
  `gateway_json` JSON DEFAULT NULL,
  `ip`           VARCHAR(45) DEFAULT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_email`  (`email`),
  INDEX `idx_status` (`status`),
  INDEX `idx_gateway_id` (`gateway_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Tabela de sessões admin
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_sessions` (
  `token`        VARCHAR(64) PRIMARY KEY,
  `admin_user`   VARCHAR(60) NOT NULL,
  `ip`           VARCHAR(45) DEFAULT NULL,
  `expires_at`   DATETIME NOT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Tabela de logs do sistema
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `logs` (
  `id`           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `nivel`        ENUM('info','warning','error') NOT NULL DEFAULT 'info',
  `acao`         VARCHAR(80) NOT NULL,
  `detalhes`     TEXT DEFAULT NULL,
  `usuario_id`   INT UNSIGNED DEFAULT NULL,
  `ip`           VARCHAR(45) DEFAULT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_nivel`   (`nivel`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Tabela de configurações do sistema
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `configuracoes` (
  `chave`   VARCHAR(80) PRIMARY KEY,
  `valor`   TEXT NOT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Valores padrão de configuração
INSERT INTO `configuracoes` (`chave`, `valor`) VALUES
  ('mp_link_basico',       ''),
  ('mp_link_profissional', ''),
  ('mp_link_agencia',      ''),
  ('mp_access_token',      ''),
  ('site_nome',            'KONEX CONECTA'),
  ('whatsapp_suporte',     ''),
  ('creditos_basico',      '1'),
  ('creditos_profissional','3'),
  ('creditos_agencia',     '10'),
  ('valor_basico',         '9.90'),
  ('valor_profissional',   '19.90'),
  ('valor_agencia',        '39.90')
  ,('llm_enabled',         '0')
  ,('llm_provider',        'openai')
  ,('llm_model',           'gpt-4o-mini')
  ,('llm_endpoint',        'https://api.openai.com/v1/chat/completions')
  ,('llm_api_key',         '')
ON DUPLICATE KEY UPDATE `valor` = VALUES(`valor`);

-- Configurações de controle de créditos e gateway
INSERT INTO `configuracoes` (`chave`, `valor`) VALUES
  ('credito_gratis_ativo', '1'),
  ('credito_gratis_qtd',   '1'),
  ('gateway_ativo',        'mercadopago')
ON DUPLICATE KEY UPDATE `valor` = VALUES(`valor`);

-- ------------------------------------------------------------
-- Usuário admin padrão
-- IMPORTANTE: Antes de importar, gere um hash bcrypt real:
--   php -r "echo password_hash('SuaSenhaSegura', PASSWORD_BCRYPT);"
-- E substitua o valor de senha_hash abaixo pelo hash gerado.
-- Se o hash não for substituído, o admin não conseguirá fazer login.
-- ------------------------------------------------------------
-- ATENÇÃO: A senha padrão do admin é 'KonexAdmin2024'. Altere-a imediatamente após o primeiro login!
-- Para gerar um novo hash: php -r "echo password_hash('SuaSenhaSegura', PASSWORD_BCRYPT);"
INSERT IGNORE INTO `usuarios` (`email`, `senha_hash`, `nome`, `creditos`, `plano`)
VALUES ('admin@konex.com', '$2y$10$cLsMoNYTLVSsxoghcX5wseNHuJCCj36u6QHIdhLdWKDc63CpEiLUa', 'Admin Konex', 999999, 'admin');
-- Configurações adicionais v3.0
INSERT INTO `configuracoes` (`chave`, `valor`) VALUES
  ('nome_plano_basico',        'Básico'),
  ('nome_plano_profissional',  'Profissional'),
  ('nome_plano_agencia',       'Agência'),
  ('desc_plano_basico',        '1 Currículo Profissional em PDF'),
  ('desc_plano_profissional',  '3 Currículos + IA Ilimitada por 30 dias'),
  ('desc_plano_agencia',       '10 Currículos + Todos os Recursos Premium'),
  ('valor_enterprise',         '149.90'),
  ('creditos_enterprise',      '100'),
  ('nome_plano_enterprise',    'Enterprise'),
  ('desc_plano_enterprise',    '100 Currículos + Todos os Recursos + Suporte Prioritário'),
  ('site_nome',                'KONEX CREATIVE'),
  ('site_url',                 '')
ON DUPLICATE KEY UPDATE `valor` = VALUES(`valor`);

-- ------------------------------------------------------------
-- Tabela de leads (capturados antes do contato WhatsApp)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `leads` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `nome`       VARCHAR(120) NOT NULL,
  `email`      VARCHAR(180) DEFAULT NULL,
  `telefone`   VARCHAR(30)  DEFAULT NULL,
  `origem`     VARCHAR(60)  NOT NULL DEFAULT 'whatsapp_float',
  `ip`         VARCHAR(45)  DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_email`   (`email`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabela de sessões IA (se não existir)
CREATE TABLE IF NOT EXISTS `ia_limites` (
  `usuario_id` INT PRIMARY KEY,
  `usos_hoje`  INT DEFAULT 0,
  `ultima_data` DATE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Limites de uso diário da IA (0 = sem limite)
INSERT INTO `configuracoes` (`chave`, `valor`) VALUES
  ('ia_limite_usuario_dia', '30'),
  ('ia_limite_global_dia',  '200')
ON DUPLICATE KEY UPDATE `valor` = VALUES(`valor`);

-- ------------------------------------------------------------
-- Configurações dos Agentes Inteligentes
-- ------------------------------------------------------------
INSERT INTO `configuracoes` (`chave`, `valor`) VALUES
  ('agents_enabled',         '1'),
  ('agent_ats_enabled',      '1'),
  ('agent_cover_enabled',    '1'),
  ('agent_review_enabled',   '1'),
  ('agent_linkedin_enabled', '1'),
  ('agent_interview_enabled','1')
ON DUPLICATE KEY UPDATE `valor` = VALUES(`valor`);
