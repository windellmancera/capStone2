-- Drop and recreate announcements table
DROP TABLE IF EXISTS announcements;

CREATE TABLE announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Add updated_at column to announcements table if it doesn't exist
ALTER TABLE announcements
ADD COLUMN IF NOT EXISTS updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Update existing rows to have updated_at same as created_at
UPDATE announcements SET updated_at = created_at WHERE updated_at IS NULL; 