<?php
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

require '../db.php';

// Handle export functionality
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $export_type = 'excel';
    
    // Get all payment data for export
    $export_sql = "SELECT 
        u.username,
        u.full_name,
        u.email,
        u.mobile_number,
        mp.name as plan_name,
        ph.amount,
        ph.payment_date,
        ph.payment_method,
        ph.payment_status,
        ph.description,
        ph.created_at
    FROM payment_history ph
    LEFT JOIN users u ON ph.user_id = u.id
    LEFT JOIN membership_plans mp ON ph.plan_id = mp.id
    ORDER BY ph.payment_date DESC";
    
    $export_result = $conn->query($export_sql);
    
    if ($export_type === 'excel') {
        // For Excel, we'll create a CSV with Excel-compatible formatting
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="payments_export_' . date('Y-m-d_H-i-s') . '.xls"');
        header('Cache-Control: max-age=0');
        
        // Create output stream
        $output = fopen('php://output', 'w');
        
        // Add Excel headers with BOM for proper encoding
        fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM
        
        // Add tab-separated headers
        fwrite($output, "Username\tFull Name\tEmail\tMobile Number\tPlan Name\tAmount\tPayment Date\tPayment Method\tPayment Status\tDescription\tCreated At\n");
        
        // Add data rows
        while ($row = $export_result->fetch_assoc()) {
            fwrite($output, 
                $row['username'] . "\t" .
                $row['full_name'] . "\t" .
                $row['email'] . "\t" .
                $row['mobile_number'] . "\t" .
                $row['plan_name'] . "\t" .
                '₱' . number_format($row['amount'], 2) . "\t" .
                date('M d, Y', strtotime($row['payment_date'])) . "\t" .
                $row['payment_method'] . "\t" .
                $row['payment_status'] . "\t" .
                $row['description'] . "\t" .
                date('M d, Y H:i:s', strtotime($row['created_at'])) . "\n"
            );
        }
        
        fclose($output);
        exit();
    }
}

$message = '';
$messageClass = '';

// Handle payment status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_status') {
        $payment_id = $_POST['payment_id'];
        $new_status = $_POST['status'];
        $notes = $_POST['notes'] ?? '';
        
        // Check if updated_at column exists
        $check_column = $conn->query("SHOW COLUMNS FROM payment_history LIKE 'updated_at'");
        if ($check_column->num_rows > 0) {
            $sql = "UPDATE payment_history SET payment_status = ?, description = CONCAT(COALESCE(description, ''), ' | Admin Notes: ', ?), updated_at = NOW() WHERE id = ?";
        } else {
            $sql = "UPDATE payment_history SET payment_status = ?, description = CONCAT(COALESCE(description, ''), ' | Admin Notes: ', ?) WHERE id = ?";
        }
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssi", $new_status, $notes, $payment_id);
        
        if ($stmt->execute()) {
            $message = "Payment status updated successfully!";
            $messageClass = 'success';
        } else {
            $message = "Error updating payment status: " . $conn->error;
            $messageClass = 'error';
        }
    }
}

