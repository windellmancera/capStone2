<?php
require 'db.php';

echo "=== FIXING OWAPAWI'S PAYMENT DATE ===\n\n";

// Calculate the correct payment date to get 2026-08-29 as expiration
// Desired expiration: 2026-08-29
// Plan duration: 365 days
// Payment date should be: 2026-08-29 - 365 days = 2025-08-29

$correct_payment_date = '2025-08-29';
$desired_expiration = '2026-08-29';

echo "Desired expiration date: $desired_expiration\n";
echo "Plan duration: 365 days\n";
echo "Correct payment date should be: $correct_payment_date\n\n";

// Update owapawi's payment date
echo "Updating owapawi's payment date...\n";
$update_query = "
    UPDATE payment_history
    SET payment_date = ?
    WHERE user_id = (SELECT id FROM users WHERE username = 'owapawi')
      AND payment_status = 'Approved'
    ORDER BY payment_date DESC
    LIMIT 1
";

$stmt = $conn->prepare($update_query);
$stmt->bind_param("s", $correct_payment_date);

if ($stmt->execute()) {
    echo "Successfully updated owapawi's payment date to $correct_payment_date\n";
} else {
    echo "Error updating payment date: " . $conn->error . "\n";
}
$stmt->close();

// Verify the fix
echo "\n=== VERIFICATION ===\n";
$verify_query = "
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

$result = $conn->query($verify_query);
if ($result && $result->num_rows > 0) {
    $data = $result->fetch_assoc();
    echo "Username: {$data['username']}\n";
    echo "Plan: {$data['plan_name']}\n";
    echo "Plan Duration: {$data['plan_duration']} days\n";
    echo "Payment Date: {$data['payment_date']}\n";
    echo "Payment Status: {$data['payment_status']}\n";
    echo "Database End Date: {$data['db_end_date']}\n";
    echo "Calculated End Date: {$data['calculated_end_date']}\n";
    
    if ($data['calculated_end_date'] == $desired_expiration) {
        echo "\n✅ SUCCESS: Member page will now show the correct expiration date!\n";
    } else {
        echo "\n❌ Still not matching. Calculated: {$data['calculated_end_date']}, Desired: $desired_expiration\n";
    }
} else {
    echo "No data found for owapawi\n";
}
?> 