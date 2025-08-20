<?php
require_once('../db.php');

// Query to get trainer statuses
$status_sql = "SELECT id, status FROM trainers WHERE deleted_at IS NULL";
$result = $conn->query($status_sql);

$trainers = array();
if ($result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        $trainers[] = array(
            'id' => $row['id'],
            'status' => $row['status'] ?? 'inactive'
        );
    }
}

header('Content-Type: application/json');
echo json_encode($trainers);

$conn->close();
?> 