// Handle payment confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment'])) {
    $payment_id = $_POST['payment_id'];
    $user_id = $_POST['user_id'];
    $plan_id = $_POST['plan_id'];
    $amount = $_POST['amount'];
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Update payment status to approved
        $update_payment_sql = "UPDATE payment_history SET payment_status = 'Approved' WHERE id = ?";
        $update_payment_stmt = $conn->prepare($update_payment_sql);
        $update_payment_stmt->bind_param("i", $payment_id);
        $update_payment_stmt->execute();
        $update_payment_stmt->close();
        
        // Get plan details
        $plan_sql = "SELECT * FROM membership_plans WHERE id = ?";
        $plan_stmt = $conn->prepare($plan_sql);
        $plan_stmt->bind_param("i", $plan_id);
        $plan_stmt->execute();
        $plan = $plan_stmt->get_result()->fetch_assoc();
        $plan_stmt->close();
        
        // Get payment date from payment_history
        $payment_sql = "SELECT payment_date FROM payment_history WHERE id = ?";
        $payment_stmt = $conn->prepare($payment_sql);
        $payment_stmt->bind_param("i", $payment_id);
        $payment_stmt->execute();
        $payment_row = $payment_stmt->get_result()->fetch_assoc();
        $payment_stmt->close();
        
        if ($plan && $payment_row) {
            $payment_date = $payment_row['payment_date'];
            $duration = $plan['duration'];
            $end_date = date('Y-m-d', strtotime($payment_date . ' + ' . $duration . ' days'));
            // Always update membership fields on approval
            $activate_sql = "UPDATE users SET 
                           selected_plan_id = ?, 
                           membership_start_date = ?, 
                           membership_end_date = ?, 
                           payment_status = 'active',
                           last_payment_date = CURDATE()
                           WHERE id = ?";
            $activate_stmt = $conn->prepare($activate_sql);
            $activate_stmt->bind_param("issi", $plan_id, $payment_date, $end_date, $user_id);
            $activate_stmt->execute();
            $activate_stmt->close();
        }
        
        // Commit transaction
        $conn->commit();
        $_SESSION['success'] = "Payment approved and membership activated successfully!";
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $_SESSION['error'] = "Error approving payment: " . $e->getMessage();
    }
    
    header("Location: manage_payments.php");
    exit();
}

// Handle payment rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_payment'])) {
    $payment_id = $_POST['payment_id'];
    $user_id = $_POST['user_id'];
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Update payment status to rejected
        $update_payment_sql = "UPDATE payment_history SET payment_status = 'Rejected' WHERE id = ?";
        $update_payment_stmt = $conn->prepare($update_payment_sql);
        $update_payment_stmt->bind_param("i", $payment_id);
        $update_payment_stmt->execute();
        $update_payment_stmt->close();
        
        // Reset user's selected plan
        $reset_sql = "UPDATE users SET selected_plan_id = NULL WHERE id = ?";
        $reset_stmt = $conn->prepare($reset_sql);
        $reset_stmt->bind_param("i", $user_id);
        $reset_stmt->execute();
        $reset_stmt->close();
        
        // Commit transaction
        $conn->commit();
        $_SESSION['success'] = "Payment rejected successfully!";
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $_SESSION['error'] = "Error rejecting payment: " . $e->getMessage();
    }
    
    header("Location: manage_payments.php");
    exit();
}

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

// Determine if updated_at column exists to improve ordering/display
$updated_at_column = $conn->query("SHOW COLUMNS FROM payment_history LIKE 'updated_at'");
$has_updated_at = $updated_at_column && $updated_at_column->num_rows > 0;

