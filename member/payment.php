<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: member_login.php");
    exit();
}

// Database connection
require_once '../db.php';

// Get user information from database
$user_id = $_SESSION['user_id'];

// Initialize default values
$user = [
    'balance' => 0.00,
    'freeze_credits' => 2,
    'membership_type' => 'No Active Plan',
    'membership_end_date' => null,
    'payment_status' => 'pending',
    'selected_plan_id' => null,
    'last_payment_date' => null,
    'profile_picture' => null
];

// Get user details including balance, freeze credits, and selected plan
$sql = "SELECT u.*, mp.name as membership_type, mp.duration, mp.price,
        selected_mp.name as selected_plan_name, selected_mp.price as selected_plan_price,
        selected_mp.duration as selected_plan_duration
        FROM users u 
        LEFT JOIN membership_plans mp ON u.membership_plan_id = mp.id 
        LEFT JOIN membership_plans selected_mp ON u.selected_plan_id = selected_mp.id
        WHERE u.id = ?";

$stmt = $conn->prepare($sql);
if ($stmt === false) {
    die("Error preparing statement: " . $conn->error);
}

$stmt->bind_param("i", $user_id);
if ($stmt->execute()) {
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $user = array_merge($user, $row);
    }
}
$stmt->close();

// Get latest approved payment for the user
$latest_payment_sql = "SELECT * FROM payment_history WHERE user_id = ? AND payment_status = 'Approved' ORDER BY payment_date DESC LIMIT 1";
$latest_payment_stmt = $conn->prepare($latest_payment_sql);
$latest_payment_stmt->bind_param("i", $user_id);
$latest_payment_stmt->execute();
$latest_payment = $latest_payment_stmt->get_result()->fetch_assoc();
$latest_payment_stmt->close();

if ($latest_payment) {
    $user['payment_status'] = 'active';
    $user['membership_type'] = $latest_payment['description'] ?? $user['membership_type'];
    // Use selected_plan_duration if available, else fallback to membership_plan duration
    $duration = $user['selected_plan_duration'] ?? $user['duration'] ?? 0;
    $user['membership_end_date'] = date('Y-m-d', strtotime($latest_payment['payment_date'] . ' + ' . $duration . ' days'));
} else {
    $user['payment_status'] = 'pending';
}

// Check if user came from plan selection
$selected_plan_id = $_GET['plan_id'] ?? $user['selected_plan_id'];
$selected_plan = null;

if ($selected_plan_id) {
    // Get selected plan details
    $plan_sql = "SELECT * FROM membership_plans WHERE id = ?";
    $plan_stmt = $conn->prepare($plan_sql);
    $plan_stmt->bind_param("i", $selected_plan_id);
    $plan_stmt->execute();
    $selected_plan = $plan_stmt->get_result()->fetch_assoc();
    $plan_stmt->close();
}

// Update profile picture variable for display
$profile_picture = $user['profile_picture'] 
    ? "../uploads/profile_pictures/" . $user['profile_picture']
    : 'https://i.pravatar.cc/40?img=1';

$display_name = $user['username'] ?? $user['email'] ?? 'User';
$page_title = 'Payments';

// Get payment history
$payments = [];
$payment_sql = "SELECT ph.*, mp.name as plan_name 
                FROM payment_history ph 
                LEFT JOIN users u ON ph.user_id = u.id 
                LEFT JOIN membership_plans mp ON u.membership_plan_id = mp.id 
                WHERE ph.user_id = ? 
                ORDER BY ph.payment_date DESC";
$stmt = $conn->prepare($payment_sql);

