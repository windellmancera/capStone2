<?php
require 'db.php';

// Simple test to check if data exists
$sql = "SELECT COUNT(*) as total FROM payment_history WHERE payment_status = 'Approved'";
$result = $conn->query($sql);
$total = $result->fetch_assoc()['total'];

echo "<h1>Reports Test</h1>";
echo "<p>Total approved payments: $total</p>";

if ($total > 0) {
    echo "<p>✅ Data exists in database</p>";
    
    // Check if we can access the reports page
    echo "<p><a href='admin/reports.php' target='_blank'>Open Reports Page</a></p>";
    
    // Show some sample data
    $sample_sql = "SELECT ph.amount, ph.payment_date, ph.payment_method, u.username 
                   FROM payment_history ph 
                   JOIN users u ON ph.user_id = u.id 
                   WHERE ph.payment_status = 'Approved' 
                   ORDER BY ph.payment_date DESC 
                   LIMIT 5";
    $sample_result = $conn->query($sample_sql);
    
    echo "<h3>Sample Payment Data:</h3>";
    echo "<ul>";
    while ($row = $sample_result->fetch_assoc()) {
        echo "<li>" . $row['username'] . " - ₱" . $row['amount'] . " (" . $row['payment_method'] . ") - " . $row['payment_date'] . "</li>";
    }
    echo "</ul>";
} else {
    echo "<p>❌ No approved payments found</p>";
}
?> 