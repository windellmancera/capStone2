<?php
// Start session and set admin user
session_start();
$_SESSION['user_id'] = 1; // Assuming admin user ID is 1
$_SESSION['role'] = 'admin';

// Include the reports page
ob_start();
include 'admin/reports.php';
$content = ob_get_clean();

// Extract just the JavaScript part to test
if (preg_match('/<script>(.*?)<\/script>/s', $content, $matches)) {
    echo "<h2>JavaScript from Reports Page:</h2>";
    echo "<pre>" . htmlspecialchars($matches[1]) . "</pre>";
} else {
    echo "<p>No JavaScript found in reports page</p>";
}

// Check if Chart.js is included
if (strpos($content, 'chart.js') !== false) {
    echo "<p>✅ Chart.js is included</p>";
} else {
    echo "<p>❌ Chart.js is NOT included</p>";
}

// Check if chart containers exist
if (strpos($content, 'revenueChart') !== false) {
    echo "<p>✅ Revenue chart container exists</p>";
} else {
    echo "<p>❌ Revenue chart container NOT found</p>";
}

if (strpos($content, 'paymentMethodChart') !== false) {
    echo "<p>✅ Payment method chart container exists</p>";
} else {
    echo "<p>❌ Payment method chart container NOT found</p>";
}

if (strpos($content, 'membershipPlanChart') !== false) {
    echo "<p>✅ Membership plan chart container exists</p>";
} else {
    echo "<p>❌ Membership plan chart container NOT found</p>";
}
?> 