if ($stmt) {
    $stmt->bind_param("i", $user_id);
    if ($stmt->execute()) {
        $payments = $stmt->get_result();
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payments - Almo Fitness</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="css/dark-mode.css">
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
        
        /* Payment Method Selection Styles */
        .payment-method-card input[type="radio"]:checked + div {
            border-color: #3B82F6;
            background-color: #EFF6FF;
            box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.1), 0 2px 4px -1px rgba(59, 130, 246, 0.06);
        }
        
        .payment-method-card input[type="radio"]:checked + div .w-16 {
            background-color: #3B82F6;
        }
        
        .payment-method-card input[type="radio"]:checked + div .w-16 i,
        .payment-method-card input[type="radio"]:checked + div .w-16 .w-8 {
            color: white;
        }
        
        .payment-method-card input[type="radio"]:checked + div .w-16 .w-8 span {
            color: white;
        }
        
        .payment-method-card:hover {
            transform: translateY(-2px);
        }
        
        /* Notification System Styles */
        #notificationBtn {
            transition: all 0.3s ease;
        }
        
        #notificationBtn:hover {
            transform: scale(1.1);
        }
        
        #notificationBadge {
            animation: bounce 2s infinite;
        }
        
        #notificationDropdown {
            transition: all 0.2s ease;
        }
        
        .notification-item {
            transition: all 0.2s ease;
        }
        
        .notification-item:hover {
            transform: translateX(5px);
        }
        
        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% {
                transform: translateY(0);
            }
            40% {
                transform: translateY(-3px);
            }
            60% {
                transform: translateY(-2px);
            }
        }
        
        @keyframes pulse {
            0% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.1);
            }
            100% {
                transform: scale(1);
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
                    <?php echo $page_title ?? 'Payments'; ?>
                </span>
            </div>
            <div class="flex items-center space-x-10">
                <div class="relative">
                    <button id="notificationBtn" class="text-white hover:text-gray-200 p-2 rounded-full hover:bg-gray-700/30 transition-colors cursor-pointer relative" title="Notifications">
                        <i class="fas fa-bell text-lg"></i>
                        <!-- Notification Badge -->
                        <span id="notificationBadge" class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full h-5 w-5 flex items-center justify-center font-bold hidden">
                            0
                        </span>
                    </button>
                    
                    <!-- Notification Dropdown -->
                    <div id="notificationDropdown" class="absolute right-0 mt-2 w-80 bg-white rounded-lg shadow-lg border border-gray-200 opacity-0 invisible transition-all duration-200 transform scale-95 origin-top-right z-50 max-h-96 overflow-y-auto">
                        <div class="p-4 border-b border-gray-100">
                            <div class="flex items-center justify-between">
                                <h3 class="text-lg font-semibold text-gray-800">Notifications</h3>
                                <button onclick="markAllAsRead()" class="text-sm text-blue-600 hover:text-blue-800 font-medium">Mark all as read</button>
                            </div>
                        </div>
                        
                        <div id="notificationList" class="p-2">
                            <!-- Notifications will be populated here -->
                            <div class="text-center py-8 text-gray-500">
                                <i class="fas fa-bell text-3xl mb-2"></i>
                                <p>No notifications</p>
                            </div>
                        </div>
                        
                        <div class="p-3 border-t border-gray-100 bg-gray-50">
                            <div class="flex justify-between items-center">
                                <a href="settings.php" class="text-sm text-blue-600 hover:text-blue-800 font-medium flex items-center">
                                    <i class="fas fa-cog mr-1"></i>Notification Settings
                                </a>
                                <button onclick="clearAllNotifications()" class="text-sm text-red-600 hover:text-red-800 font-medium">Clear All</button>
                            </div>
                        </div>
                    </div>
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
    <main class="ml-64 p-6" id="mainContent">
        <div class="max-w-7xl mx-auto">
            <!-- Success/Error Messages -->
            <?php if (isset($_SESSION['success'])): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm"><?php echo htmlspecialchars($_SESSION['success']); ?></p>
                    </div>
                </div>
            </div>
            <?php unset($_SESSION['success']); endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm"><?php echo htmlspecialchars($_SESSION['error']); ?></p>
                    </div>
                </div>
            </div>
            <?php unset($_SESSION['error']); endif; ?>

            <!-- Selected Plan Payment Section -->
            <?php if ($selected_plan): ?>
            <div class="bg-gradient-to-r from-blue-50 to-purple-50 rounded-xl shadow-sm p-6 mb-6 border-l-4 border-blue-500">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-2xl font-semibold text-gray-800 mb-2">Complete Your Plan Purchase</h2>
                        <p class="text-gray-600">You've selected the <strong><?php echo htmlspecialchars($selected_plan['name']); ?></strong> plan</p>
                    </div>
                    <div class="text-right">
                        <p class="text-3xl font-bold text-blue-600">₱<?php echo number_format($selected_plan['price'], 2); ?></p>
                        <p class="text-sm text-gray-500"><?php echo $selected_plan['duration']; ?> days access</p>
                    </div>
                </div>
                
                <div class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="bg-white rounded-lg p-4">
                        <h3 class="font-semibold text-gray-800 mb-2">Plan Details</h3>
                        <ul class="text-sm text-gray-600 space-y-1">
                            <li>• <?php echo htmlspecialchars($selected_plan['name']); ?></li>
                            <li>• <?php echo $selected_plan['duration']; ?> days duration</li>
                            <li>• Full gym access</li>
                            <li>• Professional equipment</li>
                        </ul>
                    </div>
                    <div class="bg-white rounded-lg p-4">
                        <h3 class="font-semibold text-gray-800 mb-2">Payment Status</h3>
                        <p class="text-sm text-gray-600">Status: <span class="font-medium text-yellow-600">Pending Payment</span></p>
                        <p class="text-sm text-gray-600">Membership will be activated after payment confirmation</p>
                    </div>
                    <div class="bg-white rounded-lg p-4">
                        <h3 class="font-semibold text-gray-800 mb-2">Next Steps</h3>
                        <ol class="text-sm text-gray-600 space-y-1">
                            <li>1. Complete payment below</li>
                            <li>2. Wait for admin confirmation</li>
                            <li>3. Access gym facilities</li>
                        </ol>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Payment Overview -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                <!-- Membership Status -->
                <div class="bg-white rounded-xl shadow-sm p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-800">Membership Status</h3>
                        <div class="w-10 h-10 bg-green-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-check-circle text-green-500"></i>
                        </div>
                    </div>
                    <p class="text-lg font-medium text-gray-800"><?php echo htmlspecialchars($user['membership_type']); ?></p>
                    <p class="text-sm text-gray-500">
                        Status: 
                        <span class="font-medium <?php echo $user['payment_status'] == 'active' ? 'text-green-600' : ($user['payment_status'] == 'pending' ? 'text-yellow-600' : 'text-red-600'); ?>">
                            <?php echo ucfirst($user['payment_status']); ?>
                        </span>
                    </p>
                    <?php if ($user['membership_end_date']): ?>
                    <p class="text-sm text-gray-500">
                        Valid until: <?php echo date('M d, Y', strtotime($user['membership_end_date'])); ?>
                    </p>
                    <?php endif; ?>
                </div>

                <!-- Freeze Credits -->
                <div class="bg-white rounded-xl shadow-sm p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-800">Freeze Credits</h3>
                        <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-snowflake text-blue-500"></i>
                        </div>
                    </div>
                    <p class="text-3xl font-bold text-gray-800"><?php echo (int)$user['freeze_credits']; ?></p>
                    <?php if ((int)$user['freeze_credits'] > 0): ?>
                    <button onclick="openFreezeModal()" class="mt-4 w-full bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition-colors">
                        Freeze Membership
                    </button>
                    <?php else: ?>
                    <p class="mt-4 text-sm text-gray-500 text-center">No freeze credits available</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Make Payment Section -->
            <div class="bg-gradient-to-br from-white to-blue-50 rounded-2xl shadow-lg border border-blue-100 p-8 mb-8">
                <div class="flex items-center justify-between mb-8">
                    <div>
                        <h2 class="text-3xl font-bold text-gray-800 mb-2">
                            <?php echo $selected_plan ? 'Complete Plan Payment' : 'Make a Payment'; ?>
                        </h2>
                        <?php if ($selected_plan): ?>
                        <p class="text-lg text-gray-600">Complete your purchase for the <span class="font-semibold text-blue-600"><?php echo htmlspecialchars($selected_plan['name']); ?></span> plan</p>
                        <?php endif; ?>
                    </div>
                    <?php if ($selected_plan): ?>
                    <div class="text-right">
                        <div class="bg-blue-600 text-white px-6 py-3 rounded-xl">
                            <p class="text-sm text-blue-100">Total Amount</p>
                            <p class="text-3xl font-bold">₱<?php echo number_format($selected_plan['price'], 2); ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <form action="<?php echo $selected_plan ? 'process_plan_payment.php' : 'process_payment.php'; ?>" method="POST" enctype="multipart/form-data" class="space-y-8" onsubmit="return validateForm()">
                    <?php if ($selected_plan): ?>
                    <input type="hidden" name="plan_id" value="<?php echo $selected_plan['id']; ?>">
                    <input type="hidden" name="amount" value="<?php echo $selected_plan['price']; ?>">
                    <input type="hidden" name="description" value="Payment for <?php echo htmlspecialchars($selected_plan['name']); ?> plan">
                    <?php endif; ?>
                    
                    <!-- Payment Method Selection -->
                    <div class="space-y-4">
                        <label class="block text-lg font-semibold text-gray-800 mb-4">Choose Payment Method <span class="text-red-500">*</span></label>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <label class="payment-method-card relative flex flex-col items-center justify-center bg-white border-2 border-gray-200 rounded-xl p-6 cursor-pointer hover:border-blue-400 hover:shadow-md transition-all duration-300 group">
                                <input type="radio" name="payment_method" value="Cash" class="absolute opacity-0" onchange="togglePaymentFields()">
                                <div class="flex flex-col items-center">
                                    <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mb-3 group-hover:bg-green-200 transition-colors">
                                        <i class="fas fa-money-bill-wave text-2xl text-green-600"></i>
                                    </div>
                                    <span class="text-sm font-semibold text-gray-800">Cash</span>
                                    <span class="text-xs text-gray-500 mt-1">Pay at Front Desk</span>
                                </div>
                            </label>
                            
                            <label class="payment-method-card relative flex flex-col items-center justify-center bg-white border-2 border-gray-200 rounded-xl p-6 cursor-pointer hover:border-blue-400 hover:shadow-md transition-all duration-300 group">
                                <input type="radio" name="payment_method" value="GCash" class="absolute opacity-0" onchange="togglePaymentFields()">
                                <div class="flex flex-col items-center">
                                    <div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center mb-3 group-hover:bg-blue-200 transition-colors">
                                        <i class="fas fa-mobile-alt text-2xl text-blue-600"></i>
                                    </div>
                                    <span class="text-sm font-semibold text-gray-800">GCash</span>
                                    <span class="text-xs text-gray-500 mt-1">Mobile Payment</span>
                                </div>
                            </label>
                            
                            <label class="payment-method-card relative flex flex-col items-center justify-center bg-white border-2 border-gray-200 rounded-xl p-6 cursor-pointer hover:border-blue-400 hover:shadow-md transition-all duration-300 group">
                                <input type="radio" name="payment_method" value="PayMaya" class="absolute opacity-0" onchange="togglePaymentFields()">
                                <div class="flex flex-col items-center">
                                    <div class="w-16 h-16 bg-purple-100 rounded-full flex items-center justify-center mb-3 group-hover:bg-purple-200 transition-colors">
                                        <div class="w-8 h-8 bg-purple-600 rounded-full flex items-center justify-center">
                                            <span class="text-white text-xs font-bold">P</span>
                                        </div>
                                    </div>
                                    <span class="text-sm font-semibold text-gray-800">PayMaya</span>
                                    <span class="text-xs text-gray-500 mt-1">Digital Wallet</span>
                                </div>
                            </label>
                            
                            <label class="payment-method-card relative flex flex-col items-center justify-center bg-white border-2 border-gray-200 rounded-xl p-6 cursor-pointer hover:border-blue-400 hover:shadow-md transition-all duration-300 group">
                                <input type="radio" name="payment_method" value="GoTyme" class="absolute opacity-0" onchange="togglePaymentFields()">
                                <div class="flex flex-col items-center">
                                    <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mb-3 group-hover:bg-green-200 transition-colors">
                                        <i class="fas fa-university text-2xl text-green-600"></i>
                                    </div>
                                    <span class="text-sm font-semibold text-gray-800">GoTyme</span>
                                    <span class="text-xs text-gray-500 mt-1">Bank Transfer</span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Amount Section -->
                    <div class="bg-gray-50 rounded-xl p-6">
                        <label class="block text-lg font-semibold text-gray-800 mb-4">Payment Amount <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <span class="text-2xl text-gray-500 font-semibold">₱</span>
                            </div>
                            <input type="number" name="amount" step="0.01" required
                                value="<?php echo $selected_plan ? $selected_plan['price'] : ''; ?>"
                                <?php echo $selected_plan ? 'readonly' : ''; ?>"
                                class="focus:ring-2 focus:ring-blue-500 focus:border-blue-500 block w-full pl-12 pr-4 py-4 text-xl border-gray-300 rounded-lg <?php echo $selected_plan ? 'bg-blue-50 border-blue-200 text-blue-800 font-semibold' : 'bg-white'; ?>"
                                placeholder="0.00">
                        </div>
                        <?php if ($selected_plan): ?>
                        <div class="mt-3 flex items-center text-blue-600">
                            <i class="fas fa-info-circle mr-2"></i>
                            <span class="text-sm font-medium">Fixed amount for <?php echo htmlspecialchars($selected_plan['name']); ?> plan</span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div id="onlinePaymentFields" class="bg-blue-50 rounded-xl p-6 border border-blue-200 hidden">
                        <div class="flex items-center mb-4">
                            <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center mr-3">
                                <i class="fas fa-credit-card text-blue-600"></i>
                            </div>
                            <h3 class="text-lg font-semibold text-blue-800">Online Payment Details</h3>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Reference Number <span class="text-red-500">*</span></label>
                                <input type="text" name="reference_number" id="referenceNumber"
                                    class="mt-1 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 block w-full shadow-sm sm:text-sm border-gray-300 rounded-lg px-4 py-3">
                                <p class="mt-2 text-sm text-blue-600 flex items-center">
                                    <i class="fas fa-info-circle mr-2"></i>
                                    Required for online payments
                                </p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Proof of Payment <span class="text-red-500">*</span></label>
                                <div class="mt-1 flex justify-center px-6 pt-6 pb-6 border-2 border-blue-300 border-dashed rounded-lg bg-white hover:border-blue-400 transition-colors">
                                    <div class="space-y-2 text-center">
                                        <i class="fas fa-upload text-blue-400 text-3xl mb-2"></i>
                                        <div class="flex text-sm text-gray-600">
                                            <label for="proof_of_payment" class="relative cursor-pointer bg-white rounded-md font-medium text-blue-600 hover:text-blue-500 focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-blue-500">
                                                <span>Upload a file</span>
                                                <input id="proof_of_payment" name="proof_of_payment" type="file" class="sr-only" accept="image/*">
                                            </label>
                                            <p class="pl-1">or drag and drop</p>
                                        </div>
                                        <p class="text-xs text-gray-500">PNG, JPG, GIF up to 10MB</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="cashPaymentMessage" class="bg-green-50 rounded-xl p-6 border border-green-200 hidden">
                        <div class="flex items-center mb-4">
                            <div class="w-8 h-8 bg-green-100 rounded-full flex items-center justify-center mr-3">
                                <i class="fas fa-money-bill-wave text-green-600"></i>
                            </div>
                            <h3 class="text-lg font-semibold text-green-800">Cash Payment Information</h3>
                        </div>
                        <div class="text-green-700">
                            <p class="mb-3">Please proceed to our front desk to complete your cash payment. Your payment will be pending until confirmed by our staff.</p>
                            <div class="bg-white rounded-lg p-4 border border-green-200">
                                <div class="flex items-center text-sm">
                                    <i class="fas fa-clock text-green-500 mr-2"></i>
                                    <span class="font-medium">Payment Status:</span>
                                    <span class="ml-2 px-2 py-1 bg-yellow-100 text-yellow-800 rounded-full text-xs font-medium">Pending Confirmation</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end pt-6">
                        <button type="submit" id="submitButton" class="bg-gradient-to-r from-blue-600 to-blue-700 text-white px-8 py-4 rounded-xl hover:from-blue-700 hover:to-blue-800 transition-all duration-300 transform hover:scale-105 shadow-lg font-semibold text-lg disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none disabled:shadow-none" disabled>
                            <div class="flex items-center">
                                <i class="fas fa-credit-card mr-3"></i>
                                <?php echo $selected_plan ? 'Complete Plan Payment' : 'Submit Payment'; ?>
                            </div>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Payment History -->
            <div class="bg-white rounded-xl shadow-sm p-6">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-semibold text-gray-800">Payment History</h2>
                    <div class="flex items-center space-x-2">
                        <select class="text-sm border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                            <option value="all">All Time</option>
                            <option value="month">This Month</option>
                            <option value="year">This Year</option>
                        </select>
                        <button class="text-blue-600 hover:text-blue-700">
                            <i class="fas fa-download"></i>
                        </button>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Method</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Proof</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if ($payments && $payments->num_rows > 0): ?>
                                <?php while ($payment = $payments->fetch_assoc()): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo date('M d, Y', strtotime($payment['payment_date'])); ?>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-900">
                                        <?php echo htmlspecialchars($payment['description']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        ₱<?php echo number_format($payment['amount'], 2); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo htmlspecialchars($payment['payment_method']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                            <?php 
                                            echo match($payment['payment_status']) {
                                                'Completed' => 'bg-green-100 text-green-800',
                                                'Pending' => 'bg-yellow-100 text-yellow-800',
                                                'Rejected' => 'bg-red-100 text-red-800',
                                                'Cancelled' => 'bg-gray-100 text-gray-800',
                                                default => 'bg-gray-100 text-gray-800'
                                            };
                                            ?>">
                                            <i class="fas fa-<?php 
                                            echo match($payment['payment_status']) {
                                                'Completed' => 'check',
                                                'Pending' => 'clock',
                                                'Rejected' => 'times',
                                                'Cancelled' => 'ban',
                                                default => 'question'
                                            };
                                            ?> mr-1"></i>
                                            <?php echo htmlspecialchars($payment['payment_status']); ?>
                                        </span>
                                        <?php if ($payment['payment_status'] === 'Rejected' && !empty($payment['rejection_reason'])): ?>
                                            <div class="mt-1">
                                                <span class="text-xs text-red-600 cursor-help" title="<?php echo htmlspecialchars($payment['rejection_reason']); ?>">
                                                    <i class="fas fa-info-circle"></i> View reason
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php if (!empty($payment['proof_of_payment'])): ?>
                                        <button onclick="viewProofOfPayment('<?php echo htmlspecialchars($payment['proof_of_payment']); ?>')" 
                                                class="text-blue-600 hover:text-blue-800">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <?php else: ?>
                                        <span class="text-gray-400">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php if ($payment['payment_status'] === 'Pending'): ?>
                                        <button class="text-red-600 hover:text-red-800">
                                            <i class="fas fa-times"></i> Cancel
                                        </button>
                                        <?php else: ?>
                                        <span class="text-gray-400">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                            <tr>
                                <td colspan="7" class="px-6 py-4 text-center text-sm text-gray-500">
                                    No payment history found
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <!-- Freeze Membership Modal -->
    <div id="freezeModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-medium text-gray-900">Freeze Membership</h3>
                    <button onclick="closeFreezeModal()" class="text-gray-400 hover:text-gray-500">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <form action="process_freeze.php" method="POST" class="mt-4">
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Start Date <span class="text-red-500">*</span></label>
                        <input type="date" name="start_date" required
                            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">End Date <span class="text-red-500">*</span></label>
                        <input type="date" name="end_date" required
                            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Reason <span class="text-red-500">*</span></label>
                        <textarea name="reason" rows="3" required
                            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"></textarea>
                    </div>
                    <div class="flex justify-end">
                        <button type="button" onclick="closeFreezeModal()" class="mr-3 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                            Cancel
                        </button>
                        <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 rounded-md">
                            Submit Request
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Proof of Payment Modal -->
    <div id="proofOfPaymentModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full z-50">
        <div class="relative top-10 mx-auto p-5 border w-11/12 max-w-4xl shadow-lg rounded-md bg-white">
            <div class="flex flex-col items-center">
                <div class="flex justify-between items-center w-full mb-4">
                    <h3 class="text-xl font-medium text-gray-900">Proof of Payment</h3>
                    <button onclick="closeProofOfPaymentModal()" class="text-gray-400 hover:text-gray-500">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <div class="w-full max-h-96 bg-gray-100 rounded-lg overflow-hidden">
                    <img id="proofImage" src="" alt="Proof of Payment" class="w-full h-full object-contain">
                </div>
                <button onclick="closeProofOfPaymentModal()" class="mt-4 bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 transition-colors">
                    Close
                </button>
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

        // Payment method toggle
        function togglePaymentFields() {
            const selectedMethod = document.querySelector('input[name="payment_method"]:checked');
            const onlineFields = document.getElementById('onlinePaymentFields');
            const cashMessage = document.getElementById('cashPaymentMessage');
            const submitButton = document.getElementById('submitButton');
            const selectedPlan = <?php echo $selected_plan ? 'true' : 'false'; ?>;

            console.log('Payment method selected:', selectedMethod ? selectedMethod.value : 'none');
            console.log('Selected plan:', selectedPlan);

            // Remove active state from all payment method cards
            document.querySelectorAll('.payment-method-card').forEach(card => {
                card.classList.remove('ring-2', 'ring-blue-500', 'ring-offset-2');
            });

            if (selectedMethod) {
                // Add active state to selected card
                const selectedCard = selectedMethod.closest('.payment-method-card');
                if (selectedCard) {
                    selectedCard.classList.add('ring-2', 'ring-blue-500', 'ring-offset-2');
                }

                submitButton.disabled = false;
                console.log('Submit button enabled due to payment method selection');
                
                if (selectedMethod.value === 'Cash') {
                    onlineFields.classList.add('hidden');
                    cashMessage.classList.remove('hidden');
                } else {
                    onlineFields.classList.remove('hidden');
                    cashMessage.classList.add('hidden');
                }
            } else {
                // If there's a selected plan, enable the button even without payment method
                if (selectedPlan) {
                    submitButton.disabled = false;
                    console.log('Submit button enabled due to selected plan');
                } else {
                    submitButton.disabled = true;
                    console.log('Submit button disabled - no payment method or plan selected');
                }
                onlineFields.classList.add('hidden');
                cashMessage.classList.add('hidden');
            }
        }

        // Initialize payment fields on page load
        document.addEventListener('DOMContentLoaded', function() {
            togglePaymentFields();
            
            // Load dark mode preference
            const savedDarkMode = localStorage.getItem('darkMode');
            if (savedDarkMode === 'true') {
                document.body.classList.add('dark-mode');
                console.log('Dark mode loaded from localStorage');
            }
        });

        // Form validation
        function validateForm() {
            const selectedMethod = document.querySelector('input[name="payment_method"]:checked');
            const amount = document.querySelector('input[name="amount"]').value;
            const selectedPlan = <?php echo $selected_plan ? 'true' : 'false'; ?>;

            console.log('Form validation - Payment method:', selectedMethod ? selectedMethod.value : 'none');
            console.log('Form validation - Amount:', amount);
            console.log('Form validation - Selected plan:', selectedPlan);

            if (!selectedMethod) {
                alert('Please select a payment method.');
                return false;
            }

            if (!amount || parseFloat(amount) <= 0) {
                alert('Please enter a valid amount.');
                return false;
            }

            // For online payments, check if reference number and proof are provided
            if (selectedMethod.value !== 'Cash') {
                const referenceNumber = document.getElementById('referenceNumber').value;
                const proofFile = document.getElementById('proof_of_payment').files[0];

                if (!referenceNumber.trim()) {
                    alert('Please enter a reference number for online payment.');
                    return false;
                }

                if (!proofFile) {
                    alert('Please upload proof of payment for online payment.');
                    return false;
                }
            }

            console.log('Form validation passed - submitting form');
            return true;
        }

        // File upload preview
        document.getElementById('proof_of_payment').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    // You can add preview functionality here
                    console.log('File selected:', file.name);
                };
                reader.readAsDataURL(file);
            }
        });

        // Freeze modal functions
        function openFreezeModal() {
            document.getElementById('freezeModal').classList.remove('hidden');
        }

        function closeFreezeModal() {
            document.getElementById('freezeModal').classList.add('hidden');
        }

        // View payment details
        function viewPaymentDetails(paymentId) {
            // Implement payment details view logic
            console.log('Viewing payment:', paymentId);
        }

        function viewProofOfPayment(imagePath) {
            const modal = document.getElementById('proofOfPaymentModal');
            const image = document.getElementById('proofImage');
            
            console.log('Loading image:', imagePath);
            
            // Clear any previous error messages
            const existingError = image.parentNode.querySelector('.text-gray-500');
            if (existingError) {
                existingError.remove();
            }
            
            // Reset image
            image.style.display = 'block';
            image.alt = 'Loading...';
            
            // Set up error handling for image loading
            image.onerror = function() {
                console.log('Image failed to load:', this.src);
                this.src = '';
                this.alt = 'Image not found';
                this.style.display = 'none';
                // Show error message
                const errorDiv = document.createElement('div');
                errorDiv.className = 'text-center text-gray-500 py-8';
                errorDiv.innerHTML = '<i class="fas fa-exclamation-triangle text-2xl mb-2"></i><br>Proof of payment image not found<br><small class="text-xs">File: ' + imagePath + '</small><br><small class="text-xs">URL: ' + this.src + '</small>';
                this.parentNode.appendChild(errorDiv);
            };
            
            image.onload = function() {
                console.log('Image loaded successfully:', this.src);
                // Clear any error messages when image loads successfully
                const errorDiv = this.parentNode.querySelector('.text-gray-500');
                if (errorDiv) {
                    errorDiv.remove();
                }
                this.style.display = 'block';
            };
            
            // Try the simplified image serving script first
            const timestamp = new Date().getTime();
            const testImage = new Image();
            
            testImage.onload = function() {
                console.log('Image loaded successfully via PHP script');
                image.src = this.src;
            };
            
            testImage.onerror = function() {
                console.log('PHP script failed, trying direct file access');
                // Fallback to direct file access
                image.src = '../uploads/payment_proofs/' + imagePath + '?t=' + timestamp;
            };
            
            testImage.src = 'view_payment_proof_simple.php?file=' + encodeURIComponent(imagePath) + '&t=' + timestamp;
            modal.classList.remove('hidden');
        }

        function closeProofOfPaymentModal() {
            const modal = document.getElementById('proofOfPaymentModal');
            modal.classList.add('hidden');
        }

        // Close modal when clicking outside
        document.getElementById('proofOfPaymentModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeProofOfPaymentModal();
            }
        });

        // Simple Working Notification System
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Initializing real-time notification system...');
            
            const notificationBtn = document.getElementById('notificationBtn');
            const notificationDropdown = document.getElementById('notificationDropdown');
            const notificationBadge = document.getElementById('notificationBadge');
            const notificationList = document.getElementById('notificationList');
            
            console.log('Elements found:', { 
                button: notificationBtn, 
                dropdown: notificationDropdown, 
                badge: notificationBadge, 
                list: notificationList 
            });
            
            if (!notificationBtn || !notificationDropdown) {
                console.error('Notification elements not found!');
                return;
            }
            
            let notifications = [];
            let unreadCount = 0;
            
            // Fetch real-time notifications
            function fetchNotifications() {
                fetch('get_real_notifications.php')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            notifications = data.notifications;
                            unreadCount = data.unread_count;
                            updateBadge();
                            renderNotifications();
                            console.log('Fetched notifications:', notifications);
                        } else {
                            console.error('Failed to fetch notifications:', data.error);
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching notifications:', error);
                        // Fallback to sample notifications
                        notifications = [
                            { id: 1, title: 'Payment Success', message: 'Your payment has been processed successfully.', read: false, type: 'payment' },
                            { id: 2, title: 'Plan Activated', message: 'Your membership plan is now active.', read: false, type: 'info' },
                            { id: 3, title: 'Payment Reminder', message: 'Please complete your payment to continue.', read: false, type: 'reminder' }
                        ];
                        unreadCount = notifications.length;
                        updateBadge();
                        renderNotifications();
                    });
            }
            
            // Update badge
            function updateBadge() {
                if (notificationBadge) {
                    if (unreadCount > 0) {
                        notificationBadge.textContent = unreadCount;
                        notificationBadge.classList.remove('hidden');
                    } else {
                        notificationBadge.classList.add('hidden');
                    }
                }
            }
            
            // Render notifications with enhanced styling
            function renderNotifications() {
                if (!notificationList) return;
                
                if (notifications.length === 0) {
                    notificationList.innerHTML = '<div class="text-center py-8 text-gray-500"><i class="fas fa-bell text-3xl mb-2"></i><p>No notifications</p></div>';
                    return;
                }
                
                notificationList.innerHTML = notifications.map(notification => {
                    const typeIcon = getTypeIcon(notification.type);
                    const typeColor = getTypeColor(notification.type);
                    const timeAgo = getTimeAgo(notification.timestamp);
                    
                    return `
                        <div class="p-3 border-b border-gray-100 hover:bg-gray-50 transition-all duration-200">
                            <div class="flex items-start space-x-3">
                                <div class="w-2 h-2 rounded-full ${typeColor} mt-2 flex-shrink-0"></div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between">
                                        <p class="text-sm font-medium text-gray-900 truncate">${notification.title}</p>
                                        <span class="text-xs text-gray-400 ml-2">${timeAgo}</span>
                                    </div>
                                    <p class="text-sm text-gray-600 mt-1">${notification.message}</p>
                                    <div class="flex items-center mt-2 space-x-2">
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ${getTypeBadgeColor(notification.type)}">
                                            ${typeIcon} ${notification.type}
                                        </span>
                                        ${!notification.read ? '<button onclick="markAsRead(\'' + notification.id + '\')" class="text-xs text-blue-600 hover:text-blue-800">Mark read</button>' : ''}
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                }).join('');
            }
            
            // Get type icon
            function getTypeIcon(type) {
                const icons = {
                    'warning': '<i class="fas fa-exclamation-triangle"></i>',
                    'info': '<i class="fas fa-info-circle"></i>',
                    'reminder': '<i class="fas fa-clock"></i>',
                    'goal': '<i class="fas fa-target"></i>',
                    'announcement': '<i class="fas fa-bullhorn"></i>',
                    'payment': '<i class="fas fa-credit-card"></i>',
                    'welcome': '<i class="fas fa-hand-wave"></i>'
                };
                return icons[type] || '<i class="fas fa-bell"></i>';
            }
            
            // Get type color
            function getTypeColor(type) {
                const colors = {
                    'warning': 'bg-yellow-500',
                    'info': 'bg-blue-500',
                    'reminder': 'bg-purple-500',
                    'goal': 'bg-green-500',
                    'announcement': 'bg-red-500',
                    'payment': 'bg-indigo-500',
                    'welcome': 'bg-pink-500'
                };
                return colors[type] || 'bg-gray-500';
            }
            
            // Get type badge color
            function getTypeBadgeColor(type) {
                const colors = {
                    'warning': 'bg-yellow-100 text-yellow-800',
                    'info': 'bg-blue-100 text-blue-800',
                    'reminder': 'bg-purple-100 text-purple-800',
                    'goal': 'bg-green-100 text-green-800',
                    'announcement': 'bg-red-100 text-red-800',
                    'payment': 'bg-indigo-100 text-indigo-800',
                    'welcome': 'bg-pink-100 text-pink-800'
                };
                return colors[type] || 'bg-gray-100 text-gray-800';
            }
            
            // Get time ago
            function getTimeAgo(timestamp) {
                const now = Math.floor(Date.now() / 1000);
                const diff = now - timestamp;
                
                if (diff < 60) return 'Just now';
                if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
                if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
                if (diff < 2592000) return Math.floor(diff / 86400) + 'd ago';
                return Math.floor(diff / 2592000) + 'mo ago';
            }
            
            // Global functions
            window.markAsRead = function(id) {
                const notification = notifications.find(n => n.id === id);
                if (notification && !notification.read) {
                    // Mark as read in backend
                    fetch('mark_notification_read.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'notification_id=' + encodeURIComponent(id)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            notification.read = true;
                            unreadCount--;
                            updateBadge();
                            renderNotifications();
                        }
                    })
                    .catch(error => {
                        console.error('Error marking notification as read:', error);
                        // Still update locally
                        notification.read = true;
                        unreadCount--;
                        updateBadge();
                        renderNotifications();
                    });
                }
            };
            
            window.markAllAsRead = function() {
                notifications.forEach(n => n.read = true);
                unreadCount = 0;
                updateBadge();
                renderNotifications();
            };
            
            window.clearAllNotifications = function() {
                notifications.length = 0;
                unreadCount = 0;
                updateBadge();
                renderNotifications();
            };
            
            // Toggle dropdown
            function toggleDropdown() {
                const isVisible = !notificationDropdown.classList.contains('invisible');
                console.log('Toggling dropdown, visibility:', isVisible);
                
                if (isVisible) {
                    notificationDropdown.classList.add('invisible', 'opacity-0', 'scale-95');
                    notificationDropdown.classList.remove('opacity-100', 'scale-100');
                } else {
                    notificationDropdown.classList.remove('invisible', 'opacity-0', 'scale-95');
                    notificationDropdown.classList.add('opacity-100', 'scale-100');
                    // Refresh notifications when opening
                    fetchNotifications();
                }
            }
            
            // Add click event
            notificationBtn.addEventListener('click', function(e) {
                console.log('Notification button clicked!');
                e.stopPropagation();
                toggleDropdown();
            });
            
            // Close when clicking outside
            document.addEventListener('click', function(e) {
                if (!notificationBtn.contains(e.target) && !notificationDropdown.contains(e.target)) {
                    notificationDropdown.classList.add('invisible', 'opacity-0', 'scale-95');
                    notificationDropdown.classList.remove('opacity-100', 'scale-100');
                }
            });
            
            // Initialize
            fetchNotifications();
            console.log('Real-time notification system initialized successfully!');
            
            // Refresh notifications every 5 minutes
            setInterval(fetchNotifications, 300000);
        });
    </script>
    <?php include 'footer.php'; ?>
</body>
</html> 