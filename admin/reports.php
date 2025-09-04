<?php
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

require '../db.php';

// Handle Export Request
if (isset($_GET['export']) && $_GET['export'] == '1') {
    exportReportData();
    exit();
}

// Export Function
function exportReportData() {
    global $conn;
    
    // Get filter parameters (default to current year)
    $date_from = $_GET['date_from'] ?? date('Y-01-01');
    $date_to = $_GET['date_to'] ?? date('Y-m-d');
    $member_filter = $_GET['member'] ?? '';
    $status_filter = $_GET['status'] ?? '';
    $payment_method_filter = $_GET['payment_method'] ?? '';
    
    // Build WHERE clause for filtering by actual transaction date
    $display_date_expr = "ph.payment_date";
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
    
    // Get detailed payment data for export
    $export_sql = "
        SELECT 
            ph.id,
            u.username,
            u.email,
            ph.amount,
            ph.payment_date,
            $display_date_expr AS display_payment_date,
            ph.payment_method,
            ph.payment_status,
            ph.reference_number,
            mp.name as plan_name,
            ph.created_at
        FROM payment_history ph
        JOIN users u ON ph.user_id = u.id
        LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
        WHERE $where_clause
        ORDER BY ph.payment_date DESC
    ";
    
    $export_stmt = $conn->prepare($export_sql);
    if (count($params) > 0) {
        $refs = array();
        $refs[] = $param_types;
        for($i = 0; $i < count($params); $i++) {
            $refs[] = &$params[$i];
        }
        call_user_func_array(array($export_stmt, 'bind_param'), $refs);
    }
    $export_stmt->execute();
    $export_result = $export_stmt->get_result();
    
    // Get summary data
    $summary_sql = "
        SELECT 
            COUNT(*) as total_payments,
            SUM(CASE WHEN ph.payment_status = 'Approved' THEN ph.amount ELSE 0 END) as total_revenue,
            COUNT(CASE WHEN ph.payment_status = 'Approved' THEN 1 END) as completed_payments,
            COUNT(CASE WHEN ph.payment_status = 'Pending' THEN 1 END) as pending_payments,
            COUNT(CASE WHEN ph.payment_status = 'Rejected' THEN 1 END) as failed_payments,
            AVG(CASE WHEN ph.payment_status = 'Approved' THEN ph.amount ELSE NULL END) as avg_payment_amount
        FROM payment_history ph
        JOIN users u ON ph.user_id = u.id
        WHERE $where_clause
    ";
    
    $summary_stmt = $conn->prepare($summary_sql);
    if (count($params) > 0) {
        $refs = array();
        $refs[] = $param_types;
        for($i = 0; $i < count($params); $i++) {
            $refs[] = &$params[$i];
        }
        call_user_func_array(array($summary_stmt, 'bind_param'), $refs);
    }
    $summary_stmt->execute();
    $summary = $summary_stmt->get_result()->fetch_assoc();
    
    // Set headers for CSV download
    $filename = "almo_fitness_reports_" . date('Y-m-d_H-i-s') . ".csv";
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Create file pointer connected to the output stream
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Summary Section
    fputcsv($output, ['ALMO FITNESS GYM - REPORTS SUMMARY']);
    fputcsv($output, ['']);
    fputcsv($output, ['Report Period:', $date_from . ' to ' . $date_to]);
    fputcsv($output, ['Generated On:', date('Y-m-d H:i:s')]);
    fputcsv($output, ['']);
    
    // Summary Data
    fputcsv($output, ['SUMMARY METRICS']);
    fputcsv($output, ['Total Revenue', '₱' . number_format($summary['total_revenue'], 2)]);
    fputcsv($output, ['Total Payments', $summary['total_payments']]);
    fputcsv($output, ['Completed Payments', $summary['completed_payments']]);
    fputcsv($output, ['Pending Payments', $summary['pending_payments']]);
    fputcsv($output, ['Failed Payments', $summary['failed_payments']]);
    fputcsv($output, ['Average Payment Amount', '₱' . number_format($summary['avg_payment_amount'], 2)]);
    fputcsv($output, ['']);
    
    // Detailed Payment Data
    fputcsv($output, ['DETAILED PAYMENT RECORDS']);
    fputcsv($output, [
        'Payment ID',
        'Member Username',
        'Email',
        'Amount',
        'Payment Date',
        'Payment Method',
        'Status',
        'Reference Number',
        'Plan Name',
        'Created At'
    ]);
    
    while ($row = $export_result->fetch_assoc()) {
        fputcsv($output, [
            $row['id'],
            $row['username'],
            $row['email'],
            '₱' . number_format($row['amount'], 2),
            $row['display_payment_date'],
            $row['payment_method'],
            $row['payment_status'],
            $row['reference_number'],
            $row['plan_name'],
            $row['created_at']
        ]);
    }
    
    fputcsv($output, ['']);
    
    // Payment Method Summary
    fputcsv($output, ['REVENUE BY PAYMENT METHOD']);
    $method_sql = "
        SELECT 
            ph.payment_method,
            COUNT(*) as payment_count,
            SUM(ph.amount) as total_amount
        FROM payment_history ph
        JOIN users u ON ph.user_id = u.id
        WHERE ph.payment_status = 'Approved' AND $where_clause
        GROUP BY ph.payment_method
        ORDER BY total_amount DESC
    ";
    
    $method_stmt = $conn->prepare($method_sql);
    if (count($params) > 0) {
        $refs = array();
        $refs[] = $param_types;
        for($i = 0; $i < count($params); $i++) {
            $refs[] = &$params[$i];
        }
        call_user_func_array(array($method_stmt, 'bind_param'), $refs);
    }
    $method_stmt->execute();
    $method_result = $method_stmt->get_result();
    
    fputcsv($output, ['Payment Method', 'Number of Payments', 'Total Amount']);
    while ($row = $method_result->fetch_assoc()) {
        fputcsv($output, [
            $row['payment_method'],
            $row['payment_count'],
            '₱' . number_format($row['total_amount'], 2)
        ]);
    }
    
    fputcsv($output, ['']);
    
    // Membership Plan Summary
    fputcsv($output, ['REVENUE BY MEMBERSHIP PLAN']);
    $plan_sql = "
        SELECT
            COALESCE(mp.name, 'No Plan') as plan_name,
            COUNT(*) as payment_count,
            SUM(ph.amount) as total_amount
        FROM payment_history ph
        JOIN users u ON ph.user_id = u.id
        LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
        WHERE ph.payment_status = 'Approved' AND $where_clause
        GROUP BY mp.id, mp.name
        ORDER BY total_amount DESC
    ";
    
    $plan_stmt = $conn->prepare($plan_sql);
    if (count($params) > 0) {
        $refs = array();
        $refs[] = $param_types;
        for($i = 0; $i < count($params); $i++) {
            $refs[] = &$params[$i];
        }
        call_user_func_array(array($plan_stmt, 'bind_param'), $refs);
    }
    $plan_stmt->execute();
    $plan_result = $plan_stmt->get_result();
    
    fputcsv($output, ['Plan Name', 'Number of Payments', 'Total Amount']);
    while ($row = $plan_result->fetch_assoc()) {
        fputcsv($output, [
            $row['plan_name'],
            $row['payment_count'],
            '₱' . number_format($row['total_amount'], 2)
        ]);
    }
    
    fclose($output);
    exit();
}

