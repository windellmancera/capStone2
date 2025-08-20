<?php
require 'db.php';

echo "=== CHECKING OWAPAWI'S DATES ===\n\n";

// Check owapawi's payment and plan information
$check_query = "
    SELECT 
        u.username,
        u.membership_end_date as db_end_date,
        mp.name as plan_name,
        mp.duration as plan_duration,
        ph.payment_date,
        ph.payment_status,
        DATE_ADD(ph.payment_date, INTERVAL mp.duration DAY) as calculated_end_date
    FROM users u
    LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
    LEFT JOIN (
        SELECT user_id, payment_date, payment_status
        FROM payment_history
        WHERE payment_status = 'Approved'
        ORDER BY payment_date DESC
    ) ph ON ph.user_id = u.id
    WHERE u.username = 'owapawi'
";

$result = $conn->query($check_query);
if ($result && $result->num_rows > 0) {
    $data = $result->fetch_assoc();
    echo "Username: {$data['username']}\n";
    echo "Plan: {$data['plan_name']}\n";
    echo "Plan Duration: {$data['plan_duration']} days\n";
    echo "Payment Date: {$data['payment_date']}\n";
    echo "Payment Status: {$data['payment_status']}\n";
    echo "Database End Date: {$data['db_end_date']}\n";
    echo "Calculated End Date: {$data['calculated_end_date']}\n";
    
    // Calculate what the member page would show
    if ($data['payment_date'] && $data['plan_duration']) {
        $calculated_date = date('Y-m-d', strtotime($data['payment_date'] . ' + ' . $data['plan_duration'] . ' days'));
        echo "Member Page Would Show: $calculated_date\n";
    }
} else {
    echo "No data found for owapawi\n";
}
?> 