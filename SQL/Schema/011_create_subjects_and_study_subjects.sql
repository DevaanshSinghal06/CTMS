CREATE TABLE IF NOT EXISTS subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,

    initials VARCHAR(20) NOT NULL,
    date_of_birth DATE NOT NULL,
    phone_number VARCHAR(30),
    notes TEXT,

    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS study_subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,

    study_id INT NOT NULL,
    subject_id INT NOT NULL,

    referral_source VARCHAR(255),

    screening_status ENUM(
        'screening',
        'randomization',
        'screen_failed',
        'enrolled',
        'completed',
        'withdrawn'
    ) NOT NULL DEFAULT 'screening',

    notes TEXT,

    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_study_subject (study_id, subject_id),

    FOREIGN KEY (study_id) REFERENCES studies(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);