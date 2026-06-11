CREATE TABLE IF NOT EXISTS study_startup_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,

    study_id INT NOT NULL,

    section_name VARCHAR(150) NOT NULL,
    task_name VARCHAR(255) NOT NULL,

    status ENUM(
        'not_started',
        'in_progress',
        'complete',
        'blocked',
        'not_applicable'
    ) NOT NULL DEFAULT 'not_started',

    notes TEXT NULL,

    completed_by INT NULL,
    completed_at DATETIME NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_study_startup_task (study_id, section_name, task_name),

    FOREIGN KEY (study_id) REFERENCES studies(id) ON DELETE CASCADE,
    FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL
);