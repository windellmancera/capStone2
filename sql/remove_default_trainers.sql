-- Remove default trainers
DELETE FROM trainers 
WHERE name IN ('John Smith', 'Maria Garcia', 'Mike Johnson', 'Sarah Lee')
AND email IN (
    'john.smith@almofitness.com',
    'maria.garcia@almofitness.com',
    'mike.johnson@almofitness.com',
    'sarah.lee@almofitness.com'
); 