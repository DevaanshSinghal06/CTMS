CREATE TABLE subject_visits (
    id INT AUTO_INCREMENT PRIMARY KEY,

    study_subject_id INT NOT NULL,
    visit_template_id INT NULL,

    visit_name_snapshot VARCHAR(255) NOT NULL,
    target_day_snapshot INT NULL,

    occurrence_number INT NOT NULL DEFAULT 1,

    scheduled_date DATE NULL,
    actual_visit_date DATE NULL,

    status VARCHAR(30) NOT NULL DEFAULT 'open',

    expected_total_snapshot DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    submitted_total DECIMAL(10,2) NULL,

    notes TEXT NULL,

    created_by INT NULL,
    submitted_by INT NULL,
    submitted_at DATETIME NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_subject_visits_study_subject
        FOREIGN KEY (study_subject_id)
        REFERENCES study_subjects(id)
        ON DELETE RESTRICT,

    CONSTRAINT fk_subject_visits_template
        FOREIGN KEY (visit_template_id)
        REFERENCES study_visit_templates(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_subject_visits_created_by
        FOREIGN KEY (created_by)
        REFERENCES users(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_subject_visits_submitted_by
        FOREIGN KEY (submitted_by)
        REFERENCES users(id)
        ON DELETE SET NULL,

    CONSTRAINT uq_subject_visit_occurrence
        UNIQUE (
            study_subject_id,
            visit_template_id,
            occurrence_number
        )
);


CREATE TABLE subject_visit_procedures (
    id INT AUTO_INCREMENT PRIMARY KEY,

    subject_visit_id INT NOT NULL,
    visit_procedure_id INT NULL,

    procedure_name_snapshot VARCHAR(255) NOT NULL,
    budgeted_amount_snapshot DECIMAL(10,2) NULL,
    required_snapshot TINYINT(1) NOT NULL DEFAULT 1,

    status VARCHAR(30) NOT NULL DEFAULT 'pending',

    notes TEXT NULL,

    completed_by INT NULL,
    completed_at DATETIME NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_subject_visit_procedures_visit
        FOREIGN KEY (subject_visit_id)
        REFERENCES subject_visits(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_subject_visit_procedures_template_procedure
        FOREIGN KEY (visit_procedure_id)
        REFERENCES study_visit_procedures(id)
        ON DELETE SET NULL,

    CONSTRAINT fk_subject_visit_procedures_completed_by
        FOREIGN KEY (completed_by)
        REFERENCES users(id)
        ON DELETE SET NULL,

    CONSTRAINT uq_subject_visit_procedure
        UNIQUE (
            subject_visit_id,
            visit_procedure_id
        )
);