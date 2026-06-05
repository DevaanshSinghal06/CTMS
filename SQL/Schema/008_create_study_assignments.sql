CREATE TABLE IF NOT EXISTS study_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,

    study_id INT NOT NULL,
    user_id INT NOT NULL,

    assignment_role ENUM('lead', 'backup') NOT NULL,

    assigned_by INT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY unique_study_assignment_role (study_id, assignment_role),

    FOREIGN KEY (study_id) REFERENCES studies(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
);