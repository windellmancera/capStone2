<?php
require_once 'db.php';

try {
    // Create gym_feedback table
    $create_table_sql = "
    CREATE TABLE IF NOT EXISTS gym_feedback (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        message TEXT NOT NULL,
        category ENUM('facilities', 'services', 'system', 'general') DEFAULT 'general',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    ) ENGINE=InnoDB";
    
    if ($conn->query($create_table_sql)) {
        echo "Successfully created gym_feedback table.<br>";
    } else {
        throw new Exception("Error creating table: " . $conn->error);
    }

    // Add indexes
    $index_queries = [
        "CREATE INDEX idx_gym_feedback_user ON gym_feedback(user_id)",
        "CREATE INDEX idx_gym_feedback_category ON gym_feedback(category)"
    ];

    foreach ($index_queries as $query) {
        if ($conn->query($query)) {
            echo "Successfully created index.<br>";
        } else {
            echo "Note: Index might already exist - " . $conn->error . "<br>";
        }
    }

    // Add sample data
    $sample_data_sql = "
    INSERT INTO gym_feedback (user_id, message, category) 
    SELECT 
        u.id,
        ELT(FLOOR(1 + RAND() * 4),
            'The gym equipment is well-maintained and modern. Great facility!',
            'The new mobile app makes it very convenient to book sessions.',
            'Staff is always helpful and professional.',
            'Love the cleanliness and organization of the gym.'
        ) as message,
        ELT(FLOOR(1 + RAND() * 4),
            'facilities',
            'system',
            'services',
            'general'
        ) as category
    FROM users u 
    WHERE u.role = 'member'
    LIMIT 5";

    if ($conn->query($sample_data_sql)) {
        echo "Successfully added sample feedback data.<br>";
    } else {
        echo "Note: Sample data might already exist - " . $conn->error . "<br>";
    }

    echo "Setup completed successfully!";

} catch (Exception $e) {
    echo "Error during setup: " . $e->getMessage();
}

$conn->close(); 