<?php
require 'db.php';

echo "Testing Member Data\n";
echo "==================\n\n";

// Check total members
$result = $conn->query("SELECT COUNT(*) as count FROM users WHERE role != 'admin'");
if ($result) {
    $total = $result->fetch_assoc()['count'];
    echo "Total members: $total\n";
} else {
    echo "Error counting members: " . $conn->error . "\n";
}

// Check if there are any members at all
$result = $conn->query("SELECT id, username, email, role FROM users WHERE role != 'admin' LIMIT 5");
if ($result && $result->num_rows > 0) {
    echo "\nSample members:\n";
    while ($row = $result->fetch_assoc()) {
        echo "- ID: {$row['id']}, Username: {$row['username']}, Email: {$row['email']}, Role: {$row['role']}\n";
    }
} else {
    echo "\nNo members found in database.\n";
}
?> 