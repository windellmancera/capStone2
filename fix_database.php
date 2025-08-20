<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'db.php';

// Add missing columns to users table
$sql_commands = [
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS full_name VARCHAR(100)",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS mobile_number VARCHAR(20)",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS gender ENUM('male', 'female', 'other')",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS home_address TEXT",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS date_of_birth DATE",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_picture VARCHAR(255)",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS qr_code VARCHAR(255)",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS fitness_goal VARCHAR(100)",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS experience_level ENUM('Beginner', 'Intermediate', 'Advanced')"
];

echo "Running database fixes...\n";

foreach ($sql_commands as $sql) {
    try {
        if ($conn->query($sql)) {
            echo "Success: " . $sql . "\n";
        } else {
            echo "Error: " . $conn->error . " for query: " . $sql . "\n";
        }
    } catch (Exception $e) {
        echo "Exception: " . $e->getMessage() . " for query: " . $sql . "\n";
    }
}

echo "Database fixes completed!\n";

try {
    // Array of SQL files to execute
    $sql_files = [
        'sql/create_tables.sql',
        'sql/setup_membership.sql',
        'sql/setup_payment_system.sql',
        'sql/setup_dashboard_tables.sql',
        'sql/create_trainers_table.sql',
        'sql/add_role_to_users.sql',
        'sql/add_admin.sql',
        'sql/add_emergency_contact_fields.sql',
        'sql/add_fitness_analytics_fields.sql',
        'sql/create_member_plans_table.sql',
        'sql/create_member_notes_table.sql',
        'sql/add_features_to_membership_plans.sql',
        'sql/add_sample_membership_plans.sql',
        'sql/add_selected_plan_column.sql',
        'sql/add_plan_id_to_payments.sql',
        'sql/add_updated_at_to_payment_history.sql',
        'sql/update_attendance_table.sql',
        'sql/update_payments_table.sql',
        'sql/update_profile_fields.sql',
        'sql/fix_payment_status.sql',
        'sql/update_trainers_table.sql',  // Added the new trainer table updates
        'sql/add_category_to_equipment.sql',
        'sql/add_image_url_to_equipment.sql'
    ];

    // Function to execute SQL from file
    function executeSQLFile($conn, $filename) {
        echo "Executing $filename...<br>";
        $sql = file_get_contents($filename);
        
        if ($sql === false) {
            echo "Error reading file: $filename<br>";
            return;
        }
        
        // Split into individual queries
        $queries = explode(';', $sql);
        
        foreach ($queries as $query) {
            $query = trim($query);
            if (!empty($query)) {
                if ($conn->query($query) === TRUE) {
                    echo "Success executing query<br>";
                } else {
                    echo "Error: " . $conn->error . "<br>";
                }
            }
        }
        echo "Completed $filename<br><br>";
    }

    // Execute each SQL file
    foreach ($sql_files as $file) {
        executeSQLFile($conn, $file);
    }

    echo "Database update completed!";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

// Close the connection
$conn->close();
?> 