// Fetch all payments with user information
$order_by_date = $has_updated_at ? "COALESCE(ph.updated_at, ph.payment_date)" : "ph.payment_date";
$payments = $conn->query("
    SELECT 
        ph.*, 
        u.username, 
        u.email, 
        u.profile_picture, 
        u.payment_status as user_payment_status,
        u.membership_start_date,
        u.created_at,
        /* Display date should match join date: membership_start_date > created_at > payment_date */
        COALESCE(u.membership_start_date, u.created_at, ph.payment_date) AS display_payment_date,
        mp.name as plan_name, 
        mp.duration, 
        mp.price as plan_price,
        selected_mp.name as selected_plan_name, 
        selected_mp.duration as selected_plan_duration
    FROM payment_history ph
    JOIN users u ON ph.user_id = u.id
    LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
    LEFT JOIN membership_plans selected_mp ON u.selected_plan_id = selected_mp.id
    ORDER BY $order_by_date DESC
");

// Get pending payments count
$pending_result = $conn->query("SELECT COUNT(*) as count FROM payment_history WHERE payment_status = 'Pending'");
$pending_count = $pending_result->fetch_assoc()['count'];

// Get today's revenue
$today_revenue_result = $conn->query("SELECT SUM(amount) as total FROM payment_history 
                                     WHERE payment_status = 'Approved' AND DATE(payment_date) = CURDATE()");
$today_revenue = $today_revenue_result->fetch_assoc()['total'] ?? 0;

// Default profile picture and display name
$profile_picture = 'https://i.pravatar.cc/40?img=1';
$display_name = $current_user['username'] ?? $current_user['email'] ?? 'Admin';
$page_title = 'Manage Payments';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Payments - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
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
        
        /* Loading spinner */
        .loading-spinner {
            border: 2px solid #f3f4f6;
            border-top: 2px solid #3b82f6;
            border-radius: 50%;
            width: 1rem;
            height: 1rem;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }



        /* Success message animation */
        .translate-x-full {
            transform: translateX(100%);
        }
        
        .transform {
            transform: translateX(0);
        }

        /* Table scrollable styles */
        .overflow-y-auto {
            scrollbar-width: thin;
            scrollbar-color: #cbd5e0 #f7fafc;
        }
        
        .overflow-y-auto::-webkit-scrollbar {
            width: 8px;
        }
        
        .overflow-y-auto::-webkit-scrollbar-track {
            background: #f7fafc;
            border-radius: 4px;
        }
        
        .overflow-y-auto::-webkit-scrollbar-thumb {
            background: #cbd5e0;
            border-radius: 4px;
        }
        
        .overflow-y-auto::-webkit-scrollbar-thumb:hover {
            background: #a0aec0;
        }
        
        /* Sticky header styles */
        .sticky {
            position: sticky;
        }
        
        .top-0 {
            top: 0;
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
                    <?php echo $page_title ?? 'Manage Payments'; ?>
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
        <?php if ($message): ?>
                <div class="mb-4 p-4 rounded-lg <?php echo $messageClass === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

            <!-- Payment Statistics -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <div class="bg-white rounded-lg shadow-sm p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Total Payments</h3>
                    <p class="text-3xl font-bold text-red-600">
                        <?php
                        $result = $conn->query("SELECT COUNT(*) as count FROM payment_history");
                        echo $result->fetch_assoc()['count'];
                        ?>
                    </p>
            </div>
                <div class="bg-white rounded-lg shadow-sm p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Pending Payments</h3>
                    <p class="text-3xl font-bold text-yellow-600">
                        <?php echo $pending_count; ?>
                    </p>
            </div>
                <div class="bg-white rounded-lg shadow-sm p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Today's Revenue</h3>
                    <p class="text-3xl font-bold text-green-600">
                        ₱<?php echo number_format($today_revenue, 2); ?>
                    </p>
            </div>
        </div>

            <!-- Enhanced Payment Transactions -->
            <div class="bg-white rounded-xl shadow-lg border border-gray-100 overflow-hidden">
                <div class="bg-gradient-to-r from-gray-50 to-gray-100 px-6 py-4 border-b border-gray-200">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-3">
                            <div class="bg-blue-100 p-2 rounded-lg">
                                <i class="fas fa-credit-card text-blue-600"></i>
                            </div>
                            <h2 class="text-2xl font-bold text-gray-800">Payment Transactions</h2>
                            <span class="bg-blue-100 text-blue-800 text-xs font-medium px-2.5 py-0.5 rounded-full">
                                <?php echo $payments ? $payments->num_rows : 0; ?> transactions
                            </span>
                        </div>
                        <div class="flex items-center space-x-2">
                            <button onclick="exportPayments()" class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors duration-200 text-sm font-medium flex items-center">
                                <i class="fas fa-download mr-2"></i>Export
                            </button>
                        </div>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <div class="max-h-96 overflow-y-auto" style="scroll-behavior: smooth;">
                        <table class="w-full">
                            <thead class="sticky top-0 z-10 bg-gradient-to-r from-gray-50 to-gray-100">
                                <tr>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Member</th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Plan</th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Amount</th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Payment Date</th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Method</th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Proof</th>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                        <?php if ($payments && $payments->num_rows > 0): ?>
                                <?php while($payment = $payments->fetch_assoc()): ?>
                                    <tr class="hover:bg-gray-50 transition-colors duration-200">
                                        <td class="px-6 py-4">
                                            <div class="flex items-center">
                                                <?php if ($payment['profile_picture']): ?>
                                                    <img src="../uploads/profile_pictures/<?php echo htmlspecialchars($payment['profile_picture']); ?>" 
                                                         alt="<?php echo htmlspecialchars($payment['username']); ?>" 
                                                         class="w-10 h-10 rounded-full object-cover mr-3 border-2 border-gray-200">
                                                <?php else: ?>
                                                    <div class="w-10 h-10 rounded-full bg-gradient-to-br from-red-400 to-red-600 flex items-center justify-center mr-3 border-2 border-gray-200">
                                                        <span class="text-white text-sm font-bold">
                                                            <?php echo strtoupper(substr($payment['username'], 0, 1)); ?>
                                                        </span>
                                                    </div>
                                                <?php endif; ?>
                                                <div>
                                                    <div class="font-semibold text-gray-900"><?php echo htmlspecialchars($payment['username']); ?></div>
                                                    <div class="text-sm text-gray-500 flex items-center">
                                                        <i class="fas fa-envelope text-xs mr-1"></i>
                                                        <?php echo htmlspecialchars($payment['email']); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="text-sm">
                                                <?php if ($payment['plan_name']): ?>
                                                <div class="flex items-center">
                                                    <div class="w-2 h-2 bg-green-400 rounded-full mr-2"></div>
                                                    <div>
                                                        <div class="font-semibold text-gray-900">
                                                            <?php echo htmlspecialchars($payment['plan_name']); ?>
                                                        </div>
                                                        <div class="text-xs text-green-600 font-medium">Active Plan</div>
                                                    </div>
                                                </div>
                                                <?php else: ?>
                                                <div class="flex items-center">
                                                    <div class="w-2 h-2 bg-gray-400 rounded-full mr-2"></div>
                                                    <span class="text-gray-500 font-medium">No Plan</span>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="font-bold text-lg text-gray-900">
                                                ₱<?php echo number_format($payment['amount'], 2); ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex items-center text-sm text-gray-600">
                                                <i class="fas fa-calendar text-gray-400 mr-2"></i>
                                                <?php echo date('M d, Y', strtotime($payment['display_payment_date'])); ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex items-center">
                                                <div class="px-3 py-1 bg-gray-100 rounded-full text-sm font-medium text-gray-700">
                                                    <i class="fas fa-credit-card text-xs mr-1"></i>
                                                    <?php echo htmlspecialchars($payment['payment_method']); ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold
                                                <?php
                                                switch($payment['payment_status']) {
                                                    case 'Approved':
                                                        echo 'bg-green-100 text-green-800 border border-green-200';
                                                        break;
                                                    case 'Pending':
                                                        echo 'bg-yellow-100 text-yellow-800 border border-yellow-200';
                                                        break;
                                                    case 'Rejected':
                                                        echo 'bg-red-100 text-red-800 border border-red-200';
                                                        break;
                                                    default:
                                                        echo 'bg-gray-100 text-gray-800 border border-gray-200';
                                                }
                                                ?>">
                                                <span class="w-2 h-2 rounded-full mr-2 
                                                    <?php
                                                    switch($payment['payment_status']) {
                                                        case 'Approved':
                                                            echo 'bg-green-400';
                                                            break;
                                                        case 'Pending':
                                                            echo 'bg-yellow-400';
                                                            break;
                                                        case 'Rejected':
                                                            echo 'bg-red-400';
                                                            break;
                                                        default:
                                                            echo 'bg-gray-400';
                                                    }
                                                    ?>"></span>
                                                <?php echo htmlspecialchars($payment['payment_status']); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4">
                                            <?php if ($payment['proof_of_payment']): ?>
                                                <button onclick="viewProof('<?php echo htmlspecialchars($payment['proof_of_payment']); ?>')" 
                                                        class="inline-flex items-center px-3 py-1 bg-blue-100 text-blue-700 rounded-lg hover:bg-blue-200 transition-colors duration-200 text-sm font-medium">
                                                    <i class="fas fa-eye mr-1"></i>
                                                    View Proof
                                                </button>
                                            <?php else: ?>
                                                <span class="inline-flex items-center px-3 py-1 bg-gray-100 text-gray-500 rounded-lg text-sm font-medium">
                                                    <i class="fas fa-times mr-1"></i>
                                                    No proof
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4">
                                        <?php if ($payment['payment_status'] === 'Pending'): ?>
                                                <div class="flex space-x-2">
                                                    <form method="POST" class="inline">
                                                        <input type="hidden" name="payment_id" value="<?php echo $payment['id']; ?>">
                                                        <input type="hidden" name="user_id" value="<?php echo $payment['user_id']; ?>">
                                                        <input type="hidden" name="plan_id" value="<?php echo $payment['plan_id'] ?? ''; ?>">
                                                        <input type="hidden" name="amount" value="<?php echo $payment['amount']; ?>">
                                                        <button type="submit" name="confirm_payment" 
                                                                class="inline-flex items-center px-3 py-2 bg-green-500 text-white text-sm font-medium rounded-lg hover:bg-green-600 transition-all duration-200 transform hover:scale-105 shadow-md"
                                                                onclick="return confirm('Confirm this payment and activate membership?')">
                                                            <i class="fas fa-check mr-1"></i>Confirm
                                                        </button>
                                                    </form>
                                                    <form method="POST" class="inline">
                                                        <input type="hidden" name="payment_id" value="<?php echo $payment['id']; ?>">
                                                        <input type="hidden" name="user_id" value="<?php echo $payment['user_id']; ?>">
                                                        <button type="submit" name="reject_payment" 
                                                                class="inline-flex items-center px-3 py-2 bg-red-500 text-white text-sm font-medium rounded-lg hover:bg-red-600 transition-all duration-200 transform hover:scale-105 shadow-md"
                                                                onclick="return confirm('Reject this payment?')">
                                                            <i class="fas fa-times mr-1"></i>Reject
                                                        </button>
                                                    </form>
                                                </div>
                                        <?php else: ?>
                                            <span class="text-gray-400 text-sm">No actions</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                    <td colspan="8" class="px-4 py-3 text-center text-gray-500">No payments found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                        </table>
                    </div>
                </div>
            </div>
    </main>

    <!-- Status Update Modal -->
    <div id="statusModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-lg p-6 w-full max-w-md">
            <h3 class="text-xl font-semibold text-gray-900 mb-4">Update Payment Status</h3>
            <form action="" method="POST">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="payment_id" id="payment_id">
                <input type="hidden" name="status" id="status">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Notes (optional)</label>
                    <textarea name="notes" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500"></textarea>
                </div>
                
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeModal()" 
                            class="px-4 py-2 bg-gray-200 text-gray-800 rounded hover:bg-gray-300 transition-colors duration-200">
                        Cancel
                    </button>
                    <button type="submit" 
                            class="px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700 transition-colors duration-200">
                        Update Status
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Proof View Modal -->
    <div id="proofModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-lg p-6 w-full max-w-4xl mx-4">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-xl font-semibold text-gray-900">Payment Proof</h3>
                <button onclick="closeProofModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <div id="proofContent" class="text-center">
                <div class="loading-spinner mx-auto mb-4"></div>
                <p class="text-gray-500">Loading proof...</p>
            </div>
        </div>
    </div>

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

        // Status Update Modal Functions
        const statusModal = document.getElementById('statusModal');

        function updateStatus(paymentId, status) {
            document.getElementById('payment_id').value = paymentId;
            document.getElementById('status').value = status;
            statusModal.classList.remove('hidden');
            statusModal.classList.add('flex');
        }

        function closeModal() {
            statusModal.classList.add('hidden');
            statusModal.classList.remove('flex');
        }

        // Close modal when clicking outside
        statusModal.addEventListener('click', (e) => {
            if (e.target === statusModal) {
                closeModal();
            }
        });

        // Proof View Modal Functions
        function viewProof(filename) {
            const modal = document.getElementById('proofModal');
            const content = document.getElementById('proofContent');
            
            // Show loading state
            content.innerHTML = `
                <div class="loading-spinner mx-auto mb-4"></div>
                <p class="text-gray-500">Loading proof...</p>
            `;
            
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            
            // Try to load the proof
            const img = new Image();
            img.onload = function() {
                content.innerHTML = `
                    <img src="../view_proof.php?file=${encodeURIComponent(filename)}" 
                         alt="Payment Proof" 
                         class="max-w-full max-h-96 rounded-lg shadow-lg">
                    <div class="mt-4">
                        <a href="../view_proof.php?file=${encodeURIComponent(filename)}" 
                           target="_blank" 
                           class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-200">
                            <i class="fas fa-external-link-alt mr-2"></i>
                            Open in New Tab
                        </a>
                    </div>
                `;
            };
            
            img.onerror = function() {
                content.innerHTML = `
                    <div class="text-center">
                        <i class="fas fa-exclamation-triangle text-4xl text-yellow-500 mb-4"></i>
                        <h4 class="text-lg font-semibold text-gray-900 mb-2">Proof File Not Found</h4>
                        <p class="text-gray-600 mb-4">The payment proof file "${filename}" could not be found.</p>
                        <p class="text-sm text-gray-500">This might be due to:</p>
                        <ul class="text-sm text-gray-500 mt-2 text-left max-w-md mx-auto">
                            <li>• File was deleted or moved</li>
                            <li>• Database record mismatch</li>
                            <li>• File upload error</li>
                        </ul>
                    </div>
                `;
            };
            
            img.src = `../view_proof.php?file=${encodeURIComponent(filename)}`;
        }

        function closeProofModal() {
            const modal = document.getElementById('proofModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        // Close proof modal when clicking outside
        document.getElementById('proofModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeProofModal();
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeProofModal();
            }
        });

        // Export functionality
        function exportPayments() {
            // Show loading state
            const exportButton = document.querySelector('[onclick="exportPayments()"]');
            exportButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Exporting...';
            exportButton.disabled = true;

            // Create download link for Excel
            const link = document.createElement('a');
            link.href = 'manage_payments.php?export=excel';
            link.download = `payments_export_${new Date().toISOString().slice(0, 19).replace(/:/g, '-')}.xls`;
            
            // Trigger download
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);

            // Show success message
            const messageDiv = document.createElement('div');
            messageDiv.className = 'fixed top-4 right-4 bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg z-50 transform transition-all duration-300 translate-x-full';
            messageDiv.innerHTML = `
                <div class="flex items-center">
                    <i class="fas fa-check-circle mr-2"></i>
                    <span>Excel export completed successfully!</span>
                </div>
            `;
            document.body.appendChild(messageDiv);

            // Animate in
            setTimeout(() => {
                messageDiv.classList.remove('translate-x-full');
            }, 100);

            // Remove message after 3 seconds
            setTimeout(() => {
                messageDiv.classList.add('translate-x-full');
                setTimeout(() => {
                    document.body.removeChild(messageDiv);
                }, 300);
            }, 3000);

            // Reset button state after a short delay
            setTimeout(() => {
                exportButton.innerHTML = '<i class="fas fa-download mr-2"></i>Export';
                exportButton.disabled = false;
            }, 2000);
        }
        
        // Real-Time Notification System using Server-Sent Events (SSE)
        console.log('Initializing real-time SSE notification system for manage_payments.php...');
        
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
            
            console.log('Real-time SSE notification system initialized successfully for manage_payments.php!');
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
