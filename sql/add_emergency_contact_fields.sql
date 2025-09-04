-- Add emergency contact fields to users table
ALTER TABLE users
ADD COLUMN emergency_contact_name VARCHAR(255) DEFAULT NULL,
ADD COLUMN emergency_contact_number VARCHAR(20) DEFAULT NULL,
ADD COLUMN emergency_contact_relationship VARCHAR(50) DEFAULT NULL; 