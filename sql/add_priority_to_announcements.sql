-- Add priority field to announcements table
ALTER TABLE announcements
ADD COLUMN IF NOT EXISTS priority ENUM('Low', 'Medium', 'High', 'Urgent') DEFAULT 'Low';

-- Add updated_at field if it doesn't exist
ALTER TABLE announcements
ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP; 