<?php
session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

require_once '../db.php';

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$date_filter = isset($_GET['date']) ? $_GET['date'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$export_type = isset($_GET['type']) ? $_GET['type'] : 'csv';

// Build the query with filters
$where_conditions = [];
$params = [];
$param_types = '';

if ($search) {
    $where_conditions[] = "(u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $param_types .= 'sss';
}

if ($date_filter) {
    $where_conditions[] = "DATE(a.check_in_time) = ?";
    $params[] = $date_filter;
    $param_types .= 's';
}

if ($status_filter) {
    switch ($status_filter) {
        case 'present':
            $where_conditions[] = "TIME(a.check_in_time) BETWEEN '06:00:00' AND '09:00:00'";
            break;
        case 'late':
            $where_conditions[] = "TIME(a.check_in_time) > '09:00:00'";
            break;
        case 'early':
            $where_conditions[] = "TIME(a.check_in_time) < '06:00:00'";
            break;
    }
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
}

// Get attendance records
$sql = "SELECT a.id, a.user_id, u.full_name, u.username, u.email, 
               a.check_in_time, mp.name as plan_name,
               CASE 
                   WHEN TIME(a.check_in_time) BETWEEN '06:00:00' AND '09:00:00' THEN 'Present'
                   WHEN TIME(a.check_in_time) > '09:00:00' THEN 'Late'
                   WHEN TIME(a.check_in_time) < '06:00:00' THEN 'Early'
                   ELSE 'Present'
               END as status
        FROM attendance a 
        JOIN users u ON a.user_id = u.id 
        LEFT JOIN membership_plans mp ON a.plan_id = mp.id 
        $where_clause
        ORDER BY a.check_in_time DESC";

$stmt = $conn->prepare($sql);
if (!empty($param_types)) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$attendance_records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Set headers for file download
$filename = 'attendance_export_' . date('Y-m-d_H-i-s') . '.csv';
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// Create output stream
$output = fopen('php://output', 'w');

// Add UTF-8 BOM for proper encoding
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Write CSV headers
fputcsv($output, [
    'ID',
    'User ID', 
    'Name',
    'Username',
    'Email',
    'Date',
    'Time',
    'Status',
    'Plan',
    'Check-in Time'
]);

// Write data rows
foreach ($attendance_records as $record) {
    fputcsv($output, [
        $record['id'],
        $record['user_id'],
        $record['full_name'] ?? $record['username'],
        $record['username'],
        $record['email'],
        date('Y-m-d', strtotime($record['check_in_time'])),
        date('H:i:s', strtotime($record['check_in_time'])),
        $record['status'],
        $record['plan_name'] ?? 'No Plan',
        $record['check_in_time']
    ]);
}

fclose($output);
exit();
?> 