<?php
require 'db.php';

// Get filter parameters (same as reports.php)
$date_from = $_GET['date_from'] ?? '2024-01-01'; // Default to start of year to show all data
$date_to = $_GET['date_to'] ?? date('Y-m-d'); // Today

echo "<h1>Direct Reports Test</h1>";
echo "<p>Date Range: $date_from to $date_to</p>";

// Test daily revenue data
$daily_revenue_sql = "
    SELECT 
        DATE(ph.payment_date) as date,
        SUM(ph.amount) as daily_revenue,
        COUNT(*) as payment_count
    FROM payment_history ph
    JOIN users u ON ph.user_id = u.id
    WHERE ph.payment_status = 'Approved' AND ph.payment_date BETWEEN ? AND ?
    GROUP BY DATE(ph.payment_date)
    ORDER BY date ASC
";

$daily_revenue_stmt = $conn->prepare($daily_revenue_sql);
$date_to_with_time = $date_to . ' 23:59:59';
$daily_revenue_stmt->bind_param("ss", $date_from, $date_to_with_time);
$daily_revenue_stmt->execute();
$daily_revenue = $daily_revenue_stmt->get_result();

$dates = [];
$revenues = [];
if ($daily_revenue && $daily_revenue->num_rows > 0) {
    echo "<h3>Daily Revenue Data Found:</h3>";
    echo "<ul>";
    while ($row = $daily_revenue->fetch_assoc()) {
        $dates[] = $row['date'];
        $revenues[] = (float)$row['daily_revenue'];
        echo "<li>" . $row['date'] . " - ₱" . number_format($row['daily_revenue'], 2) . " (" . $row['payment_count'] . " payments)</li>";
    }
    echo "</ul>";
} else {
    echo "<p>No daily revenue data found!</p>";
}

// Test payment method data
$revenue_by_method_sql = "
    SELECT 
        ph.payment_method,
        COUNT(*) as payment_count,
        SUM(ph.amount) as total_amount
    FROM payment_history ph
    JOIN users u ON ph.user_id = u.id
    WHERE ph.payment_status = 'Approved' AND ph.payment_date BETWEEN ? AND ?
    GROUP BY ph.payment_method
    ORDER BY total_amount DESC
";

$revenue_by_method_stmt = $conn->prepare($revenue_by_method_sql);
$revenue_by_method_stmt->bind_param("ss", $date_from, $date_to_with_time);
$revenue_by_method_stmt->execute();
$revenue_by_method = $revenue_by_method_stmt->get_result();

$methods = [];
$amounts = [];
if ($revenue_by_method && $revenue_by_method->num_rows > 0) {
    echo "<h3>Payment Method Data Found:</h3>";
    echo "<ul>";
    while ($row = $revenue_by_method->fetch_assoc()) {
        $methods[] = $row['payment_method'];
        $amounts[] = (float)$row['total_amount'];
        echo "<li>" . $row['payment_method'] . " - ₱" . number_format($row['total_amount'], 2) . " (" . $row['payment_count'] . " payments)</li>";
    }
    echo "</ul>";
} else {
    echo "<p>No payment method data found!</p>";
}

// Test membership plan data
$revenue_by_plan_sql = "
    SELECT 
        COALESCE(mp.name, 'No Plan') as plan_name,
        COUNT(*) as payment_count,
        SUM(ph.amount) as total_amount
    FROM payment_history ph
    JOIN users u ON ph.user_id = u.id
    LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
    WHERE ph.payment_status = 'Approved' AND ph.payment_date BETWEEN ? AND ?
    GROUP BY mp.id, mp.name
    ORDER BY total_amount DESC
";

$revenue_by_plan_stmt = $conn->prepare($revenue_by_plan_sql);
$revenue_by_plan_stmt->bind_param("ss", $date_from, $date_to_with_time);
$revenue_by_plan_stmt->execute();
$revenue_by_plan = $revenue_by_plan_stmt->get_result();

$plans = [];
$planAmounts = [];
if ($revenue_by_plan && $revenue_by_plan->num_rows > 0) {
    echo "<h3>Membership Plan Data Found:</h3>";
    echo "<ul>";
    while ($row = $revenue_by_plan->fetch_assoc()) {
        $plans[] = $row['plan_name'];
        $planAmounts[] = (float)$row['total_amount'];
        echo "<li>" . $row['plan_name'] . " - ₱" . number_format($row['total_amount'], 2) . " (" . $row['payment_count'] . " payments)</li>";
    }
    echo "</ul>";
} else {
    echo "<p>No membership plan data found!</p>";
}

// Now test the charts
echo "<h2>Chart Test</h2>";
echo "<div style='width: 400px; height: 300px; border: 1px solid #ccc; margin: 20px 0;'>";
echo "<canvas id='testChart'></canvas>";
echo "</div>";

echo "<script src='https://cdn.jsdelivr.net/npm/chart.js'></script>";
echo "<script>";
echo "document.addEventListener('DOMContentLoaded', function() {";
echo "    console.log('Testing chart creation...');";
echo "    const ctx = document.getElementById('testChart').getContext('2d');";
echo "    console.log('Canvas context:', ctx);";
echo "    const chart = new Chart(ctx, {";
echo "        type: 'line',";
echo "        data: {";
echo "            labels: " . json_encode($dates) . ",";
echo "            datasets: [{";
echo "                label: 'Daily Revenue',";
echo "                data: " . json_encode($revenues) . ",";
echo "                borderColor: 'rgb(75, 192, 192)',";
echo "                tension: 0.1";
echo "            }]";
echo "        },";
echo "        options: {";
echo "            responsive: true,";
echo "            maintainAspectRatio: false";
echo "        }";
echo "    });";
echo "    console.log('Chart created:', chart);";
echo "});";
echo "</script>";
?> 