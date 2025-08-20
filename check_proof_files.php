<?php
require 'db.php';

echo "Checking payment proof files in database:\n\n";

$result = $conn->query("SELECT id, user_id, proof_of_payment FROM payment_history WHERE proof_of_payment IS NOT NULL AND proof_of_payment != ''");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo "Payment ID: " . $row['id'] . ", User ID: " . $row['user_id'] . ", Proof: " . $row['proof_of_payment'] . "\n";
        
        // Check if file exists
        $file_path = "uploads/payment_proofs/" . $row['proof_of_payment'];
        if (file_exists($file_path)) {
            echo "  ✓ File exists: " . $file_path . "\n";
        } else {
            echo "  ✗ File missing: " . $file_path . "\n";
        }
        echo "\n";
    }
} else {
    echo "Error querying database: " . $conn->error . "\n";
}

$conn->close();
?> 