<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: member_login.php");
    exit();
}

// Database connection
require_once '../db.php';

// Initialize variables with default values
$display_name = 'User';
$profile_picture = 'https://i.pravatar.cc/40?img=1';
$page_title = 'Dashboard';
$user = [];
$announcements = [];
$trainers = [];
$error_message = null;

try {
    // Get user information from database
    $user_id = $_SESSION['user_id'];
    
    // Enhanced user query with membership information and attendance data
    $user_sql = "SELECT u.*, mp.name as plan_name, mp.duration as plan_duration,
                         ph.payment_status, ph.payment_date,
                         (SELECT SUM(amount) FROM payment_history WHERE user_id = u.id AND payment_status = 'Approved') as total_paid,
                         (SELECT COUNT(*) FROM payment_history WHERE user_id = u.id AND payment_status = 'Approved') as completed_payments,
                         (SELECT MAX(check_in_time) FROM attendance WHERE user_id = u.id) as last_visit,
                         (SELECT COUNT(*) FROM attendance WHERE user_id = u.id AND DATE(check_in_time) = CURDATE()) as today_visits,
                         (SELECT COUNT(*) FROM attendance WHERE user_id = u.id AND DATE(check_in_time) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) as this_week_visits,
                         (SELECT COUNT(*) FROM attendance WHERE user_id = u.id AND DATE(check_in_time) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as this_month_visits
                  FROM users u 
                  LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id 
                  LEFT JOIN (
                      SELECT * FROM payment_history 
                      WHERE user_id = ? 
                      ORDER BY payment_date DESC 
                      LIMIT 1
                  ) ph ON ph.user_id = u.id
                  WHERE u.id = ?";
    $stmt = $conn->prepare($user_sql);
    $stmt->bind_param("ii", $user_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    // Calculate membership end date based on payment date and plan duration
    if ($user && $user['payment_status'] === 'Approved' && $user['payment_date'] && $user['plan_duration']) {
        $user['membership_end_date'] = date('Y-m-d', strtotime($user['payment_date'] . ' + ' . $user['plan_duration'] . ' days'));
    }

    // Set display name based on available user data
    if ($user) {
        $display_name = $user['full_name'] ?? $user['username'] ?? $user['email'] ?? 'User';
        $profile_picture = $user['profile_picture'] 
            ? "../uploads/profile_pictures/" . $user['profile_picture']
            : 'https://i.pravatar.cc/40?img=1';
    }

    // Get QR code
    $qr_code_path = null;
    if ($user && !empty($user['qr_code'])) {
        $qr_code_path = "../uploads/qr_codes/" . $user['qr_code'];
    }

    // Get announcements
    $announcements_sql = "SELECT * FROM announcements ORDER BY created_at DESC LIMIT 5";
    $announcements_result = $conn->query($announcements_sql);
    
    if ($announcements_result) {
        while ($row = $announcements_result->fetch_assoc()) {
            $announcements[] = $row;
        }
    }

    // Get trainers
    $trainers_sql = "SELECT * FROM trainers ORDER BY experience_years DESC, name ASC LIMIT 3";
    $trainers_result = $conn->query($trainers_sql);
    
    if ($trainers_result) {
        while ($row = $trainers_result->fetch_assoc()) {
            $trainers[] = $row;
        }
    }

} catch (Exception $e) {
    error_log("Error in homepage.php: " . $e->getMessage());
    $error_message = "An error occurred while loading the dashboard. Please try again later.";
}

// Helper function to get membership status badge
function getMembershipStatusBadge($user) {
    if (!$user) {
        return '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800">No Membership</span>';
    }
    
    // Check if user has a selected plan
    if (!isset($user['selected_plan_id']) || empty($user['selected_plan_id'])) {
        return '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800">No Plan Selected</span>';
    }
    
    // Check payment status
    if (isset($user['payment_status'])) {
        switch ($user['payment_status']) {
            case 'Approved':
                return '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">Active</span>';
            case 'Pending':
                return '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800">Payment Pending</span>';
            case 'Failed':
                return '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">Payment Failed</span>';
            default:
                return '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">Plan Selected</span>';
        }
    }
    
    // Check membership end date
    if (isset($user['membership_end_date'])) {
        $end_date = strtotime($user['membership_end_date']);
        $current_date = time();
        
        if ($end_date >= $current_date) {
            return '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">Active</span>';
        } else {
            return '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">Expired</span>';
        }
    }
    
    return '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">Plan Selected</span>';
}

// Helper function to get membership days remaining
function getMembershipDaysRemaining($user) {
    if (!$user || !isset($user['membership_end_date'])) {
        return 0;
    }
    
    $end_date = strtotime($user['membership_end_date']);
    $current_date = time();
    $days_remaining = ceil(($end_date - $current_date) / (24 * 60 * 60));
    
    return max(0, $days_remaining);
}

// Check if tables exist
function tableExists($conn, $table) {
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    return $result->num_rows > 0;
}

$tables_status = [
    'equipment_usage' => tableExists($conn, 'equipment_usage'),
    'equipment' => tableExists($conn, 'equipment'),
    'equipment_categories' => tableExists($conn, 'equipment_categories'),
    'training_sessions' => tableExists($conn, 'training_sessions'),
    'trainers' => tableExists($conn, 'trainers'),
    'feedback' => tableExists($conn, 'feedback'),
    'member_analytics' => tableExists($conn, 'member_analytics')
];

$setup_needed = !array_product($tables_status);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Almo Fitness</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>

        /* Equipment panel styles */
        .equipment-grid {
            display: grid;
            grid-template-columns: repeat(1, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        @media (min-width: 768px) {
            .equipment-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        .equipment-panel {
            height: 100%;
            min-height: 140px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        /* Text truncation */
        .line-clamp-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .truncate {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* Status badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 500;
            white-space: nowrap;
        }

        /* Recent usage grid */
        .usage-grid {
            display: grid;
            grid-template-columns: repeat(1, 1fr);
            gap: 0.75rem;
        }

        @media (min-width: 768px) {
            .usage-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        .usage-panel {
            background-color: rgb(249, 250, 251);
            padding: 0.75rem;
            border-radius: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        /* Custom scrollbar styles */
        .overflow-y-auto {
            scrollbar-width: thin;
            scrollbar-color: #E5E7EB transparent;
        }

        .overflow-y-auto::-webkit-scrollbar {
            width: 6px;
        }

        .overflow-y-auto::-webkit-scrollbar-track {
            background: transparent;
        }

        .overflow-y-auto::-webkit-scrollbar-thumb {
            background-color: #E5E7EB;
            border-radius: 3px;
        }

        .overflow-y-auto::-webkit-scrollbar-thumb:hover {
            background-color: #D1D5DB;
        }

        /* Responsive grid adjustments */
        @media (max-width: 768px) {
            .overflow-y-auto {
                max-height: 600px;
            }
        }

        /* Horizontal scroll container */
        .equipment-scroll-container {
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none; /* Firefox */
            -ms-overflow-style: none;  /* IE and Edge */
        }

        .equipment-scroll-container::-webkit-scrollbar {
            display: none; /* Chrome, Safari and Opera */
        }

        /* Equipment row */
        .equipment-row {
            width: max-content;
            padding: 0.5rem 0;
        }

        /* Equipment card */
        .equipment-card {
            transition: all 0.2s ease-in-out;
        }

        .equipment-card:hover {
            transform: translateY(-2px);
        }

        /* Text truncation */
        .line-clamp-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        /* Responsive adjustments */
        @media (max-width: 640px) {
            .equipment-card {
                width: 260px;
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
            <a href="homepage.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'homepage.php' ? 'bg-red-600 text-white shadow-md' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-home w-6 text-center"></i> <span>Dashboard</span>
            </a>
            <a href="profile.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'bg-red-600 text-white shadow-md' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-user w-6 text-center"></i> <span>Profile</span>
            </a>
            <a href="membership.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'membership.php' ? 'bg-red-600 text-white shadow-md' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-id-card w-6 text-center"></i> <span>Membership</span>
            </a>
            <a href="payment.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'payment.php' ? 'bg-red-600 text-white shadow-md' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-credit-card w-6 text-center"></i> <span>Payments</span>
            </a>
            <a href="equipment.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'equipment.php' ? 'bg-red-600 text-white shadow-md' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-dumbbell w-6 text-center"></i> <span>Equipment</span>
            </a>

            <a href="trainers.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'trainers.php' ? 'bg-red-600 text-white shadow-md' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-users w-6 text-center"></i> <span>Trainers</span>
            </a>
            <a href="attendance_history.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'attendance_history.php' ? 'bg-red-600 text-white shadow-md' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-clock w-6 text-center"></i> <span>Attendance History</span>
            </a>
            <a href="progress.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 <?php echo basename($_SERVER['PHP_SELF']) == 'progress.php' ? 'bg-red-600 text-white shadow-md' : 'text-gray-300 hover:bg-gray-700 hover:text-white'; ?>">
                <i class="fas fa-chart-line w-6 text-center"></i> <span>Progress</span>
            </a>
        </nav>
        <div class="px-4 py-5 border-t border-gray-700 mt-auto flex flex-col space-y-2 sidebar-bottom">
            <a href="settings.php" class="flex items-center gap-2 px-4 py-2 rounded transition-colors duration-200 hover:bg-gray-700 hover:text-white sidebar-settings">
                <i class="fas fa-cog w-6 text-center"></i> <span class="sidebar-bottom-text">Settings</span>
            </a>
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
                    <?php echo $page_title ?? 'Dashboard'; ?>
                </span>
            </div>
            <div class="flex items-center space-x-10">
                <div class="relative">
                    <button class="text-white hover:text-gray-200 p-2 rounded-full hover:bg-gray-700/30 transition-colors">
                        <i class="fas fa-bell text-lg"></i>
                    </button>
                </div>
                <div class="relative">
                    <button id="profileDropdown" class="flex items-center space-x-3 p-2 rounded-lg hover:bg-gray-700/30 transition-colors">
                        <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="User Avatar" class="w-10 h-10 rounded-full border-2 border-gray-200 object-cover">
                        <div class="text-left">
                            <h3 class="font-semibold text-white drop-shadow"><?php echo htmlspecialchars($display_name); ?></h3>
                            <p class="text-sm text-gray-200 drop-shadow">Member</p>
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
            <main class="ml-64 mt-16 p-8" id="mainContent">
                <div class="max-w-7xl mx-auto space-y-8">

            <!-- User Profile and Plan Summary -->
            <div class="bg-gradient-to-br from-red-50 via-white to-red-50 rounded-xl shadow-lg border border-red-200 p-5 mb-6 hover:shadow-xl transition-all duration-300">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                        <div class="relative">
                            <?php if (!empty($user['profile_picture'])): ?>
                                <div class="h-14 w-14 rounded-full overflow-hidden border-3 border-white shadow-md ring-3 ring-red-200">
                                    <img src="../uploads/profile_pictures/<?php echo htmlspecialchars($user['profile_picture']); ?>" 
                                         alt="Profile Picture" 
                                         class="h-full w-full object-cover">
                                </div>
                            <?php else: ?>
                                <div class="h-14 w-14 bg-gradient-to-br from-red-400 to-red-600 rounded-full flex items-center justify-center shadow-md ring-3 ring-red-200">
                                    <span class="text-lg font-bold text-white">
                                        <?php echo strtoupper(substr($user['full_name'] ?? $user['username'] ?? 'U', 0, 1)); ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            <div class="absolute -bottom-1 -right-1 h-5 w-5 bg-green-400 rounded-full border-2 border-white flex items-center justify-center shadow-md">
                                <i class="fas fa-check text-xs text-white"></i>
                            </div>
                        </div>
                        <div>
                            <h2 class="text-lg font-bold text-red-600 mb-1">
                                <?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?>
                            </h2>
                            <p class="text-sm text-gray-600 mb-1 flex items-center">
                                <i class="fas fa-envelope mr-2 text-red-500"></i>
                                <?php echo htmlspecialchars($user['email']); ?>
                            </p>
                            <p class="text-xs text-gray-500 flex items-center">
                                <i class="fas fa-calendar-alt mr-2 text-red-500"></i>
                                Member since: <?php echo date('M d, Y', strtotime($user['created_at'] ?? 'now')); ?>
                            </p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-gray-600 mb-1">Current Plan</p>
                        <p class="text-xl font-bold text-red-600 mb-2">
                            <?php echo htmlspecialchars($user['plan_name'] ?? 'No Plan'); ?>
                        </p>
                        <?php echo getMembershipStatusBadge($user); ?>
                    </div>
                </div>
            </div>

            <!-- Key Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <!-- Membership Status -->
                <div class="bg-white rounded-lg shadow-md border border-gray-200 p-4 hover:shadow-lg transition-all duration-300">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-gradient-to-br from-blue-500 to-blue-600 text-white shadow-md">
                            <i class="fas fa-id-card text-lg"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-xs font-medium text-blue-700">Membership Status</p>
                            <p class="text-lg font-bold text-blue-900"><?php echo htmlspecialchars($user['plan_name'] ?? 'No Plan'); ?></p>
                        </div>
                    </div>
                    <?php if ($user && isset($user['payment_status']) && $user['payment_status'] === 'Approved'): ?>
                        <div class="mt-3 text-center">
                            <?php if ($qr_code_path): ?>
                                <div class="bg-white rounded-lg p-2 shadow-md border border-blue-200">
                                    <img src="<?php echo htmlspecialchars($qr_code_path); ?>" 
                                         alt="Member QR Code" 
                                         class="w-16 h-16 mx-auto">
                                    <p class="text-xs text-blue-700 mt-1 font-medium">Scan for gym access</p>
                                </div>
                            <?php else: ?>
                                <button onclick="generateQRCode()" class="bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white px-3 py-1 rounded text-xs font-medium transition-all duration-300 shadow-sm">
                                    <i class="fas fa-qrcode mr-1"></i> Generate QR Code
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Membership Expires -->
                <div class="bg-white rounded-2xl shadow-xl border border-gray-200 p-6 hover:shadow-2xl transition-all duration-500 transform hover:scale-105">
                    <div class="flex items-center">
                        <div class="p-4 rounded-full bg-gradient-to-br from-green-500 to-green-600 text-white shadow-lg">
                            <i class="fas fa-calendar text-2xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-green-700">Membership Expires</p>
                            <?php if (isset($user['payment_status']) && $user['payment_status'] === 'Approved' && isset($user['membership_end_date']) && $user['membership_end_date']): ?>
                                <?php $days_remaining = getMembershipDaysRemaining($user); ?>
                                <?php if ($days_remaining > 0): ?>
                                    <p class="text-2xl font-bold text-green-900"><?php echo $days_remaining; ?> Days</p>
                                    <p class="text-sm text-green-600">Expires on <?php echo date('M d, Y', strtotime($user['membership_end_date'])); ?></p>
                                <?php else: ?>
                                    <p class="text-2xl font-bold text-red-600">Expired</p>
                                    <p class="text-sm text-red-500">Expired on <?php echo date('M d, Y', strtotime($user['membership_end_date'])); ?></p>
                                <?php endif; ?>
                            <?php elseif (isset($user['payment_status']) && $user['payment_status'] === 'Completed'): ?>
                                <p class="text-2xl font-bold text-yellow-600">Pending Approval</p>
                                <p class="text-sm text-yellow-600">Waiting for admin approval</p>
                            <?php elseif (isset($user['payment_status']) && $user['payment_status'] === 'Pending'): ?>
                                <p class="text-2xl font-bold text-yellow-600">Payment Pending</p>
                                <p class="text-sm text-yellow-600">Complete your payment</p>
                            <?php else: ?>
                                <p class="text-2xl font-bold text-gray-900">No Active Plan</p>
                                <p class="text-sm text-gray-500">Select a membership plan</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Total Paid -->
                <div class="bg-white rounded-2xl shadow-xl border border-gray-200 p-6 hover:shadow-2xl transition-all duration-500 transform hover:scale-105">
                    <div class="flex items-center">
                        <div class="p-4 rounded-full bg-gradient-to-br from-purple-500 to-purple-600 text-white shadow-lg">
                            <i class="fas fa-credit-card text-2xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-purple-700">Total Paid</p>
                            <p class="text-2xl font-bold text-purple-900">₱<?php echo number_format($user['total_paid'] ?? 0, 2); ?></p>
                            <p class="text-sm text-purple-600"><?php echo $user['completed_payments'] ?? 0; ?> payments</p>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="bg-white rounded-2xl shadow-xl border border-gray-200 p-6 hover:shadow-2xl transition-all duration-500 transform hover:scale-105">
                    <div class="flex items-center">
                        <div class="p-4 rounded-full bg-gradient-to-br from-orange-500 to-orange-600 text-white shadow-lg">
                            <i class="fas fa-bolt text-2xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-orange-700">Quick Actions</p>
                            <div class="space-y-3 mt-4">
                                <a href="attendance_history.php" class="text-sm text-blue-600 hover:text-blue-700 block font-bold bg-white px-3 py-2 rounded-lg shadow-sm hover:shadow-md transition-all duration-300">
                                    <i class="fas fa-clock mr-2"></i>View Attendance
                                </a>
                                <a href="trainers.php" class="text-sm text-green-600 hover:text-green-700 block font-bold bg-white px-3 py-2 rounded-lg shadow-sm hover:shadow-md transition-all duration-300">
                                    <i class="fas fa-users mr-2"></i>Find Trainers
                                </a>
                                <button onclick="openRecommendationsModal()" class="text-sm text-purple-600 hover:text-purple-700 block font-bold bg-white px-3 py-2 rounded-lg shadow-sm hover:shadow-md transition-all duration-300 w-full text-left">
                                    <i class="fas fa-dumbbell mr-2"></i>Get Recommendations
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
                
            <!-- Personal Insights Section -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <!-- Recent Activity -->
                <div class="bg-white rounded-2xl shadow-xl border border-gray-200 p-6 hover:shadow-2xl transition-all duration-500 transform hover:scale-105">
                    <div class="flex items-center mb-5">
                        <div class="p-4 rounded-full bg-gradient-to-br from-blue-500 to-blue-600 text-white shadow-lg mr-4">
                            <i class="fas fa-clock text-xl"></i>
                        </div>
                        <h3 class="text-lg font-bold text-blue-900">Recent Activity</h3>
                    </div>
                    <div class="space-y-4">
                        <div class="p-4 bg-white rounded-xl shadow-sm border border-blue-100">
                            <div class="flex items-center justify-between mb-2">
                                <div class="flex items-center">
                                    <i class="fas fa-calendar-check text-blue-500 mr-3"></i>
                                    <span class="text-sm font-semibold text-blue-900">Last Visit</span>
                                </div>
                                <span class="text-sm font-bold text-blue-700">
                                    <?php echo isset($user['last_visit']) ? date('M d', strtotime($user['last_visit'])) : 'Never'; ?>
                                </span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div class="bg-gradient-to-r from-blue-400 to-blue-600 h-2 rounded-full" style="width: <?php echo isset($user['last_visit']) ? '100%' : '0%'; ?>"></div>
                            </div>
                        </div>
                        <div class="p-4 bg-white rounded-xl shadow-sm border border-blue-100">
                            <div class="flex items-center justify-between mb-2">
                                <div class="flex items-center">
                                    <i class="fas fa-calendar-week text-blue-500 mr-3"></i>
                                    <span class="text-sm font-semibold text-blue-900">This Week</span>
                                </div>
                                <span class="text-sm font-bold text-blue-700"><?php echo $user['this_week_visits'] ?? 0; ?> visits</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div class="bg-gradient-to-r from-blue-400 to-blue-600 h-2 rounded-full" style="width: <?php echo min(100, (($user['this_week_visits'] ?? 0) / 7) * 100); ?>%"></div>
                            </div>
                        </div>
                        <div class="p-4 bg-white rounded-xl shadow-sm border border-blue-100">
                            <div class="flex items-center justify-between mb-2">
                                <div class="flex items-center">
                                    <i class="fas fa-calendar-alt text-blue-500 mr-3"></i>
                                    <span class="text-sm font-semibold text-blue-900">This Month</span>
                                </div>
                                <span class="text-sm font-bold text-blue-700"><?php echo $user['this_month_visits'] ?? 0; ?> visits</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div class="bg-gradient-to-r from-blue-400 to-blue-600 h-2 rounded-full" style="width: <?php echo min(100, (($user['this_month_visits'] ?? 0) / 30) * 100); ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Fitness Goals -->
                <div class="bg-white rounded-2xl shadow-xl border border-gray-200 p-6 hover:shadow-2xl transition-all duration-500 transform hover:scale-105">
                    <div class="flex items-center mb-5">
                        <div class="p-4 rounded-full bg-gradient-to-br from-green-500 to-green-600 text-white shadow-lg mr-4">
                            <i class="fas fa-target text-xl"></i>
                        </div>
                        <h3 class="text-lg font-bold text-green-900">Fitness Goals</h3>
                    </div>
                    <div class="space-y-4">
                        <div class="p-4 bg-white rounded-xl shadow-sm border border-green-100">
                            <div class="flex items-center justify-between mb-2">
                                <div class="flex items-center">
                                    <i class="fas fa-calendar-week text-green-500 mr-3"></i>
                                    <span class="text-sm font-semibold text-green-900">Weekly Visits</span>
                                </div>
                                <span class="text-sm font-bold text-green-700"><?php echo $user['this_week_visits'] ?? 0; ?> / 3</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div class="bg-gradient-to-r from-green-400 to-green-600 h-2 rounded-full" style="width: <?php echo min(100, (($user['this_week_visits'] ?? 0) / 3) * 100); ?>%"></div>
                            </div>
                        </div>
                        <div class="p-4 bg-white rounded-xl shadow-sm border border-green-100">
                            <div class="flex items-center justify-between mb-2">
                                <div class="flex items-center">
                                    <i class="fas fa-calendar-alt text-green-500 mr-3"></i>
                                    <span class="text-sm font-semibold text-green-900">Monthly Goal</span>
                                </div>
                                <span class="text-sm font-bold text-green-700"><?php echo $user['this_month_visits'] ?? 0; ?> / 12</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div class="bg-gradient-to-r from-green-400 to-green-600 h-2 rounded-full" style="width: <?php echo min(100, (($user['this_month_visits'] ?? 0) / 12) * 100); ?>%"></div>
                            </div>
                        </div>
                        <div class="p-4 bg-white rounded-xl shadow-sm border border-green-100">
                            <div class="flex items-center justify-between mb-2">
                                <div class="flex items-center">
                                    <i class="fas fa-chart-line text-green-500 mr-3"></i>
                                    <span class="text-sm font-semibold text-green-900">Consistency</span>
                                </div>
                                <span class="text-sm font-bold text-green-700">0%</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div class="bg-gradient-to-r from-green-400 to-green-600 h-2 rounded-full" style="width: 0%"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Personalized Insights -->
                <div class="bg-white rounded-2xl shadow-xl border border-gray-200 p-6 hover:shadow-2xl transition-all duration-500 transform hover:scale-105">
                    <div class="flex items-center mb-5">
                        <div class="p-4 rounded-full bg-gradient-to-br from-purple-500 to-purple-600 text-white shadow-lg mr-4">
                            <i class="fas fa-lightbulb text-xl"></i>
                        </div>
                        <h3 class="text-lg font-bold text-purple-900">Smart Insights</h3>
                    </div>
                    <div class="space-y-4">
                        <?php
                        $insights = [];
                        
                        if (isset($user['days_since_last_visit']) && $user['days_since_last_visit'] > 7) {
                            $insights[] = [
                                'icon' => 'fa-calendar-alt',
                                'color' => 'yellow',
                                'message' => 'Time to get back to the gym!'
                            ];
                        }
                        
                        if (isset($user['visit_frequency']) && $user['visit_frequency'] >= 12) {
                            $insights[] = [
                                'icon' => 'fa-star',
                                'color' => 'green',
                                'message' => 'You\'re doing great!'
                            ];
                        }
                        
                        if (isset($user['churn_probability']) && $user['churn_probability'] > 0.5) {
                            $insights[] = [
                                'icon' => 'fa-user-clock',
                                'color' => 'red',
                                'message' => 'We miss you!'
                            ];
                        }
                        
                        if (empty($insights)) {
                            $insights[] = [
                                'icon' => 'fa-heart',
                                'color' => 'blue',
                                'message' => 'Welcome to your fitness journey!'
                            ];
                        }
                        
                        foreach ($insights as $insight):
                        ?>
                        <div class="flex items-start p-4 bg-white rounded-xl shadow-sm border border-purple-100">
                            <i class="fas <?php echo $insight['icon']; ?> text-<?php echo $insight['color']; ?>-500 mt-1 mr-3 text-lg"></i>
                            <p class="text-sm text-gray-700 font-semibold"><?php echo $insight['message']; ?></p>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Equipment Usage and Announcements Section -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Announcements -->
                <div class="bg-white rounded-2xl shadow-xl border border-gray-200 p-6 hover:shadow-2xl transition-all duration-500 transform hover:scale-105">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-semibold text-gray-800 flex items-center">
                            <i class="fas fa-bullhorn text-gray-600 mr-3"></i>Latest Announcements
                        </h2>
                    </div>
                    
                    <!-- Scrollable Announcements Container -->
                    <div class="announcements-container overflow-y-auto" style="max-height: 350px;">
                        <div class="space-y-4 pr-3">
                            <?php
                            // Debug information
                            if ($error_message) {
                                echo '<div class="text-red-500 mb-4">' . htmlspecialchars($error_message) . '</div>';
                            }
                            
                            // Direct database query for announcements
                            $announcements = [];
                            $announcements_sql = "SELECT * FROM announcements ORDER BY created_at DESC";
                            $announcements_result = $conn->query($announcements_sql);
                            
                            if ($announcements_result) {
                                while ($row = $announcements_result->fetch_assoc()) {
                                    $announcements[] = $row;
                                }
                            } else {
                                echo '<div class="text-red-500 mb-4">Error: ' . htmlspecialchars($conn->error) . '</div>';
                            }
                            
                            if (!empty($announcements)): ?>
                                <?php foreach($announcements as $announcement): ?>
                                    <div class="announcement-card border-b border-orange-200 pb-4 last:border-b-0 last:pb-0 hover:bg-white rounded-xl p-4 transition-all duration-200 shadow-sm">
                                        <div class="flex items-center justify-between mb-3">
                                            <h3 class="text-lg font-semibold text-orange-900"><?php echo htmlspecialchars($announcement['title']); ?></h3>
                                            <span class="text-sm text-orange-600 bg-orange-100 px-3 py-1 rounded-full font-medium">
                                                <?php echo date('M j, Y', strtotime($announcement['created_at'])); ?>
                                            </span>
                                        </div>
                                        <p class="text-gray-700 leading-relaxed"><?php echo nl2br(htmlspecialchars($announcement['content'])); ?></p>
                                        <?php if(isset($announcement['updated_at']) && $announcement['updated_at'] && $announcement['updated_at'] != $announcement['created_at']): ?>
                                            <div class="mt-3 text-sm text-orange-600 flex items-center">
                                                <i class="far fa-edit mr-2"></i> Updated <?php echo date('M j, Y', strtotime($announcement['updated_at'])); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-8">
                                    <div class="text-orange-300 mb-4">
                                        <i class="fas fa-bullhorn text-4xl"></i>
                                    </div>
                                    <p class="text-orange-600 font-medium">No announcements at this time.</p>
                                    <p class="text-sm text-orange-500 mt-2">Check back later for updates!</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <style>
                    /* Custom scrollbar styles */
                    .announcements-container::-webkit-scrollbar {
                        width: 6px;
                    }
                    
                    .announcements-container::-webkit-scrollbar-track {
                        background: #f1f1f1;
                        border-radius: 3px;
                    }
                    
                    .announcements-container::-webkit-scrollbar-thumb {
                        background: #cbd5e0;
                        border-radius: 3px;
                        transition: background 0.2s;
                    }
                    
                    .announcements-container::-webkit-scrollbar-thumb:hover {
                        background: #a0aec0;
                    }

                    /* Responsive height adjustments */
                    @media (max-width: 768px) {
                        .announcements-container {
                            max-height: 300px;
                        }
                    }

                    /* Announcement card hover effect */
                    .announcement-card {
                        transition: all 0.2s ease;
                    }

                    .announcement-card:hover {
                        transform: translateY(-1px);
                    }

                    /* Ensure the last announcement doesn't have a border */
                    .announcement-card:last-child {
                        border-bottom: none;
                        margin-bottom: 0;
                    }
                    
                    /* Equipment grid styling */
                    .equipment-grid {
                        display: grid;
                        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
                        gap: 1rem;
                        max-height: 400px;
                        overflow-y: auto;
                    }
                    
                    .equipment-grid::-webkit-scrollbar {
                        width: 6px;
                    }
                    .equipment-grid::-webkit-scrollbar-track {
                        background: #f1f1f1;
                        border-radius: 3px;
                    }
                    .equipment-grid::-webkit-scrollbar-thumb {
                        background: #c1c1c1;
                        border-radius: 3px;
                    }
                    .equipment-grid::-webkit-scrollbar-thumb:hover {
                        background: #a8a8a8;
                    }
                    
                    /* Enhanced card animations */
                    .equipment-card:hover {
                        transform: translateY(-2px);
                        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
                    }
                    
                    /* Gradient text effects */
                    .gradient-text {
                        background: linear-gradient(45deg, #667eea 0%, #764ba2 100%);
                        -webkit-background-clip: text;
                        -webkit-text-fill-color: transparent;
                        background-clip: text;
                    }
                </style>

                <!-- Equipment Usage -->
                <div class="bg-white rounded-2xl shadow-xl border border-gray-200 p-6 hover:shadow-2xl transition-all duration-500 transform hover:scale-105">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-lg font-semibold text-gray-800 flex items-center">
                            <i class="fas fa-dumbbell text-gray-600 mr-3"></i>Equipment Usage
                        </h2>
                        <a href="equipment.php" class="text-sm text-gray-600 hover:text-gray-700 transition-colors duration-200 font-medium">View All</a>
                    </div>

                    <?php
                    // Get available equipment with quantities
                    $equipment_sql = "SELECT e.*, 
                                           COUNT(eu.id) as current_usage,
                                           ec.name as category_name
                                    FROM equipment e
                                    LEFT JOIN equipment_usage eu ON e.id = eu.equipment_id 
                                        AND eu.end_time IS NULL
                                    LEFT JOIN equipment_categories ec ON e.category_id = ec.id
                                    WHERE e.status != 'Maintenance'
                                    GROUP BY e.id
                                    ORDER BY e.category, e.name
                                    LIMIT 20";
                    $equipment_result = $conn->query($equipment_sql);
                    $available_equipment = [];
                    if ($equipment_result) {
                        while ($row = $equipment_result->fetch_assoc()) {
                            $available_equipment[] = $row;
                        }
                    }
                    ?>

                    <?php if ($available_equipment && count($available_equipment) > 0): ?>
                        <!-- Fixed Height, Scrollable Equipment Panel -->
                        <div class="equipment-panel-container">
                            <div class="equipment-grid">
                                <?php foreach ($available_equipment as $equipment): ?>
                                <div class="equipment-card bg-white rounded-xl shadow-sm p-4 hover:shadow-md transition-all duration-200 border border-gray-100">
                                    <div class="flex flex-col justify-between h-full">
                                        <div>
                                            <div class="flex items-center justify-between mb-3">
                                                <div class="flex-1 min-w-0 pr-3">
                                                    <h3 class="text-gray-900 font-semibold truncate"><?php echo htmlspecialchars($equipment['name']); ?></h3>
                                                    <span class="text-sm text-red-600 font-medium block truncate"><?php echo htmlspecialchars($equipment['category_name'] ?? $equipment['category']); ?></span>
                                                </div>
                                                <span class="flex-shrink-0 inline-flex items-center px-3 py-1.5 rounded-full text-sm font-bold <?php 
                                                    $available = $equipment['quantity'] - ($equipment['current_usage'] ?? 0);
                                                    if ($available <= 0) {
                                                        echo 'bg-red-100 text-red-800';
                                                    } elseif ($available <= 2) {
                                                        echo 'bg-yellow-100 text-yellow-800';
                                                    } else {
                                                        echo 'bg-green-100 text-green-800';
                                                    }
                                                ?>">
                                                    <?php echo $available; ?> available
                                                </span>
                                            </div>
                                            <?php if (!empty($equipment['description'])): ?>
                                            <p class="text-sm text-gray-600 line-clamp-2 mb-3 leading-relaxed"><?php echo htmlspecialchars($equipment['description']); ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex items-center justify-between pt-3 border-t border-gray-100">
                                            <span class="text-xs text-gray-500 font-medium">
                                                Total: <?php echo $equipment['quantity']; ?> units
                                            </span>
                                            <span class="text-xs text-gray-500 font-medium">
                                                <?php echo $equipment['status']; ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <?php if (isset($user['recent_equipment_usage']) && count($user['recent_equipment_usage']) > 0): ?>
                        <!-- Recent Usage Section -->
                        <div class="mt-6 pt-6 border-t border-red-200">
                            <h3 class="text-sm font-semibold text-red-900 mb-4 flex items-center">
                                <i class="fas fa-history mr-2"></i>Your Recent Usage
                            </h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <?php foreach (array_slice($user['recent_equipment_usage'], 0, 4) as $usage): ?>
                                <div class="flex items-center justify-between bg-white rounded-xl p-4 shadow-sm border border-red-100">
                                    <div class="flex flex-col">
                                        <span class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($usage['equipment_name']); ?></span>
                                        <span class="text-xs text-red-600 font-medium">
                                            <?php echo date('M d, g:i A', strtotime($usage['usage_date'])); ?>
                                        </span>
                                    </div>
                                    <span class="text-sm text-red-600 font-bold">
                                        <?php echo isset($usage['duration']) ? round($usage['duration']) . ' mins' : ''; ?>
                                    </span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                    <?php else: ?>
                        <div class="text-center py-8">
                            <div class="text-gray-400 mb-2">
                                <i class="fas fa-dumbbell text-4xl"></i>
                            </div>
                            <p class="text-gray-500">No equipment data available.</p>
                        </div>
                    <?php endif; ?>
                </div>
                </div>

            <!-- Trainers and Smart Recommendations Section -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mt-8 mb-8">
                <!-- Featured Trainers Section -->
                <div class="bg-white rounded-2xl shadow-xl border border-gray-200 p-6 hover:shadow-2xl transition-all duration-500 transform hover:scale-105">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-xl font-bold text-gray-800 flex items-center">
                            <i class="fas fa-users text-purple-600 mr-3 text-xl"></i>Featured Trainers
                        </h2>
                        <a href="trainers.php" class="text-sm text-purple-600 hover:text-purple-700 transition-colors duration-200 font-medium">View All</a>
                    </div>
                    <div class="grid grid-cols-1 gap-4">
                    <?php if (!empty($trainers)): ?>
                        <?php foreach($trainers as $trainer): ?>
                        <div class="bg-gradient-to-r from-gray-50 to-gray-100 rounded-xl p-4 hover:from-gray-100 hover:to-gray-200 transition-all duration-300 border border-gray-200">
                            <div class="flex items-center space-x-3">
                                <!-- Trainer Image -->
                                <div class="flex-shrink-0">
                                    <div class="w-20 h-20 bg-gradient-to-br from-gray-200 to-gray-300 rounded-full overflow-hidden shadow-md border-2 border-white">
                                        <?php
                                        $trainer_image = !empty($trainer['image_url']) 
                                            ? "../" . $trainer['image_url']
                                            : "../image/almo.jpg";
                                        ?>
                                        <img src="<?php echo htmlspecialchars($trainer_image); ?>" 
                                             alt="<?php echo htmlspecialchars($trainer['name']); ?>" 
                                             class="w-full h-full object-cover"
                                             onerror="this.src='../image/almo.jpg';">
                                    </div>
                                </div>
                                
                                <!-- Trainer Info -->
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <h3 class="text-sm font-semibold text-gray-800 truncate">
                                                <?php echo htmlspecialchars($trainer['name']); ?>
                                            </h3>
                                            <p class="text-xs text-red-600 font-medium">
                                                <?php echo htmlspecialchars($trainer['specialization'] ?? 'General Fitness'); ?>
                                            </p>
                                        </div>
                                        
                                        <!-- Status Badge -->
                                        <?php
                                        $status = $trainer['status'] ?? 'inactive';
                                        $status_bg = match($status) {
                                            'active' => 'bg-green-100 text-green-800',
                                            'on_leave' => 'bg-orange-100 text-orange-800',
                                            'inactive', null => 'bg-red-100 text-red-800',
                                            default => 'bg-red-100 text-red-800'
                                        };
                                        ?>
                                        <div class="px-2 py-1 rounded-full text-xs font-medium <?php echo $status_bg; ?>">
                                            <?php echo ucfirst($status); ?>
                                        </div>
                                    </div>
                                    
                                    <!-- Rating and Experience -->
                                    <div class="flex items-center space-x-4 mt-1">
                                        <div class="flex items-center">
                                            <i class="fas fa-star text-yellow-400 text-xs mr-1"></i>
                                            <span class="text-xs font-medium">
                                                <?php echo number_format($trainer['avg_rating'] ?? 0, 1); ?>
                                            </span>
                                        </div>
                                        <div class="flex items-center text-gray-600">
                                            <i class="fas fa-briefcase text-xs mr-1"></i>
                                            <span class="text-xs"><?php echo htmlspecialchars($trainer['experience_years'] ?? '0'); ?>y</span>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- View Profile Button -->
                                <div class="flex-shrink-0">
                                    <a href="trainers.php?id=<?php echo $trainer['id']; ?>" 
                                       class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-red-500 to-red-600 text-white rounded-lg hover:from-red-600 hover:to-red-700 transition-all duration-300 text-sm font-medium shadow-md">
                                        <i class="fas fa-eye mr-2"></i>
                                        View
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <div class="text-gray-400 mb-2">
                                <i class="fas fa-users text-2xl"></i>
                            </div>
                            <p class="text-gray-500 text-sm">No trainers available.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Smart Recommendations Section -->
            <div class="bg-white rounded-2xl shadow-xl border border-gray-200 p-6 hover:shadow-2xl transition-all duration-500 transform hover:scale-105">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold text-gray-800 flex items-center">
                        <i class="fas fa-chart-line text-red-600 mr-3 text-xl"></i>Smart Recommendations
                    </h2>
                    <span class="px-4 py-2 bg-gradient-to-r from-red-500 to-red-600 text-white text-sm font-bold rounded-full shadow-md">
                        Personalized
                    </span>
                </div>

                <div class="grid grid-cols-1 gap-5">
                    <!-- Recommended Workout -->
                    <div class="bg-gradient-to-r from-gray-50 to-gray-100 rounded-xl p-5 border border-gray-200 hover:shadow-md transition-all duration-300">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="font-medium text-gray-800">
                                <i class="fas fa-dumbbell text-red-500 mr-2"></i>Recommended Workout
                            </h3>
                            <span class="text-sm text-gray-600">
                                <?php 
                                    $workout_days = ['Monday', 'Wednesday', 'Friday'];
                                    $workout_times = ['6:00 AM', '5:00 PM', '7:00 PM'];
                                    $random_day = $workout_days[array_rand($workout_days)];
                                    $random_time = $workout_times[array_rand($workout_times)];
                                    echo $random_day . ' at ' . $random_time;
                                ?>
                            </span>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                Cardio
                            </span>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                Strength Training
                            </span>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                Flexibility
                            </span>
                        </div>
                    </div>

                    <!-- Fitness Goals -->
                    <div class="bg-gradient-to-r from-gray-50 to-gray-100 rounded-xl p-5 border border-gray-200 hover:shadow-md transition-all duration-300">
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="font-medium text-gray-800">
                                <i class="fas fa-bullseye text-red-500 mr-2"></i>Fitness Goals
                            </h3>
                            <div class="flex items-center">
                                <span class="text-sm text-gray-600">
                                    <?php 
                                        $goals = ['Weight Loss', 'Muscle Gain', 'General Fitness', 'Endurance'];
                                        echo $goals[array_rand($goals)];
                                    ?>
                                </span>
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                Consistency is Key
                            </span>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                Stay Hydrated
                            </span>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                Rest Days Important
                            </span>
                        </div>
                        <div class="mt-3">
                            <p class="text-sm text-gray-600">
                                <i class="fas fa-chart-line text-green-500 mr-1"></i>
                                Track your progress weekly for best results
                            </p>
                        </div>
                    </div>

                    <!-- Smart Tips -->
                    <div class="bg-gradient-to-r from-red-50 to-red-100 rounded-xl p-5 border border-red-200 hover:shadow-md transition-all duration-300">
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="font-medium text-red-800">
                                <i class="fas fa-lightbulb text-red-500 mr-2"></i>Smart Tips
                            </h3>
                        </div>
                        <div class="text-sm text-red-700">
                            <p class="font-medium">💡 Today's Tip</p>
                            <p class="mt-1">
                                <?php 
                                    $tips = [
                                        "Warm up for 10 minutes before your workout to prevent injuries",
                                        "Stay consistent with your workout schedule for better results",
                                        "Don't forget to stretch after your workout session",
                                        "Mix cardio and strength training for balanced fitness",
                                        "Listen to your body and take rest when needed"
                                    ];
                                    echo $tips[array_rand($tips)];
                                ?>
                            </p>
                            <div class="mt-3 flex items-center text-xs text-red-600">
                                <i class="fas fa-clock mr-1"></i>
                                <span>Updated daily</span>
                            </div>
                        </div>
                    </div>


                </div>
            </div>

            <!-- Recent Feedback Section -->
            <?php include 'recent_feedback.php'; ?>

            <!-- Feedback Modal -->
            <div id="feedbackModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
                <div class="bg-white rounded-xl p-8 max-w-md w-full mx-4 relative">
                    <h3 class="text-xl font-semibold text-gray-800 mb-6">Submit Feedback</h3>
                    <form id="feedbackForm" class="space-y-6">
                        <!-- Trainer Selection -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Select Trainer</label>
                            <select name="trainer_id" required class="w-full rounded-lg border-gray-300 focus:border-purple-500 focus:ring-purple-500 shadow-sm">
                                <option value="">Select a trainer</option>
                                <?php
                                $trainers_sql = "SELECT id, name FROM trainers ORDER BY name";
                                $trainers_result = $conn->query($trainers_sql);
                                if ($trainers_result) {
                                    while ($trainer = $trainers_result->fetch_assoc()) {
                                        echo '<option value="' . $trainer['id'] . '">' . htmlspecialchars($trainer['name']) . '</option>';
                                    }
                                }
                                ?>
                            </select>
                        </div>
                        
                        <!-- Rating -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Rating</label>
                            <div class="flex gap-3">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                <button type="button" class="rating-star text-2xl text-gray-300 hover:text-yellow-400 transition-colors" data-rating="<?php echo $i; ?>">
                                    ★
                                </button>
                                <?php endfor; ?>
                            </div>
                            <input type="hidden" name="rating" value="" required>
                        </div>
                        
                        <!-- Comment -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Comment</label>
                            <textarea name="comment" rows="4" class="w-full rounded-lg border-gray-300 focus:border-purple-500 focus:ring-purple-500 shadow-sm"></textarea>
                        </div>
                        
                        <!-- Buttons -->
                        <div class="flex justify-end space-x-3">
                            <button type="button" onclick="closeFeedbackModal()" 
                                    class="px-4 py-2 text-gray-600 hover:text-gray-700 font-medium transition-colors">
                                Cancel
                            </button>
                            <button type="submit" 
                                    class="px-4 py-2 bg-purple-500 text-white rounded-lg hover:bg-purple-600 font-medium transition-colors">
                                Submit
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <style>
                /* Custom scrollbar styles */
                .feedback-container::-webkit-scrollbar {
                    width: 6px;
                }
                
                .feedback-container::-webkit-scrollbar-track {
                    background: #f1f1f1;
                    border-radius: 3px;
                }
                
                .feedback-container::-webkit-scrollbar-thumb {
                    background: #cbd5e0;
                    border-radius: 3px;
                    transition: background 0.2s;
                }
                
                .feedback-container::-webkit-scrollbar-thumb:hover {
                    background: #a0aec0;
                }
            </style>

            <script>
                // Feedback Modal Functions
                function openFeedbackModal() {
                    const modal = document.getElementById('feedbackModal');
                    modal.classList.remove('hidden');
                    modal.classList.add('flex');
                }

                function closeFeedbackModal() {
                    const modal = document.getElementById('feedbackModal');
                    modal.classList.remove('flex');
                    modal.classList.add('hidden');
                    // Reset form
                    document.getElementById('feedbackForm').reset();
                    document.querySelectorAll('.rating-star').forEach(star => {
                        star.classList.remove('text-yellow-400');
                        star.classList.add('text-gray-300');
                    });
                    document.querySelector('input[name="rating"]').value = '';
                }

                // Rating Stars Functionality
                document.querySelectorAll('.rating-star').forEach(star => {
                    star.addEventListener('click', function() {
                        const rating = this.dataset.rating;
                        document.querySelector('input[name="rating"]').value = rating;
                        
                        // Update stars visual
                        document.querySelectorAll('.rating-star').forEach(s => {
                            if (s.dataset.rating <= rating) {
                                s.classList.remove('text-gray-300');
                                s.classList.add('text-yellow-400');
                            } else {
                                s.classList.remove('text-yellow-400');
                                s.classList.add('text-gray-300');
                            }
                        });
                    });
                });

                // Handle Form Submission
                document.getElementById('feedbackForm').addEventListener('submit', async function(e) {
                    e.preventDefault();
                    
                    const formData = new FormData(this);
                    try {
                        const response = await fetch('submit_feedback.php', {
                            method: 'POST',
                            body: formData
                        });
                        
                        const data = await response.json();
                        if (data.error) {
                            alert(data.error);
                        } else {
                            closeFeedbackModal();
                            alert('Thank you for your feedback!');
                            // Reload page to show new feedback
                            window.location.reload();
                        }
                    } catch (error) {
                        console.error('Error:', error);
                        alert('An error occurred while submitting feedback. Please try again.');
                    }
                });
            </script>
        </div>
    </main>

    <!-- Mobile Menu Button -->
    <button class="fixed lg:hidden bottom-4 right-4 bg-gray-900 text-white p-3 rounded-full shadow-lg z-50">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Chatbot Component -->
    <div id="chatbot" class="fixed bottom-6 right-6 z-50">
        <!-- Chat Button -->
        <button id="chatbotToggle" class="bg-red-600 hover:bg-red-700 text-white w-14 h-14 rounded-full shadow-lg flex items-center justify-center transition-all duration-300 hover:scale-110">
            <i id="chatbotIcon" class="fas fa-comments text-xl"></i>
        </button>
        
        <!-- Chat Window -->
        <div id="chatbotWindow" class="absolute bottom-16 right-0 w-96 bg-white rounded-xl shadow-2xl border border-gray-200 hidden transform transition-all duration-300">
            <!-- Chat Header -->
            <div class="bg-red-600 text-white px-6 py-4 rounded-t-xl flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-white rounded-full flex items-center justify-center">
                        <i class="fas fa-dumbbell text-red-600 text-lg"></i>
                    </div>
                    <div>
                        <h3 class="font-semibold text-lg">FitTracker Assistant</h3>
                        <p class="text-sm text-red-100">Online</p>
                    </div>
                </div>
                <button id="chatbotClose" class="text-white hover:text-red-100 transition-colors p-2">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <!-- Chat Messages -->
            <div id="chatMessages" class="h-96 overflow-y-auto p-6 space-y-4">
                <!-- Welcome Message -->
                <div class="flex items-start gap-3">
                    <div class="w-8 h-8 bg-red-100 rounded-full flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-dumbbell text-red-600 text-sm"></i>
                    </div>
                    <div class="bg-gray-100 rounded-lg px-4 py-3 max-w-[85%]">
                        <p class="text-gray-800">Hi! I'm your FitTracker assistant. How can I help you today?</p>
                    </div>
                </div>
            </div>
            
            <!-- Chat Input -->
            <div class="border-t border-gray-200 p-4">
                <div class="flex gap-2">
                    <input type="text" id="chatInput" placeholder="Type your message..." 
                           class="flex-1 px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500 text-sm shadow-sm">
                    <button id="chatSend" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors flex items-center justify-center">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
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

        // QR Code Generation using Online APIs
        async function generateQRCode() {
            try {
                const response = await fetch('generate_qr_online.php', {
                    method: 'POST'
                });
                const data = await response.json();
                
                if (data.success) {
                    // Show success message with API info
                    alert('QR Code generated successfully using ' + (data.api_used || 'online API') + '!');
                    // Reload the page to show the new QR code
                    window.location.reload();
                } else {
                    alert('Error generating QR code: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error generating QR code. Please try again.');
            }
        }

        // Training Session Cancellation
        async function cancelSession(sessionId) {
            if (!confirm('Are you sure you want to cancel this training session?')) {
                return;
            }

            try {
                const response = await fetch('cancel_session.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `session_id=${sessionId}`
                });

                const data = await response.json();

                if (data.success) {
                    // Reload the page to show updated session status
                    window.location.reload();
                } else {
                    alert('Error canceling session: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error canceling session. Please try again.');
            }
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', (e) => {
            if (!profileDropdown.contains(e.target)) {
                profileMenu.classList.add('opacity-0', 'invisible', 'scale-95');
                dropdownArrow.classList.remove('rotate-180');
            }
        });


    // Sidebar Toggle with Content Centering
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const toggleIcon = sidebarToggle.querySelector('i');
    const mainContent = document.getElementById('mainContent');

    sidebarToggle.addEventListener('click', () => {
        sidebar.classList.toggle('w-64');
        sidebar.classList.toggle('w-20');
        toggleIcon.classList.toggle('rotate-180');
        
        // Toggle visibility of text elements
        document.querySelectorAll('.sidebar-logo-text, nav span, .sidebar-bottom-text').forEach(el => {
            el.classList.toggle('hidden');
        });

        // Adjust main content margin for centering
        if (sidebar.classList.contains('w-20')) {
            mainContent.style.marginLeft = '5rem'; // 80px for w-20
            mainContent.style.transition = 'margin-left 0.3s ease';
        } else {
            mainContent.style.marginLeft = '16rem'; // 256px for w-64
            mainContent.style.transition = 'margin-left 0.3s ease';
        }
    });

    // Chatbot functionality
    document.addEventListener('DOMContentLoaded', function() {
        const chatbotToggle = document.getElementById('chatbotToggle');
        const chatbotWindow = document.getElementById('chatbotWindow');
        const chatbotClose = document.getElementById('chatbotClose');
        const chatbotIcon = document.getElementById('chatbotIcon');
        const chatMessages = document.getElementById('chatMessages');
        const chatInput = document.getElementById('chatInput');
        const chatSend = document.getElementById('chatSend');
        
        let isChatOpen = false;
        let messages = [
            { sender: 'bot', text: "Hi! I'm your FitTracker assistant. How can I help you today?" }
        ];

        function renderMessages() {
            chatMessages.innerHTML = '';
            messages.forEach(msg => {
                if (msg.sender === 'bot') {
                    chatMessages.innerHTML += `
                        <div class="flex items-start gap-3">
                            <div class="w-8 h-8 bg-red-100 rounded-full flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-dumbbell text-red-600 text-sm"></i>
                            </div>
                            <div class="bg-gray-100 rounded-lg px-4 py-3 max-w-[85%]">
                                <p class="text-gray-800">${msg.text}</p>
                            </div>
                        </div>
                    `;
                } else {
                    chatMessages.innerHTML += `
                        <div class="flex items-end gap-3 justify-end">
                            <div class="bg-red-600 text-white rounded-lg px-4 py-3 max-w-[85%]">
                                <p>${msg.text}</p>
                            </div>
                            <div class="w-8 h-8 bg-red-100 rounded-full flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-user text-red-600 text-sm"></i>
                            </div>
                        </div>
                    `;
                }
            });
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }

        function getBotResponse(userMsg) {
            const msg = userMsg.toLowerCase();
            if (msg.includes('membership')) {
                return 'You can view and manage your membership in the Membership section. Need help with renewals or plan details?';
            } else if (msg.includes('schedule') || msg.includes('hour')) {
                return 'Our gym is open from 6am to 10pm daily. You can view class and trainer schedules in the Schedule section.';
            } else if (msg.includes('contact')) {
                return 'You can contact us at (123) 456-7890 or email support@almo-fitness.com.';
            } else if (msg.includes('payment')) {
                return 'You can check your payment status and history in the Payments section. Let me know if you need help with a specific payment.';
            } else if (msg.includes('attendance')) {
                return 'Attendance records are available in your dashboard. If you have questions about your attendance, let us know!';
            } else if (msg.includes('hello') || msg.includes('hi')) {
                return 'Hello! How can I assist you today?';
            } else if (msg.includes('help')) {
                return 'I can help you with membership, schedule, payments, attendance, and more. Just ask!';
            } else {
                return 'Sorry, I did not understand that. Please try asking about membership, schedule, contact, payment, or attendance.';
            }
        }

        function toggleChat() {
            isChatOpen = !isChatOpen;
            if (isChatOpen) {
                chatbotWindow.classList.remove('hidden');
                chatbotIcon.className = 'fas fa-times text-xl';
                chatInput.focus();
                renderMessages();
            } else {
                chatbotWindow.classList.add('hidden');
                chatbotIcon.className = 'fas fa-comments text-xl';
            }
        }

        function sendMessage() {
            const userMsg = chatInput.value.trim();
            if (!userMsg) return;
            messages.push({ sender: 'user', text: userMsg });
            renderMessages();
            chatInput.value = '';
            setTimeout(() => {
                const botReply = getBotResponse(userMsg);
                messages.push({ sender: 'bot', text: botReply });
                renderMessages();
            }, 500);
        }

        chatbotToggle.addEventListener('click', toggleChat);
        chatbotClose.addEventListener('click', toggleChat);
        chatSend.addEventListener('click', sendMessage);
        chatInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                sendMessage();
            }
        });
    });
    </script>
    <?php include 'recommendations_modal.php'; ?>
</body>
</html>