-- Add updated_at column to payment_history table
ALTER TABLE payment_history 
ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Update existing records to have updated_at same as created_at
UPDATE payment_history SET updated_at = created_at WHERE updated_at IS NULL; 