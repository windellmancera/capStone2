-- Add activity tracking columns if they don't exist
ALTER TABLE trainers
ADD COLUMN IF NOT EXISTS status ENUM('active', 'inactive', 'on_leave') DEFAULT 'active',
ADD COLUMN IF NOT EXISTS last_login DATETIME DEFAULT NULL,
ADD COLUMN IF NOT EXISTS last_session_end DATETIME DEFAULT NULL,
ADD COLUMN IF NOT EXISTS next_session_start DATETIME DEFAULT NULL;

-- Drop the view if it exists
DROP VIEW IF EXISTS trainer_activity_status;

-- Create the view
CREATE VIEW trainer_activity_status AS
SELECT 
    t.id,
    t.name,
    t.status,
    t.last_login,
    t.last_session_end,
    t.next_session_start,
    CASE 
        WHEN t.status = 'inactive' THEN 'inactive'
        WHEN t.status = 'on_leave' THEN 'on_leave'
        WHEN t.next_session_start IS NOT NULL 
            AND t.next_session_start <= DATE_ADD(NOW(), INTERVAL 1 HOUR) THEN 'upcoming_session'
        WHEN t.last_session_end IS NOT NULL 
            AND t.last_session_end >= DATE_SUB(NOW(), INTERVAL 30 MINUTE) THEN 'just_finished'
        WHEN t.last_login IS NOT NULL 
            AND t.last_login >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) THEN 'online'
        ELSE 'offline'
    END as activity_status
FROM trainers t; 