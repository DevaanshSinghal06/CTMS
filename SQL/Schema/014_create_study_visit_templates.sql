CREATE TABLE study_arms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    study_id INT NOT NULL,
    arm_name VARCHAR(255) NOT NULL,
    arm_order INT NOT NULL DEFAULT 1,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_study_arms_study
        FOREIGN KEY (study_id)
        REFERENCES studies(id)
        ON DELETE CASCADE
);

CREATE TABLE study_visit_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    study_id INT NOT NULL,
    arm_id INT NULL,
    visit_name VARCHAR(255) NOT NULL,
    visit_order INT NOT NULL DEFAULT 1,
    target_day INT NULL,
    window_before_days INT NULL,
    window_after_days INT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_visit_templates_study
        FOREIGN KEY (study_id)
        REFERENCES studies(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_visit_templates_arm
        FOREIGN KEY (arm_id)
        REFERENCES study_arms(id)
        ON DELETE SET NULL
);

CREATE TABLE study_procedures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    study_id INT NOT NULL,
    procedure_name VARCHAR(255) NOT NULL,
    procedure_code VARCHAR(100) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_study_procedures_study
        FOREIGN KEY (study_id)
        REFERENCES studies(id)
        ON DELETE CASCADE
);

CREATE TABLE study_visit_procedures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    visit_template_id INT NOT NULL,
    procedure_id INT NOT NULL,
    budgeted_amount DECIMAL(10,2) NULL,
    required TINYINT(1) NOT NULL DEFAULT 1,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_visit_procedures_visit
        FOREIGN KEY (visit_template_id)
        REFERENCES study_visit_templates(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_visit_procedures_procedure
        FOREIGN KEY (procedure_id)
        REFERENCES study_procedures(id)
        ON DELETE CASCADE,

    CONSTRAINT uq_visit_procedure
        UNIQUE (visit_template_id, procedure_id)
);