CREATE TABLE activity_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    -- user_id: ID do usuário (0 se for um erro de sistema ou não autenticado)
    user_id INT UNSIGNED NOT NULL DEFAULT 0,
    
    -- event_type: Categoria da ação (para fácil filtragem)
    event_type VARCHAR(50) NOT NULL COMMENT 'Ex: LOGIN, VIEW_PAGE, ACTION_CREATE, SYSTEM_ERROR, EXECUTE_CONTROLLER',
    
    -- description: Resumo da ação para relatórios
    description VARCHAR(255) NOT NULL,
    
    -- details: Armazena dados contextuais em formato JSON (IP, URL, duração, etc.)
    details JSON COMMENT 'Dados adicionais em formato JSON (IP, URL, duração, IDs de recursos, etc.)',
    
    -- timestamp: Quando o evento ocorreu
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    
) ENGINE=InnoDB 
  DEFAULT CHARSET=utf8mb4 
  COLLATE=utf8mb4_unicode_ci;

-- Adiciona índices para otimizar consultas de auditoria
CREATE INDEX idx_activity_user_time ON activity_log (user_id, timestamp);
CREATE INDEX idx_activity_event_type ON activity_log (event_type);