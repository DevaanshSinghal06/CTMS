ALTER TABLE studies
ADD COLUMN fpfv_date DATE NULL AFTER end_date,
ADD COLUMN lpfv_date DATE NULL AFTER fpfv_date,
ADD COLUMN lplv_date DATE NULL AFTER lpfv_date,
ADD COLUMN enrollment_closing_date DATE NULL AFTER lplv_date,
ADD COLUMN study_termination_date DATE NULL AFTER enrollment_closing_date,
ADD COLUMN competitive_enrollment TINYINT(1) NULL AFTER study_termination_date,
ADD COLUMN budgeted_enrollment_number INT NULL AFTER competitive_enrollment,
ADD COLUMN site_enrollment_target INT NULL AFTER budgeted_enrollment_number;