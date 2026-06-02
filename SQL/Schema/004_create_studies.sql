CREATE TABLE IF NOT EXISTS studies (
    id INT AUTO_INCREMENT PRIMARY KEY,

    study_name VARCHAR(255) NOT NULL,
    protocol_number VARCHAR(100),
    sponsor VARCHAR(255),
    cro_name VARCHAR(255),
    principal_investigator VARCHAR(255),

    status ENUM('setup', 'open', 'closed', 'archived') NOT NULL DEFAULT 'setup',

    start_date DATE NULL,
    end_date DATE NULL,

    notes TEXT,

    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);