// Get filter parameters (default to current year)
$date_from = $_GET['date_from'] ?? date('Y-01-01'); // Default to start of current year
$date_to = $_GET['date_to'] ?? date('Y-m-d'); // Today
$member_filter = $_GET['member'] ?? '';
$status_filter = $_GET['status'] ?? '';
$payment_method_filter = $_GET['payment_method'] ?? '';

// Get current user data for the top bar
$current_user = null;
if (isset($_SESSION['user_id'])) {
    $user_sql = "SELECT username, email FROM users WHERE id = ?";
    $user_stmt = $conn->prepare($user_sql);
    $user_stmt->bind_param("i", $_SESSION['user_id']);
    $user_stmt->execute();
    $result = $user_stmt->get_result();
    $current_user = $result->fetch_assoc();
}

// Build dynamic WHERE clause for filtering using actual payment date
// This ensures we filter by when the payment was actually made, not membership dates
$display_date_expr_main = "ph.payment_date";
$where_conditions = ["$display_date_expr_main BETWEEN ? AND ?"];
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

// Function to bind parameters dynamically
function bindParameters($stmt, $param_types, $params) {
    $refs = array();
    $refs[] = $param_types;
    for($i = 0; $i < count($params); $i++) {
        $refs[] = &$params[$i];
    }
    call_user_func_array(array($stmt, 'bind_param'), $refs);
}

// Key Metrics
$metrics_sql = "
    SELECT 
        COUNT(*) as total_payments,
        SUM(CASE WHEN ph.payment_status = 'Approved' THEN ph.amount ELSE 0 END) as total_revenue,
        COUNT(CASE WHEN ph.payment_status = 'Approved' THEN 1 END) as completed_payments,
        COUNT(CASE WHEN ph.payment_status = 'Pending' THEN 1 END) as pending_payments,
        COUNT(CASE WHEN ph.payment_status = 'Rejected' THEN 1 END) as failed_payments,
        AVG(CASE WHEN ph.payment_status = 'Approved' THEN ph.amount ELSE NULL END) as avg_payment_amount
    FROM payment_history ph
    JOIN users u ON ph.user_id = u.id
    WHERE $where_clause
";

$metrics_stmt = $conn->prepare($metrics_sql);
if (count($params) > 0) {
    $refs = array();
    $refs[] = $param_types;
    for($i = 0; $i < count($params); $i++) {
        $refs[] = &$params[$i];
    }
    call_user_func_array(array($metrics_stmt, 'bind_param'), $refs);
}
$metrics_stmt->execute();
$metrics = $metrics_stmt->get_result()->fetch_assoc();

// Member Statistics
$member_stats_sql = "
    SELECT 
        COUNT(DISTINCT u.id) as total_members,
                    COUNT(CASE WHEN (u.membership_end_date IS NULL OR u.membership_end_date > CURDATE()) THEN 1 END) as active_members,
        COUNT(CASE WHEN u.membership_end_date < CURDATE() THEN 1 END) as expired_members,
        COUNT(CASE WHEN u.balance > 0 THEN 1 END) as members_with_balance
    FROM users u
    WHERE u.role = 'member'
";

$member_stats = $conn->query($member_stats_sql)->fetch_assoc();

// Recent Payments (original: show by selected filters only)
// Detect if payment_history has updated_at
$reports_has_updated_at = false;
$reports_updated_at_column = $conn->query("SHOW COLUMNS FROM payment_history LIKE 'updated_at'");
if ($reports_updated_at_column && $reports_updated_at_column->num_rows > 0) {
    $reports_has_updated_at = true;
}

// Build recent payments query - using actual payment date for accurate transaction history
$recent_payments_sql = "
    SELECT 
        ph.id,
        ph.amount,
        ph.payment_date,
        " . ($reports_has_updated_at ? "ph.updated_at AS updated_at," : "NULL AS updated_at,") . "
        ph.payment_method,
        ph.payment_status,
        ph.reference_number,
        u.username,
        u.email,
        u.membership_start_date,
        u.created_at,
        ph.payment_date AS display_payment_date,
        mp.name as plan_name
    FROM payment_history ph
    JOIN users u ON ph.user_id = u.id
    LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
    WHERE $where_clause
    ORDER BY ph.payment_date DESC
    LIMIT 10
";

$recent_payments_stmt = $conn->prepare($recent_payments_sql);
if (count($params) > 0) {
    $refs = array();
    $refs[] = $param_types;
    for($i = 0; $i < count($params); $i++) {
        $refs[] = &$params[$i];
    }
    call_user_func_array(array($recent_payments_stmt, 'bind_param'), $refs);
}
$recent_payments_stmt->execute();
$recent_payments = $recent_payments_stmt->get_result();

// Revenue by Payment Method - Show ALL historical data regardless of filter
$revenue_by_method_sql = "
    SELECT 
        ph.payment_method,
        COUNT(*) as payment_count,
        SUM(ph.amount) as total_amount
    FROM payment_history ph
    JOIN users u ON ph.user_id = u.id
    WHERE ph.payment_status = 'Approved'
    GROUP BY ph.payment_method
    ORDER BY total_amount DESC
";

$revenue_by_method_stmt = $conn->prepare($revenue_by_method_sql);
$revenue_by_method_stmt->execute();
$revenue_by_method = $revenue_by_method_stmt->get_result();

// Revenue by Membership Plan - Show ALL historical data regardless of filter
        $revenue_by_plan_sql = "
            SELECT
                COALESCE(mp.name, 'No Plan') as plan_name,
                COUNT(*) as payment_count,
                SUM(ph.amount) as total_amount
            FROM payment_history ph
            JOIN users u ON ph.user_id = u.id
            LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
            WHERE ph.payment_status = 'Approved'
            GROUP BY mp.id, mp.name
            ORDER BY total_amount DESC
        ";

$revenue_by_plan_stmt = $conn->prepare($revenue_by_plan_sql);
$revenue_by_plan_stmt->execute();
$revenue_by_plan = $revenue_by_plan_stmt->get_result();

// Daily Revenue for Chart - Show ALL historical data regardless of filter
$daily_revenue_sql = "
    SELECT 
        DATE(ph.payment_date) as date,
        SUM(ph.amount) as daily_revenue,
        COUNT(*) as payment_count
    FROM payment_history ph
    JOIN users u ON ph.user_id = u.id
    WHERE ph.payment_status = 'Approved'
    GROUP BY DATE(ph.payment_date)
    ORDER BY date ASC
";

