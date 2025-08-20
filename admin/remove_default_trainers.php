<?php
// Only check for admin session if not running from CLI
if (php_sapi_name() !== 'cli') {
    session_start();
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
        die("Unauthorized access");
    }
}

// Fix path for CLI execution
$db_path = php_sapi_name() === 'cli' ? __DIR__ . '/../db.php' : '../db.php';
require_once $db_path;

// Function to check if table exists
function tableExists($conn, $tableName) {
    $result = $conn->query("SHOW TABLES LIKE '$tableName'");
    return $result->num_rows > 0;
}

// First, delete any related records in trainer_specialties and trainer_schedules
$trainer_names = ['John Smith', 'Maria Garcia', 'Mike Johnson', 'Sarah Lee'];
$trainer_emails = [
    'john.smith@almofitness.com',
    'maria.garcia@almofitness.com',
    'mike.johnson@almofitness.com',
    'sarah.lee@almofitness.com'
];

// Get trainer IDs
$placeholders = str_repeat('?,', count($trainer_names) - 1) . '?';
$sql = "SELECT id FROM trainers WHERE name IN ($placeholders)";
$stmt = $conn->prepare($sql);
$stmt->bind_param(str_repeat('s', count($trainer_names)), ...$trainer_names);
$stmt->execute();
$result = $stmt->get_result();

$trainer_ids = [];
while ($row = $result->fetch_assoc()) {
    $trainer_ids[] = $row['id'];
}

if (!empty($trainer_ids)) {
    $id_placeholders = str_repeat('?,', count($trainer_ids) - 1) . '?';
    
    // Array of tables to check and clean up, in order of dependency
    $tables = [
        'feedback',
        'trainer_specialties',
        'trainer_schedules',
        'training_sessions',
        'trainer_availability',
        'trainer_ratings',
        'trainer_certifications',
        'member_trainer_assignments',
        'member_trainer_sessions',
        'trainer_feedback',
        'trainer_schedule',
        'trainer_attendance',
        'trainer_payments',
        'trainer_notes',
        'classes'
    ];

    // First, disable foreign key checks
    $conn->query('SET FOREIGN_KEY_CHECKS=0');

    foreach ($tables as $table) {
        if (tableExists($conn, $table)) {
            $sql = "DELETE FROM $table WHERE trainer_id IN ($id_placeholders)";
            try {
                $stmt = $conn->prepare($sql);
                $stmt->bind_param(str_repeat('i', count($trainer_ids)), ...$trainer_ids);
                $stmt->execute();
                if (php_sapi_name() === 'cli') {
                    echo "Cleaned up table $table\n";
                }
            } catch (Exception $e) {
                if (php_sapi_name() === 'cli') {
                    echo "Warning: Could not clean up table $table: " . $e->getMessage() . "\n";
                }
            }
        }
    }

    // Finally, delete the trainers
    $name_placeholders = str_repeat('?,', count($trainer_names) - 1) . '?';
    $email_placeholders = str_repeat('?,', count($trainer_emails) - 1) . '?';
    $sql = "DELETE FROM trainers WHERE name IN ($name_placeholders) AND email IN ($email_placeholders)";
    $stmt = $conn->prepare($sql);
    $types = str_repeat('s', count($trainer_names) + count($trainer_emails));
    $stmt->bind_param($types, ...array_merge($trainer_names, $trainer_emails));
    $stmt->execute();

    // Re-enable foreign key checks
    $conn->query('SET FOREIGN_KEY_CHECKS=1');

    $affected_rows = $stmt->affected_rows;

    if (php_sapi_name() === 'cli') {
        echo "Successfully removed $affected_rows default trainer(s).\n";
    } else {
        // Redirect back to manage_trainers.php with a message
        $message = urlencode("Successfully removed $affected_rows default trainer(s).");
        header("Location: manage_trainers.php?message=" . $message);
    }
} else {
    if (php_sapi_name() === 'cli') {
        echo "No default trainers found to remove.\n";
    } else {
        $message = urlencode("No default trainers found to remove.");
        header("Location: manage_trainers.php?message=" . $message);
    }
}
?> 