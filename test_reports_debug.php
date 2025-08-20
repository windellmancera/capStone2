<?php
require 'db.php';

// Get filter parameters (same as reports.php)
$date_from = $_GET['date_from'] ?? '2024-01-01'; // Default to start of year to show all data
$date_to = $_GET['date_to'] ?? date('Y-m-d'); // Today
$member_filter = $_GET['member'] ?? '';
$status_filter = $_GET['status'] ?? '';
$payment_method_filter = $_GET['payment_method'] ?? '';

// Build dynamic WHERE clause for filtering
$where_conditions = ["ph.payment_date BETWEEN ? AND ?"];
$params = [$date_from, $date_to . ' 23:59:59'];
$param_types = "ss";

if (!empty($member_filter)) {
    $where_conditions[] = "(u.username LIKE ? OR u.email LIKE ?)";
    $params[] = "%$member_filter%";
    $params[] = "%$member_filter%";
    $param_types .= "ss";
}

if (!empty($status_filter)) {
    $where_conditions[] = "ph.payment_status = ?";
    $params[] = $status_filter;
    $param_types .= "s";
}

if (!empty($payment_method_filter)) {
    $where_conditions[] = "ph.payment_method = ?";
    $params[] = $payment_method_filter;
    $param_types .= "s";
}

$where_clause = implode(" AND ", $where_conditions);

echo "<h2>Debug Reports Data</h2>";
echo "<p><strong>Date Range:</strong> $date_from to $date_to</p>";
echo "<p><strong>Where Clause:</strong> $where_clause</p>";
echo "<p><strong>Parameters:</strong> " . implode(', ', $params) . "</p>";

// Test 1: Check total payments
$total_payments_sql = "SELECT COUNT(*) as total FROM payment_history ph JOIN users u ON ph.user_id = u.id WHERE $where_clause";
$total_stmt = $conn->prepare($total_payments_sql);
if (count($params) > 0) {
    $refs = array();
    $refs[] = $param_types;
    for($i = 0; $i < count($params); $i++) {
        $refs[] = &$params[$i];
    }
    call_user_func_array(array($total_stmt, 'bind_param'), $refs);
}
$total_stmt->execute();
$total_result = $total_stmt->get_result()->fetch_assoc();
echo "<h3>1. Total Payments in Date Range:</h3>";
echo "<p>Total: " . $total_result['total'] . "</p>";

// Test 2: Check daily revenue data
echo "<h3>2. Daily Revenue Data:</h3>";
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
    echo "<p>Found " . $daily_revenue->num_rows . " days with revenue data:</p>";
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

// Test 3: Check payment method data
echo "<h3>3. Payment Method Data:</h3>";
$revenue_by_method_sql = "
    SELECT 
        ph.payment_method,
        COUNT(*) as payment_count,
        SUM(ph.amount) as total_amount
    FROM payment_history ph
    JOIN users u ON ph.user_id = u.id
    WHERE $where_clause
    GROUP BY ph.payment_method
    ORDER BY total_amount DESC
";

$revenue_by_method_stmt = $conn->prepare($revenue_by_method_sql);
if (count($params) > 0) {
    $refs = array();
    $refs[] = $param_types;
    for($i = 0; $i < count($params); $i++) {
        $refs[] = &$params[$i];
    }
    call_user_func_array(array($revenue_by_method_stmt, 'bind_param'), $refs);
}
$revenue_by_method_stmt->execute();
$revenue_by_method = $revenue_by_method_stmt->get_result();

$methods = [];
$amounts = [];
if ($revenue_by_method && $revenue_by_method->num_rows > 0) {
    echo "<p>Found " . $revenue_by_method->num_rows . " payment methods:</p>";
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

// Test 4: Check membership plan data
echo "<h3>4. Membership Plan Data:</h3>";
$revenue_by_plan_sql = "
    SELECT 
        COALESCE(mp.name, 'No Plan') as plan_name,
        COUNT(*) as payment_count,
        SUM(ph.amount) as total_amount
    FROM payment_history ph
    JOIN users u ON ph.user_id = u.id
    LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
    WHERE $where_clause
    GROUP BY mp.id, mp.name
    ORDER BY total_amount DESC
";

$revenue_by_plan_stmt = $conn->prepare($revenue_by_plan_sql);
if (count($params) > 0) {
    $refs = array();
    $refs[] = $param_types;
    for($i = 0; $i < count($params); $i++) {
        $refs[] = &$params[$i];
    }
    call_user_func_array(array($revenue_by_plan_stmt, 'bind_param'), $refs);
}
$revenue_by_plan_stmt->execute();
$revenue_by_plan = $revenue_by_plan_stmt->get_result();

$plans = [];
$planAmounts = [];
if ($revenue_by_plan && $revenue_by_plan->num_rows > 0) {
    echo "<p>Found " . $revenue_by_plan->num_rows . " membership plans:</p>";
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

// Test 5: Show JSON data that would be passed to JavaScript
echo "<h3>5. JSON Data for JavaScript:</h3>";
echo "<h4>Daily Revenue Data:</h4>";
echo "<pre>" . json_encode(['dates' => $dates, 'revenues' => $revenues], JSON_PRETTY_PRINT) . "</pre>";

echo "<h4>Payment Method Data:</h4>";
echo "<pre>" . json_encode(['methods' => $methods, 'amounts' => $amounts], JSON_PRETTY_PRINT) . "</pre>";

echo "<h4>Membership Plan Data:</h4>";
echo "<pre>" . json_encode(['plans' => $plans, 'amounts' => $planAmounts], JSON_PRETTY_PRINT) . "</pre>";

// Test 6: Check if Chart.js is working
echo "<h3>6. Chart.js Test:</h3>";
echo "<div style='width: 400px; height: 300px;'>";
echo "<canvas id='testChart'></canvas>";
echo "</div>";

echo "<script src='https://cdn.jsdelivr.net/npm/chart.js'></script>";
echo "<script>";
echo "document.addEventListener('DOMContentLoaded', function() {";
echo "    const ctx = document.getElementById('testChart').getContext('2d');";
echo "    new Chart(ctx, {";
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
echo "});";
echo "</script>";
?> 