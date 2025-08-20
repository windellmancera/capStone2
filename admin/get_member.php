<?php
// Fix the path to work both from command line and HTTP
$db_path = file_exists('../db.php') ? '../db.php' : 'db.php';
require_once $db_path;
header('Content-Type: application/json');

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Invalid member ID.']);
    exit;
}

// Fetch member details with current database structure
$stmt = $conn->prepare('
    SELECT 
        u.username, 
        u.email, 
        u.full_name,
        u.mobile_number, 
        u.membership_start_date,
        u.membership_end_date,
        u.payment_status,
        u.created_at,
        u.profile_picture,
        mp.name as plan_name,
        mp.duration,
        ph.payment_date,
        ph.payment_status as latest_payment_status
    FROM users u 
    LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id 
    LEFT JOIN (
        SELECT user_id, payment_date, payment_status 
        FROM payment_history 
        WHERE user_id = ? 
        ORDER BY payment_date DESC 
        LIMIT 1
    ) ph ON ph.user_id = u.id
    WHERE u.id = ? AND u.role = "member" 
    LIMIT 1
');
$stmt->bind_param('ii', $id, $id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Member not found.']);
    exit;
}
$member = $result->fetch_assoc();

// Use payment status from the main query
$payment_status = $member['latest_payment_status'] ?: 'No payments';

// Attendance record
$attStmt = $conn->prepare('SELECT check_in_time FROM attendance WHERE user_id = ? ORDER BY check_in_time DESC LIMIT 10');
$attStmt->bind_param('i', $id);
$attStmt->execute();
$attRes = $attStmt->get_result();
$attendance = [];
while ($row = $attRes->fetch_assoc()) {
    $attendance[] = date('M d, Y H:i', strtotime($row['check_in_time']));
}

// Calculate expiration date - prioritize membership_end_date, then calculate from payment
$expiration_date = null;
if ($member['membership_end_date']) {
    $expiration_date = $member['membership_end_date'];
} elseif ($member['latest_payment_status'] === 'Approved' && $member['payment_date'] && $member['duration']) {
    $expiration_date = date('Y-m-d', strtotime($member['payment_date'] . ' + ' . $member['duration'] . ' days'));
}

// Determine membership status
$membership_status = 'Active'; // Default to Active if they have a membership
if ($expiration_date) {
    if (strtotime($expiration_date) >= time()) {
        $membership_status = 'Active';
    } else {
        $membership_status = 'Expired';
    }
} elseif (!$member['plan_name']) {
    $membership_status = 'No Plan';
}

// Output JSON
$data = [
    'success' => true,
    'full_name' => htmlspecialchars($member['full_name'] ?: $member['username']),
    'email' => htmlspecialchars($member['email']),
    'contact_number' => htmlspecialchars($member['mobile_number'] ?: 'N/A'),
    'profile_picture' => htmlspecialchars($member['profile_picture'] ?: 'N/A'),
    'membership_type' => $member['plan_name'] ? htmlspecialchars($member['plan_name']) : 'No Plan',
    'membership_status' => $membership_status,
    'join_date' => date('M d, Y', strtotime($member['created_at'])),
    'expiration_date' => $expiration_date ? date('M d, Y', strtotime($expiration_date)) : 'No expiration date',
    'payment_status' => htmlspecialchars($payment_status),
    'attendance_record' => $attendance ? implode('<br>', $attendance) : 'No attendance records'
];
echo json_encode($data); 