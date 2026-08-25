-- 016_add_subject_visit_scheduling.sql
-- Adds scheduling-anchor, protocol-window snapshot,
-- and actual visit timing support.

ALTER TABLE study_visit_templates
    ADD COLUMN is_schedule_anchor TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE study_subjects
    ADD COLUMN schedule_anchor_date DATE NULL;

ALTER TABLE subject_visits
    ADD COLUMN target_date_snapshot DATE NULL,
    ADD COLUMN window_start_date_snapshot DATE NULL,
    ADD COLUMN window_end_date_snapshot DATE NULL,
    ADD COLUMN scheduled_time TIME NULL,
    ADD COLUMN actual_start_time TIME NULL,
    ADD COLUMN actual_end_time TIME NULL,
    ADD COLUMN visit_timezone VARCHAR(64) NOT NULL DEFAULT 'America/Chicago';