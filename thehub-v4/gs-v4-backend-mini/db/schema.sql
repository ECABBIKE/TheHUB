CREATE TABLE IF NOT EXISTS cyclists (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    uci_id VARCHAR(50) DEFAULT NULL,
    club VARCHAR(150) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO cyclists (first_name, last_name, uci_id, club) VALUES
('Anna', 'Andersson', 'SWE2025001', 'GravitySeries Test Club'),
('Björn', 'Berg', 'SWE2025002', 'Capital Enduro Test'),
('Caroline', 'Carlsson', NULL, 'Falun Bike');
