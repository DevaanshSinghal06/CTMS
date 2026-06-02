-- Updates study status values from the original development labels
-- to the workflow labels requested for the CTMS.
--
-- Old:
-- setup, open, closed, archived
--
-- New:
-- enrolling, closed_to_enrollment, terminated, archived

ALTER TABLE studies
MODIFY status ENUM(
    'setup',
    'open',
    'closed',
    'enrolling',
    'closed_to_enrollment',
    'terminated',
    'archived'
)
NOT NULL DEFAULT 'enrolling';

UPDATE studies
SET status = 'enrolling'
WHERE status IN ('setup', 'open');

UPDATE studies
SET status = 'closed_to_enrollment'
WHERE status = 'closed';

ALTER TABLE studies
MODIFY status ENUM(
    'enrolling',
    'closed_to_enrollment',
    'terminated',
    'archived'
)
NOT NULL DEFAULT 'enrolling';