$daily_revenue_stmt = $conn->prepare($daily_revenue_sql);
$daily_revenue_stmt->execute();
$daily_revenue = $daily_revenue_stmt->get_result();

// Get unique members for filter dropdown
$members_sql = "SELECT DISTINCT u.username, u.email FROM users u JOIN payment_history ph ON u.id = ph.user_id ORDER BY u.username";
$members_result = $conn->query($members_sql);

// Default profile picture and display name
$profile_picture = '../uploads/profile_pictures/default.jpg';
$display_name = $current_user['username'] ?? $current_user['email'] ?? 'Admin';
$page_title = 'Reports & Analytics';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .sidebar-scroll::-webkit-scrollbar {
            width: 6px;
        }
        .sidebar-scroll::-webkit-scrollbar-track {
            background: transparent;
        }
        .sidebar-scroll::-webkit-scrollbar-thumb {
            background: #4B5563;
            border-radius: 3px;
        }
        .sidebar-scroll::-webkit-scrollbar-thumb:hover {
            background: #374151;
        }
        
        /* Enhanced chart containers */
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        
        .no-data-message {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            color: #6b7280;
        }
        
        /* Custom scrollbar for recent payments */
        .recent-payments-scroll-container::-webkit-scrollbar {
            width: 6px;
        }
        
        .recent-payments-scroll-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }
        
        .recent-payments-scroll-container::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 3px;
        }
        
        .recent-payments-scroll-container::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }
        
        /* Ensure page stays fixed */
        body {
            overflow-x: hidden;
        }
        
        /* Prevent horizontal scrolling on the main page */
        .main-content {
            overflow-x: hidden;
        }
        
        /* Prevent horizontal scrolling on the main content div */
        #mainContent {
            overflow-x: hidden;
        }
        
        /* Sticky header for recent payments table */
        .sticky {
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        /* Enhanced filter styling */
        .filter-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border: 1px solid #e2e8f0;
            border-radius: 1rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        
        .filter-input {
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 0.5rem;
            transition: all 0.2s ease-in-out;
        }
        
        .filter-input:focus {
            border-color: #ef4444;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
        }
        
        /* Enhanced metric cards */
        .metric-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border: 1px solid #e2e8f0;
            border-radius: 1rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease-in-out;
        }
        
        .metric-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }
        
        /* Enhanced chart cards */
        .chart-card {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
        }
        
        /* Payment method colors */
        .payment-method-cash { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .payment-method-gcash { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
        .payment-method-paymaya { background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); }
        .payment-method-gotyme { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
        
        /* Loading animation */
        .loading-spinner {
            border: 2px solid #f3f4f6;
            border-top: 2px solid #ef4444;
            border-radius: 50%;
            width: 1rem;
            height: 1rem;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Enhanced chart containers */
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        
        .no-data-message {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            color: #6b7280;
        }
        
        /* Enhanced chart cards */
        .chart-card {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border: 1px solid #e2e8f0;
        }
        
        /* Notification Animation Classes */
        .animate-pulse {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        
        @keyframes pulse {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: .5;
            }
        }
        
        .animate-bounce {
            animation: bounce 1s infinite;
        }
        
        @keyframes bounce {
            0%, 20%, 53%, 80%, 100% {
                transform: translate3d(0,0,0);
            }
            40%, 43% {
                transform: translate3d(0,-30px,0);
            }
            70% {
                transform: translate3d(0,-15px,0);
            }
            90% {
                transform: translate3d(0,-4px,0);
            }
        }
    </style>
</head>
<body class="bg-gray-100">
    <!-- Sidebar -->
    <aside id="sidebar" class="fixed top-0 left-0 w-64 h-screen text-white flex flex-col rounded-r-xl shadow-xl transition-all duration-300" style="background: linear-gradient(to bottom, #18181b 0%, #7f1d1d 100%);">
        <div id="sidebarHeader" class="px-6 py-5 border-b border-gray-700 flex items-center space-x-2 relative">
            <img src="../image/almo.jpg" alt="Almo Fitness Gym Logo" class="w-8 h-8 rounded-full object-cover shadow sidebar-logo-img cursor-pointer" style="min-width:2rem;">
            <span class="text-lg font-bold tracking-tight whitespace-nowrap sidebar-logo-text" style="font-family: 'Segoe UI', 'Inter', sans-serif;">Almo Fitness Gym</span>
            <button id="sidebarToggle" class="ml-2 p-2 rounded-full hover:bg-gray-700/40 transition-all duration-300 focus:outline-none sidebar-toggle-btn flex items-center justify-center absolute right-4" title="Collapse sidebar" style="top:50%;transform:translateY(-50%);">
                <i class="fas fa-chevron-left transition-transform duration-300"></i>
            </button>
        </div>
        <nav class="flex-1 px-4 py-6 space-y-2 overflow-y-auto sidebar-scroll">
            <a href="dashboard.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'bg-red-600 text-white shadow-md' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-home w-6 text-center"></i> <span>Dashboard</span>
            </a>
            <a href="manage_members.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'manage_members.php' ? 'bg-red-600 text-white shadow-md' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-users w-6 text-center"></i> <span>Members</span>
            </a>
            <a href="manage_membership.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'manage_membership.php' ? 'bg-red-600 text-white shadow-md' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-id-card w-6 text-center"></i> <span>Membership</span>
            </a>
            <a href="manage_trainers.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'manage_trainers.php' ? 'bg-red-600 text-white shadow-md' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-user-tie w-6 text-center"></i> <span>Trainers</span>
            </a>
            <a href="manage_equipment.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'manage_equipment.php' ? 'bg-red-600 text-white shadow-md' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-cogs w-6 text-center"></i> <span>Equipment</span>
            </a>
            <a href="manage_announcements.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'manage_announcements.php' ? 'bg-red-600 text-white shadow-md' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-bullhorn w-6 text-center"></i> <span>Announcements</span>
            </a>
            <a href="manage_payments.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'manage_payments.php' ? 'bg-red-600 text-white shadow-md' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-credit-card w-6 text-center"></i> <span>Payments</span>
            </a>
            <a href="manage_feedback.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'manage_feedback.php' ? 'bg-red-600 text-white shadow-md' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-comments w-6 text-center"></i> <span>Feedback</span>
            </a>
            <a href="reports.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'bg-red-600 text-white shadow-md' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-chart-bar w-6 text-center"></i> <span>Reports</span>
            </a>
            <a href="attendance_history.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'attendance_history.php' ? 'bg-red-600 text-white shadow-md' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-clock w-6 text-center"></i> <span>Attendance History</span>
            </a>
        </nav>
        <div class="px-4 py-5 border-t border-gray-700 mt-auto flex flex-col space-y-2 sidebar-bottom">
            <a href="../logout.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 hover:bg-gray-700 hover:text-white sidebar-logout">
                <i class="fas fa-sign-out-alt w-6 text-center"></i> <span class="sidebar-bottom-text">Logout</span>
            </a>
        </div>
    </aside>

    <!-- Top Bar -->
    <div class="w-full flex justify-center items-center mt-6 mb-2">
        <header class="shadow-2xl drop-shadow-2xl px-12 py-5 flex justify-between items-center w-full max-w-7xl rounded-2xl bg-clip-padding" style="background: linear-gradient(to right, #18181b 0%, #7f1d1d 100%);">
            <div class="flex items-center">
                <span class="text-lg sm:text-xl font-semibold text-white mr-8" style="font-family: 'Segoe UI', 'Inter', sans-serif; letter-spacing: 0.01em;">
                    Reports
                </span>
            </div>
            <div class="flex items-center space-x-10">
                <!-- Real-Time Notification System -->
                <div class="relative">
                    <button id="notificationBtn" class="text-white hover:text-gray-200 p-2 rounded-full hover:bg-gray-700/30 transition-colors relative">
                        <i class="fas fa-bell text-lg"></i>
                        <!-- Notification Badge -->
                        <span id="notificationBadge" class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full h-5 w-5 flex items-center justify-center font-bold hidden">
                            0
                        </span>
                    </button>
                    
                    <!-- Notification Dropdown -->
                    <div id="notificationDropdown" class="absolute right-0 mt-2 w-80 bg-white rounded-lg shadow-xl border border-gray-200 opacity-0 invisible transition-all duration-200 transform scale-95 origin-top-right z-50 max-h-96 overflow-y-auto">
                        <div class="p-4 border-b border-gray-100">
                            <div class="flex items-center justify-between">
                                <h3 class="text-lg font-semibold text-gray-800">Notifications</h3>
                                <div class="flex items-center space-x-2">
                                    <button onclick="markAllAsRead()" class="text-sm text-blue-600 hover:text-blue-800 font-medium">
                                        Mark all read
                                    </button>
                                    <button onclick="clearAllNotifications()" class="text-sm text-red-600 hover:text-red-800 font-medium">
                                        Clear all
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div id="notificationList" class="p-2">
                            <!-- Notifications will be loaded here -->
                            <div class="text-center py-8 text-gray-500">
                                <i class="fas fa-bell text-3xl mb-3"></i>
                                <p>No notifications yet</p>
                            </div>
                        </div>
                        
                        <div class="p-3 border-t border-gray-100 bg-gray-50">
                            <div class="text-xs text-gray-500 text-center">
                                Real-time updates every 3 seconds
                            </div>
                            <!-- Debug Info -->
                            <div id="debugInfo" class="mt-2 text-xs text-gray-400 text-center hidden">
                                <div>Status: <span id="connectionStatus">Connecting...</span></div>
                                <div>Last Update: <span id="lastUpdate">Never</span></div>
                                <div>Notifications: <span id="notificationCount">0</span></div>
                            </div>
                            <button onclick="toggleDebug()" class="text-xs text-blue-600 hover:text-blue-800 mt-1">
                                Toggle Debug
                            </button>
                        </div>
                    </div>
                </div>
                <div class="relative">
                    <button id="profileDropdown" class="flex items-center space-x-3 p-2 rounded-lg hover:bg-gray-700/30 transition-colors">
                        <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="User Avatar" class="w-10 h-10 rounded-full border-2 border-gray-200 object-cover">
                        <div class="text-left">
                            <h3 class="font-semibold text-white drop-shadow"><?php echo htmlspecialchars($display_name); ?></h3>
                            <p class="text-sm text-gray-200 drop-shadow">Admin</p>
                        </div>
                        <i class="fas fa-chevron-down text-gray-300 text-sm transition-transform duration-200" id="dropdownArrow"></i>
                    </button>
                    
                    <!-- Dropdown Menu -->
                    <div id="profileMenu" class="absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg border border-gray-200 opacity-0 invisible transition-all duration-200 transform scale-95 origin-top-right z-50">
                        <div class="py-2">
                            <a href="profile.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors duration-150">
                                <i class="fas fa-user mr-3 text-gray-500"></i>
                                Profile
                            </a>
                            <a href="settings.php" class="flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors duration-150">
                                <i class="fas fa-cog mr-3 text-gray-500"></i>
                                Settings
                            </a>
                            <div class="border-t border-gray-100 my-1"></div>
                            <a href="../logout.php" class="flex items-center px-4 py-2 text-sm text-red-600 hover:bg-red-50 transition-colors duration-150">
                                <i class="fas fa-sign-out-alt mr-3"></i>
                                Logout
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </header>
    </div>

    <!-- Main Content -->
    <main class="ml-64 mt-16 p-6 transition-all duration-300">
        <div class="max-w-7xl mx-auto">
            <!-- Filter Reports -->
            <div class="filter-card p-6 mb-8">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold text-gray-800 flex items-center">
                        <i class="fas fa-filter text-red-500 mr-3"></i>Filter Reports
                    </h2>
                    <div class="flex space-x-3">
                        <button onclick="applyFilters()" class="px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors duration-200 flex items-center">
                            <i class="fas fa-filter mr-2"></i>Apply
                        </button>
                        <button onclick="clearFilters()" class="px-6 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600 transition-colors duration-200 flex items-center">
                            <i class="fas fa-times mr-2"></i>Clear
                        </button>
                        <button onclick="exportReport()" class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors duration-200 flex items-center">
                            <i class="fas fa-download mr-2"></i>Export
                        </button>
                    </div>
                </div>
                <form id="filterForm" method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Date From</label>
                        <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" 
                               class="filter-input w-full px-3 py-2 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Date To</label>
                        <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" 
                               class="filter-input w-full px-3 py-2 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Member</label>
                        <input type="text" name="member" value="<?php echo htmlspecialchars($member_filter); ?>" 
                               placeholder="Search by name or email" 
                               class="filter-input w-full px-3 py-2 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Payment Status</label>
                        <select name="status" class="filter-input w-full px-3 py-2 focus:outline-none">
                            <option value="">All Statuses</option>
                            <option value="Approved" <?php echo $status_filter === 'Approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="Pending" <?php echo $status_filter === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="Rejected" <?php echo $status_filter === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Payment Method</label>
                        <select name="payment_method" class="filter-input w-full px-3 py-2 focus:outline-none">
                            <option value="">All Methods</option>
                            <option value="Cash" <?php echo $payment_method_filter === 'Cash' ? 'selected' : ''; ?>>Cash</option>
                            <option value="GCash" <?php echo $payment_method_filter === 'GCash' ? 'selected' : ''; ?>>GCash</option>
                            <option value="PayMaya" <?php echo $payment_method_filter === 'PayMaya' ? 'selected' : ''; ?>>PayMaya</option>
                            <option value="GoTyme" <?php echo $payment_method_filter === 'GoTyme' ? 'selected' : ''; ?>>GoTyme</option>
                        </select>
                    </div>
                </form>
            </div>

            <!-- Key Metrics -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="metric-card p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">Total Revenue</h3>
                            <p class="text-3xl font-bold text-green-600">₱<?php echo number_format($metrics['total_revenue'] ?? 0, 2); ?></p>
                            <p class="text-sm text-gray-500 mt-1"><?php echo date('M d', strtotime($date_from)); ?> - <?php echo date('M d', strtotime($date_to)); ?></p>
                        </div>
                        <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-dollar-sign text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="metric-card p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">Total Payments</h3>
                            <p class="text-3xl font-bold text-blue-600"><?php echo number_format($metrics['total_payments'] ?? 0); ?></p>
                            <p class="text-sm text-gray-500 mt-1"><?php echo $metrics['completed_payments'] ?? 0; ?> completed</p>
                        </div>
                        <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-credit-card text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="metric-card p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">Active Members</h3>
                            <p class="text-3xl font-bold text-red-600"><?php echo number_format($member_stats['active_members'] ?? 0); ?></p>
                            <p class="text-sm text-gray-500 mt-1"><?php echo $member_stats['total_members'] ?? 0; ?> total</p>
                        </div>
                        <div class="w-12 h-12 bg-red-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-users text-red-600 text-xl"></i>
                        </div>
                    </div>
                </div>
                
                <div class="metric-card p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">Avg Payment</h3>
                            <p class="text-3xl font-bold text-yellow-600">₱<?php echo number_format($metrics['avg_payment_amount'] ?? 0, 2); ?></p>
                            <p class="text-sm text-gray-500 mt-1">Per transaction</p>
                        </div>
                        <div class="w-12 h-12 bg-yellow-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-chart-line text-yellow-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
                <!-- Daily Revenue Trend -->
                <div class="chart-card p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-xl font-bold text-gray-800 flex items-center">
                            <i class="fas fa-chart-line text-blue-500 mr-3"></i>Daily Revenue Trend (All Time)
                        </h3>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                            <i class="fas fa-chart-bar mr-1"></i>Analytics
                        </span>
                    </div>
                    <div class="chart-container">
                        <canvas id="revenueChart"></canvas>
                        <div id="noDataMessage" class="no-data-message" style="display: none;">
                            <i class="fas fa-chart-bar text-4xl mb-3 text-gray-300"></i>
                            <p class="text-lg font-medium">No revenue data available</p>
                            <p class="text-sm text-gray-400 mt-1">Data will appear here when payments are made</p>
                        </div>
                    </div>
                </div>
                
                <!-- Revenue by Payment Method -->
                <div class="chart-card p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-xl font-bold text-gray-800 flex items-center">
                            <i class="fas fa-credit-card text-green-500 mr-3"></i>Revenue by Payment Method
                        </h3>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                            <i class="fas fa-chart-pie mr-1"></i>Breakdown
                        </span>
                    </div>
                    <div class="chart-container">
                        <canvas id="paymentMethodChart"></canvas>
                        <div id="noPaymentMethodData" class="no-data-message" style="display: none;">
                            <i class="fas fa-credit-card text-4xl mb-3 text-gray-300"></i>
                            <p class="text-lg font-medium">No payment method data</p>
                            <p class="text-sm text-gray-400 mt-1">Data will appear when payments are made</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Revenue by Membership Plan -->
            <div class="chart-card p-6 mb-8">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-xl font-bold text-gray-800 flex items-center">
                        <i class="fas fa-id-card text-purple-500 mr-3"></i>Revenue by Membership Plan
                    </h3>
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                        <i class="fas fa-chart-pie mr-1"></i>Plan Analysis
                    </span>
                </div>
                <div class="chart-container">
                    <canvas id="membershipPlanChart"></canvas>
                    <div id="noMembershipPlanData" class="no-data-message" style="display: none;">
                        <i class="fas fa-id-card text-4xl mb-3 text-gray-300"></i>
                        <p class="text-lg font-medium">No membership plan data</p>
                        <p class="text-sm text-gray-400 mt-1">Data will appear when payments are made</p>
                    </div>
                </div>
            </div>

            <!-- Recent Payment Transactions -->
            <div class="chart-card p-6 mb-8">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-xl font-bold text-gray-800 flex items-center">
                        <i class="fas fa-list-alt text-red-500 mr-3"></i>Recent Payment Transactions
                    </h3>
                    <a href="manage_payments.php" class="text-red-600 hover:text-red-700 text-sm font-medium transition-colors duration-200">
                        <i class="fas fa-external-link-alt mr-1"></i>View All Payments
                    </a>
                </div>
                <div class="recent-payments-scroll-container" style="max-height: 500px; overflow-y: auto; overflow-x: hidden;">
                    <table class="w-full">
                        <thead class="sticky top-0 bg-gradient-to-r from-gray-50 to-gray-100 z-10">
                            <tr>
                                <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">
                                    <i class="fas fa-calendar mr-2"></i>Date
                                </th>
                                <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">
                                    <i class="fas fa-user mr-2"></i>Member
                                </th>
                                <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">
                                    <i class="fas fa-dollar-sign mr-2"></i>Amount
                                </th>
                                <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">
                                    <i class="fas fa-credit-card mr-2"></i>Method
                                </th>
                                <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">
                                    <i class="fas fa-check-circle mr-2"></i>Status
                                </th>
                                <th class="px-6 py-4 text-left text-xs font-bold text-gray-700 uppercase tracking-wider">
                                    <i class="fas fa-id-card mr-2"></i>Plan
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if ($recent_payments && $recent_payments->num_rows > 0): ?>
                                <?php while($payment = $recent_payments->fetch_assoc()): ?>
                                <tr class="hover:bg-gradient-to-r hover:from-red-50 hover:to-red-100 transition-all duration-200">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-semibold text-gray-900">
                                            <?php echo date('M d, Y', strtotime($payment['display_payment_date'])); ?>
                                        </div>
                                        <div class="text-xs text-gray-500">
                                            <?php echo date('l', strtotime($payment['display_payment_date'])); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0 h-10 w-10">
                                                <div class="h-10 w-10 rounded-full bg-gradient-to-br from-red-400 to-red-600 flex items-center justify-center shadow-md">
                                                    <span class="text-white font-bold text-sm">
                                                        <?php echo strtoupper(substr($payment['username'] ?? 'U', 0, 1)); ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="ml-4">
                                                <div class="text-sm font-bold text-gray-900">
                                                    <?php echo htmlspecialchars($payment['username']); ?>
                                                </div>
                                                <div class="text-sm text-gray-500 flex items-center">
                                                    <i class="fas fa-envelope mr-1 text-xs"></i>
                                                    <?php echo htmlspecialchars($payment['email']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-bold text-gray-900">
                                            ₱<?php echo number_format($payment['amount'], 2); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                            <i class="fas fa-credit-card mr-1"></i>
                                            <?php echo htmlspecialchars($payment['payment_method']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold border
                                            <?php echo $payment['payment_status'] === 'Approved' ? 'bg-green-100 text-green-800 border-green-200' : 
                                                ($payment['payment_status'] === 'Pending' ? 'bg-yellow-100 text-yellow-800 border-yellow-200' : 
                                                'bg-red-100 text-red-800 border-red-200'); ?>">
                                            <i class="fas fa-<?php echo $payment['payment_status'] === 'Approved' ? 'check-circle' : ($payment['payment_status'] === 'Pending' ? 'clock' : 'times-circle'); ?> mr-1"></i>
                                            <?php echo htmlspecialchars($payment['payment_status']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                            <i class="fas fa-id-card mr-1"></i>
                                            <?php echo htmlspecialchars($payment['plan_name'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                                        <div class="flex flex-col items-center">
                                            <i class="fas fa-credit-card text-4xl text-gray-300 mb-4"></i>
                                            <p class="text-lg font-medium">No recent payments</p>
                                            <p class="text-sm">No payment transactions found for the selected filters.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Profile Dropdown Toggle
        const profileDropdown = document.getElementById('profileDropdown');
        const profileMenu = document.getElementById('profileMenu');
        const dropdownArrow = document.getElementById('dropdownArrow');

        profileDropdown.addEventListener('click', () => {
            profileMenu.classList.toggle('opacity-0');
            profileMenu.classList.toggle('invisible');
            profileMenu.classList.toggle('scale-95');
            dropdownArrow.classList.toggle('rotate-180');
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', (e) => {
            if (!profileDropdown.contains(e.target)) {
                profileMenu.classList.add('opacity-0', 'invisible', 'scale-95');
                dropdownArrow.classList.remove('rotate-180');
            }
        });

        // Sidebar Toggle
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const toggleIcon = sidebarToggle.querySelector('i');

        sidebarToggle.addEventListener('click', () => {
            sidebar.classList.toggle('w-64');
            sidebar.classList.toggle('w-20');
            toggleIcon.classList.toggle('rotate-180');
            
            // Toggle visibility of text elements
            document.querySelectorAll('.sidebar-logo-text, nav span, .sidebar-bottom-text').forEach(el => {
                el.classList.toggle('hidden');
            });
            
            // Toggle main content margin
            const mainContent = document.querySelector('main');
            if (mainContent) {
                mainContent.classList.toggle('ml-64');
                mainContent.classList.toggle('ml-20');
            }
        });

        // Filter Functions
        function applyFilters() {
            document.getElementById('filterForm').submit();
        }

        function clearFilters() {
            window.location.href = 'reports.php';
        }

        function exportReport() {
            const currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set('export', '1');
            window.open(currentUrl.toString(), '_blank');
        }

        // Test Chart.js availability
        console.log('Chart.js available:', typeof Chart !== 'undefined');
        
        // Chart Data
        const dailyRevenueData = <?php
            $dates = [];
            $revenues = [];
            if ($daily_revenue && $daily_revenue->num_rows > 0) {
                while ($row = $daily_revenue->fetch_assoc()) {
                    $dates[] = $row['date'];
                    $revenues[] = (float)$row['daily_revenue'];
                }
            }
            echo json_encode(['dates' => $dates, 'revenues' => $revenues]);
        ?>;

        const paymentMethodData = <?php
            $methods = [];
            $amounts = [];
            $colors = [];
            if ($revenue_by_method && $revenue_by_method->num_rows > 0) {
                while ($row = $revenue_by_method->fetch_assoc()) {
                    $methods[] = $row['payment_method'];
                    $amounts[] = (float)$row['total_amount'];
                    // Assign colors based on payment method
                    switch($row['payment_method']) {
                        case 'Cash': $colors[] = '#10b981'; break;
                        case 'GCash': $colors[] = '#3b82f6'; break;
                        case 'PayMaya': $colors[] = '#8b5cf6'; break;
                        case 'GoTyme': $colors[] = '#f59e0b'; break;
                        default: $colors[] = '#6b7280'; break;
                    }
                }
            }
            echo json_encode(['methods' => $methods, 'amounts' => $amounts, 'colors' => $colors]);
        ?>;

        const membershipPlanData = <?php
            $plans = [];
            $planAmounts = [];
            $planColors = [];
            if ($revenue_by_plan && $revenue_by_plan->num_rows > 0) {
                while ($row = $revenue_by_plan->fetch_assoc()) {
                    $plans[] = $row['plan_name'];
                    $planAmounts[] = (float)$row['total_amount'];
                    // Generate colors for plans
                    $planColors[] = '#' . sprintf('%06x', mt_rand(0, 16777215));
                }
            }
            echo json_encode(['plans' => $plans, 'amounts' => $planAmounts, 'colors' => $planColors]);
        ?>;

        // Initialize Charts
        document.addEventListener('DOMContentLoaded', function() {
            try {
                console.log('DOM Content Loaded');
                console.log('Daily Revenue Data:', dailyRevenueData);
                console.log('Payment Method Data:', paymentMethodData);
                console.log('Membership Plan Data:', membershipPlanData);
                
                // Daily Revenue Chart
                console.log('Checking revenue chart...');
                const revenueChartElement = document.getElementById('revenueChart');
                console.log('Revenue Chart Element:', revenueChartElement);
                
                if (dailyRevenueData.dates.length > 0 && dailyRevenueData.revenues.length > 0) {
                    console.log('Creating revenue chart with data:', dailyRevenueData);
                    // Ensure chart is visible
                    revenueChartElement.style.display = 'block';
                    document.getElementById('noDataMessage').style.display = 'none';
                    
                    const revenueCtx = revenueChartElement.getContext('2d');
                    new Chart(revenueCtx, {
                        type: 'line',
                        data: {
                            labels: dailyRevenueData.dates,
                            datasets: [{
                                label: 'Daily Revenue',
                                data: dailyRevenueData.revenues,
                                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                                borderColor: 'rgba(59, 130, 246, 1)',
                                borderWidth: 2,
                                fill: true,
                                tension: 0.4
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                x: { 
                                    title: { display: true, text: 'Date' },
                                    ticks: {
                                        maxTicksLimit: 15,
                                        callback: function(value, index, values) {
                                            const date = new Date(this.getLabelForValue(value));
                                            return date.toLocaleDateString('en-US', { 
                                                month: 'short', 
                                                day: 'numeric',
                                                year: '2-digit'
                                            });
                                        }
                                    }
                                },
                                y: { 
                                    title: { display: true, text: 'Revenue (₱)' }, 
                                    beginAtZero: true,
                                    ticks: {
                                        callback: function(value) {
                                            return '₱' + value.toLocaleString();
                                        }
                                    }
                                }
                            },
                            plugins: {
                                legend: {
                                    display: true
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            return 'Revenue: ₱' + context.parsed.y.toLocaleString();
                                        }
                                    }
                                }
                            }
                        }
                    });
                } else {
                    console.log('No revenue data, showing no data message');
                    revenueChartElement.style.display = 'none';
                    document.getElementById('noDataMessage').style.display = 'flex';
                }

            // Payment Method Chart
            console.log('Checking payment method chart...');
            const paymentMethodChartElement = document.getElementById('paymentMethodChart');
            console.log('Payment Method Chart Element:', paymentMethodChartElement);
            
            if (paymentMethodData.methods.length > 0 && paymentMethodData.amounts.length > 0) {
                console.log('Creating payment method chart with data:', paymentMethodData);
                // Ensure chart is visible
                paymentMethodChartElement.style.display = 'block';
                document.getElementById('noPaymentMethodData').style.display = 'none';
                
                const methodCtx = paymentMethodChartElement.getContext('2d');
                new Chart(methodCtx, {
                    type: 'doughnut',
                    data: {
                        labels: paymentMethodData.methods,
                        datasets: [{
                            data: paymentMethodData.amounts,
                            backgroundColor: paymentMethodData.colors,
                            borderWidth: 2,
                            borderColor: '#ffffff'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    padding: 20,
                                    usePointStyle: true
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = ((context.parsed / total) * 100).toFixed(1);
                                        return context.label + ': ₱' + context.parsed.toLocaleString() + ' (' + percentage + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            } else {
                console.log('No payment method data, showing no data message');
                paymentMethodChartElement.style.display = 'none';
                document.getElementById('noPaymentMethodData').style.display = 'flex';
            }

            // Membership Plan Chart
            console.log('Checking membership plan chart...');
            const membershipPlanChartElement = document.getElementById('membershipPlanChart');
            console.log('Membership Plan Chart Element:', membershipPlanChartElement);
            
            if (membershipPlanData.plans.length > 0 && membershipPlanData.amounts.length > 0) {
                console.log('Creating membership plan chart with data:', membershipPlanData);
                // Ensure chart is visible
                membershipPlanChartElement.style.display = 'block';
                document.getElementById('noMembershipPlanData').style.display = 'none';
                
                const planCtx = membershipPlanChartElement.getContext('2d');
                new Chart(planCtx, {
                    type: 'bar',
                    data: {
                        labels: membershipPlanData.plans,
                        datasets: [{
                            label: 'Revenue by Plan',
                            data: membershipPlanData.amounts,
                            backgroundColor: membershipPlanData.colors,
                            borderColor: membershipPlanData.colors,
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: { 
                                title: { display: true, text: 'Membership Plan' }
                            },
                            y: { 
                                title: { display: true, text: 'Revenue (₱)' }, 
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return '₱' + value.toLocaleString();
                                    }
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return 'Revenue: ₱' + context.parsed.y.toLocaleString();
                                    }
                                }
                            }
                        }
                    }
                });
            } else {
                console.log('No membership plan data, showing no data message');
                membershipPlanChartElement.style.display = 'none';
                document.getElementById('noMembershipPlanData').style.display = 'flex';
            }
            } catch (error) {
                console.error('Error initializing charts:', error);
                alert('Error loading charts: ' + error.message);
            }
        });
        
        // Real-Time Notification System using Server-Sent Events (SSE)
        console.log('Initializing real-time SSE notification system for reports.php...');
        
        const notificationBtn = document.getElementById('notificationBtn');
        const notificationDropdown = document.getElementById('notificationDropdown');
        const notificationBadge = document.getElementById('notificationBadge');
        const notificationList = document.getElementById('notificationList');
        
        let notifications = [];
        let unreadCount = 0;
        let eventSource = null;
        
        if (!notificationBtn || !notificationDropdown) {
            console.error('Notification elements not found!');
        } else {
            // Connect to real-time server
            function connectToRealTimeServer() {
                if (eventSource) {
                    eventSource.close();
                }
                
                console.log('Connecting to admin real-time notification server...');
                eventSource = new EventSource('real_time_server.php');
                
                eventSource.onopen = function(event) {
                    console.log('✅ Connected to admin real-time notifications');
                    showNotificationAction('Connected to real-time notifications! 🚀', 'success');
                    updateDebugInfo('Connected', unreadCount);
                };
                
                eventSource.addEventListener('notifications', function(event) {
                    const data = JSON.parse(event.data);
                    console.log('🔄 Real-time notifications received:', data);
                    
                    notifications = data.notifications;
                    unreadCount = data.unread_count;
                    
                    updateBadge();
                    renderNotifications();
                    updateDebugInfo('Connected', unreadCount);
                });
                
                eventSource.addEventListener('count_update', function(event) {
                    const data = JSON.parse(event.data);
                    const newCount = data.unread_count;
                    if (newCount !== unreadCount) {
                        unreadCount = newCount;
                        updateBadge();
                        updateDebugInfo('Connected', unreadCount);
                    }
                });
                
                eventSource.addEventListener('error', function(event) {
                    console.error('❌ SSE Error:', event);
                    updateDebugInfo('Error - Reconnecting', unreadCount);
                    setTimeout(connectToRealTimeServer, 5000);
                });
                
                eventSource.onerror = function(event) {
                    console.error('❌ SSE Connection error:', event);
                    eventSource.close();
                    setTimeout(connectToRealTimeServer, 5000);
                };
            }
            
            // Initialize real-time connection
            connectToRealTimeServer();
            
            // Update badge
            function updateBadge() {
                if (unreadCount > 0) {
                    notificationBadge.textContent = unreadCount > 99 ? '99+' : unreadCount;
                    notificationBadge.classList.remove('hidden');
                    notificationBadge.classList.add('animate-pulse');
                } else {
                    notificationBadge.classList.add('hidden');
                    notificationBadge.classList.remove('animate-pulse');
                }
            }
            
            // Render notifications
            function renderNotifications() {
                if (notifications.length === 0) {
                    notificationList.innerHTML = `
                        <div class="text-center py-8 text-gray-500">
                            <i class="fas fa-bell text-3xl mb-3"></i>
                            <p>No notifications</p>
                        </div>
                    `;
                    return;
                }
                
                notificationList.innerHTML = notifications.map(notification => `
                    <div class="notification-item p-3 border-b border-gray-100 last:border-b-0 hover:bg-gray-50 transition-colors cursor-pointer" 
                         data-notification-id="${notification.id}" 
                         data-notification-type="${notification.type}">
                        <div class="flex items-start space-x-3">
                            <div class="flex-shrink-0 mt-1">
                                <div class="w-8 h-8 rounded-full flex items-center justify-center ${getTypeColor(notification.type)}">
                                    <i class="${getTypeIcon(notification.type)} text-white text-sm"></i>
                                </div>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between">
                                    <h4 class="text-sm font-medium text-gray-900">${notification.title}</h4>
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ${getTypeBadgeColor(notification.type)}">
                                        ${notification.priority}
                                    </span>
                                </div>
                                <p class="text-sm text-gray-600 mt-1">${notification.message}</p>
                                <div class="flex items-center justify-between mt-2">
                                    <span class="text-xs text-gray-400">${getTimeAgo(notification.timestamp)}</span>
                                    <button onclick="event.stopPropagation(); markAsRead('${notification.id}')" 
                                            class="text-xs text-blue-600 hover:text-blue-800 font-medium">
                                        Mark read
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                `).join('');
                
                // Add click event to notification items
                document.querySelectorAll('.notification-item').forEach(item => {
                    item.addEventListener('click', function() {
                        const id = this.dataset.notificationId;
                        const type = this.dataset.notificationType;
                        handleNotificationClick(id, type);
                    });
                });
            }
            
            // Helper functions
            function getTypeIcon(type) {
                switch (type) {
                    case 'success': return 'fas fa-check-circle';
                    case 'warning': return 'fas fa-exclamation-triangle';
                    case 'error': return 'fas fa-times-circle';
                    case 'alert': return 'fas fa-bell';
                    default: return 'fas fa-info-circle';
                }
            }
            
            function getTypeColor(type) {
                switch (type) {
                    case 'success': return 'bg-green-500';
                    case 'warning': return 'bg-yellow-500';
                    case 'error': return 'bg-red-500';
                    case 'alert': return 'bg-blue-500';
                    default: return 'bg-gray-500';
                }
            }
            
            function getTypeBadgeColor(type) {
                switch (type) {
                    case 'success': return 'bg-green-100 text-green-800';
                    case 'warning': return 'bg-yellow-100 text-yellow-800';
                    case 'error': return 'bg-red-100 text-red-800';
                    case 'alert': return 'bg-blue-100 text-blue-800';
                    default: return 'bg-gray-100 text-gray-800';
                }
            }
            
            function getTimeAgo(timestamp) {
                const now = Math.floor(Date.now() / 1000);
                const diff = now - timestamp;
                
                if (diff < 60) return 'Just now';
                if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
                if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
                return Math.floor(diff / 86400) + 'd ago';
            }
            
            function handleNotificationClick(id, type) {
                markAsRead(id);
                showNotificationAction('Notification action triggered! ✅', 'success');
                
                const item = document.querySelector(`[data-notification-id="${id}"]`);
                if (item) {
                    item.style.border = '2px solid #3b82f6';
                    item.style.backgroundColor = '#eff6ff';
                    setTimeout(() => {
                        item.style.border = '';
                        item.style.backgroundColor = '';
                    }, 2000);
                }
            }
            
            function showNotificationAction(message, type) {
                const actionDiv = document.createElement('div');
                actionDiv.className = `fixed top-4 right-4 z-50 px-4 py-2 rounded-lg shadow-lg text-white font-medium transform transition-all duration-300 ${
                    type === 'success' ? 'bg-green-500' :
                    type === 'warning' ? 'bg-yellow-500' :
                    type === 'error' ? 'bg-red-500' :
                    'bg-blue-500'
                }`;
                actionDiv.textContent = message;
                
                document.body.appendChild(actionDiv);
                
                setTimeout(() => {
                    actionDiv.style.transform = 'translateX(0)';
                }, 100);
                
                setTimeout(() => {
                    actionDiv.style.transform = 'translateX(100%)';
                    setTimeout(() => {
                        document.body.removeChild(actionDiv);
                    }, 300);
                }, 3000);
            }
            
            function toggleDropdown() {
                const isVisible = !notificationDropdown.classList.contains('invisible');
                
                if (isVisible) {
                    notificationDropdown.classList.add('invisible', 'opacity-0', 'scale-95');
                } else {
                    notificationDropdown.classList.remove('invisible', 'opacity-0', 'scale-95');
                }
            }
            
            // Add click event
            notificationBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                toggleDropdown();
            });
            
            // Close when clicking outside
            document.addEventListener('click', function(e) {
                if (!notificationBtn.contains(e.target) && !notificationDropdown.contains(e.target)) {
                    notificationDropdown.classList.add('invisible', 'opacity-0', 'scale-95');
                }
            });
            
            // Clean up on page unload
            window.addEventListener('beforeunload', function() {
                if (eventSource) {
                    eventSource.close();
                }
            });
            
            console.log('Real-time SSE notification system initialized successfully for reports.php!');
        }
        
        // Global notification action functions
        function markAsRead(notificationId) {
            console.log('Marking notification as read:', notificationId);
            
            fetch('mark_notification_read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `notification_id=${notificationId}&notification_type=general`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log('✅ Notification marked as read');
                    
                    if (unreadCount > 0) {
                        unreadCount--;
                        updateBadge();
                    }
                    
                    notifications = notifications.filter(n => n.id !== notificationId);
                    renderNotifications();
                    
                    showNotificationAction('Notification marked as read! ✅', 'success');
                } else {
                    console.error('❌ Failed to mark notification as read:', data.error);
                    showNotificationAction('Failed to mark as read! ❌', 'error');
                }
            })
            .catch(error => {
                console.error('❌ Error marking notification as read:', error);
                showNotificationAction('Error marking as read! ❌', 'error');
            });
        }
        
        function markAllAsRead() {
            console.log('Marking all notifications as read');
            
            fetch('notification_sync.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=mark_all_read'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log('✅ All notifications marked as read');
                    
                    unreadCount = 0;
                    notifications = [];
                    updateBadge();
                    renderNotifications();
                    
                    showNotificationAction('All notifications marked as read! ✅', 'success');
                } else {
                    console.error('❌ Failed to mark all notifications as read:', data.error);
                    showNotificationAction('Failed to mark all as read! ❌', 'error');
                }
            })
            .catch(error => {
                console.error('❌ Error marking all notifications as read:', error);
                showNotificationAction('Error marking all as read! ❌', 'error');
            });
        }
        
        function clearAllNotifications() {
            console.log('Clearing all notifications');
            
            if (!confirm('Are you sure you want to clear all notifications? This action cannot be undone.')) {
                return;
            }
            
            fetch('notification_sync.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=clear_all'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log('✅ All notifications cleared');
                    
                    unreadCount = 0;
                    notifications = [];
                    updateBadge();
                    renderNotifications();
                    
                    showNotificationAction('All notifications cleared! ✅', 'success');
                } else {
                    console.error('❌ Failed to clear all notifications:', data.error);
                    showNotificationAction('Failed to clear all! ❌', 'error');
                }
            })
            .catch(error => {
                console.error('❌ Error clearing all notifications:', error);
                showNotificationAction('Error clearing all! ❌', 'error');
            });
        }
        
        // Debug functions
        function toggleDebug() {
            const debugInfo = document.getElementById('debugInfo');
            debugInfo.classList.toggle('hidden');
        }
        
        function updateDebugInfo(status, count) {
            const connectionStatus = document.getElementById('connectionStatus');
            const lastUpdate = document.getElementById('lastUpdate');
            const notificationCount = document.getElementById('notificationCount');
            
            if (connectionStatus) connectionStatus.textContent = status;
            if (lastUpdate) lastUpdate.textContent = new Date().toLocaleTimeString();
            if (notificationCount) notificationCount.textContent = count;
        }
    </script>
</body>
</html> 
