<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: member_login.php");
    exit();
}

// Validate session data
if (empty($_SESSION['user_id']) || !isset($_SESSION['user_id'])) {
    error_log("Invalid session data: " . var_export($_SESSION, true));
    session_destroy();
    header("Location: member_login.php?error=invalid_session");
    exit();
}

// Database connection
require_once '../db.php';
require_once 'payment_status_helper.php';

// Check database connection
if (!$conn || $conn->connect_error) {
    error_log("Database connection failed: " . ($conn ? $conn->connect_error : 'No connection object'));
    header("Location: member_login.php?error=database_error");
    exit();
}

// Get user information from database
$user_id = $_SESSION['user_id'];

// Validate user_id
if (!is_numeric($user_id) || $user_id <= 0) {
    error_log("Invalid user_id in session: " . var_export($user_id, true));
    header("Location: member_login.php?error=invalid_session");
    exit();
}

$user = getUserPaymentStatus($conn, $user_id);

// Check if user data was retrieved successfully
if (!$user) {
    // Log the error and redirect to login or show error
    error_log("Failed to retrieve user data for user_id: " . $user_id);
    error_log("Database connection status: " . ($conn ? 'Connected' : 'Not connected'));
    error_log("Session user_id: " . var_export($_SESSION['user_id'], true));
    header("Location: member_login.php?error=user_not_found");
    exit();
}

// Ensure user is always an array to prevent null access warnings
if (!is_array($user)) {
    $user = [];
    error_log("User data is not an array for user_id: " . $user_id);
    header("Location: member_login.php?error=user_data_invalid");
    exit();
}

// Get recent payments
$recent_payments = getRecentPayments($conn, $user_id, 3);

// Ensure recent_payments is always an array
if (!is_array($recent_payments)) {
    $recent_payments = [];
    error_log("Failed to retrieve recent payments for user_id: " . $user_id);
}

// Get all available membership plans
$plans_sql = "SELECT * FROM membership_plans ORDER BY price ASC";
$plans_result = $conn->query($plans_sql);

if (!$plans_result) {
    error_log("Error querying membership plans: " . $conn->error);
    $available_plans = [];
} else {
    $available_plans = [];
    while ($plan = $plans_result->fetch_assoc()) {
        $available_plans[] = $plan;
    }
}

// Predictive Analytics and Decision Tree Logic
function analyzeMemberProfile($user, $plans) {
    // Ensure plans is an array
    if (!is_array($plans)) {
        return [
            'recommendations' => [],
            'all_scores' => []
        ];
    }
    
    $recommendations = [];
    $scores = [];
    
    foreach ($plans as $plan) {
        // Ensure plan data is valid before proceeding
        if (!isset($plan['id']) || !isset($plan['name']) || !isset($plan['price']) || !isset($plan['duration'])) {
            continue; // Skip invalid plans
        }
        
        $score = 0;
        $reasons = [];
        
        // Factor 1: Fitness Level Analysis
        if (isset($user['fitness_level']) && $user['fitness_level']) {
            switch ($user['fitness_level']) {
                case 'beginner':
                    if ($plan['name'] == 'Daily Pass' || $plan['name'] == 'Monthly Plan') {
                        $score += 30;
                        $reasons[] = "Perfect for beginners - start small and build up";
                    }
                    break;
                case 'intermediate':
                    if ($plan['name'] == 'Monthly Plan' || $plan['name'] == 'Annual Plan') {
                        $score += 25;
                        $reasons[] = "Great for intermediate users - consistent commitment";
                    }
                    break;
                case 'advanced':
                    if ($plan['name'] == 'Annual Plan') {
                        $score += 35;
                        $reasons[] = "Advanced users benefit most from long-term plans";
                    }
                    break;
            }
        }
        
        // Factor 2: Goal-Based Analysis
        if (isset($user['goal']) && $user['goal']) {
            switch ($user['goal']) {
                case 'weight_loss':
                    if ($plan['name'] == 'Monthly Plan' || $plan['name'] == 'Annual Plan') {
                        $score += 20;
                        $reasons[] = "Consistent access needed for weight loss programs";
                    }
                    break;
                case 'muscle_gain':
                    if ($plan['name'] == 'Annual Plan') {
                        $score += 25;
                        $reasons[] = "Muscle building requires long-term commitment";
                    }
                    break;
                case 'endurance':
                    if ($plan['name'] == 'Monthly Plan' || $plan['name'] == 'Annual Plan') {
                        $score += 20;
                        $reasons[] = "Endurance training benefits from regular access";
                    }
                    break;
            }
        }
        
        // Factor 3: Attendance Frequency Analysis
        if (isset($user['attendance_frequency']) && $user['attendance_frequency']) {
            $frequency = $user['attendance_frequency'];
            if ($frequency >= 15 && $plan['name'] == 'Annual Plan') {
                $score += 30;
                $reasons[] = "High attendance rate - maximize value with annual plan";
            } elseif ($frequency >= 8 && $plan['name'] == 'Monthly Plan') {
                $score += 25;
                $reasons[] = "Good attendance - monthly plan provides good value";
            } elseif ($frequency < 5 && $plan['name'] == 'Daily Pass') {
                $score += 20;
                $reasons[] = "Low attendance - pay-as-you-go is cost-effective";
            }
        }
        
        // Factor 4: Age and Activity Level
        if (isset($user['age']) && isset($user['activity_level']) && $user['age'] && $user['activity_level']) {
            if ($user['age'] < 25 && $user['activity_level'] == 'active') {
                if ($plan['name'] == 'Annual Plan') {
                    $score += 15;
                    $reasons[] = "Young and active - can maximize annual plan benefits";
                }
            } elseif ($user['age'] > 40 && $user['activity_level'] == 'moderate') {
                if ($plan['name'] == 'Monthly Plan') {
                    $score += 15;
                    $reasons[] = "Moderate activity level - monthly plan is flexible";
                }
            }
        }
        
        // Factor 5: Medical Conditions
        if (isset($user['has_medical_condition']) && $user['has_medical_condition']) {
            if ($plan['name'] == 'Annual Plan') {
                $score += 20;
                $reasons[] = "Annual plan provides consistent access for medical considerations";
            }
        }
        
        // Factor 6: Cost-Benefit Analysis
        $daily_cost = $plan['price'] / $plan['duration'];
        if ($daily_cost <= 50) {
            $score += 15;
            $reasons[] = "Excellent value for money";
        } elseif ($daily_cost <= 100) {
            $score += 10;
            $reasons[] = "Good value for money";
        }
        
        $scores[$plan['id']] = [
            'plan' => $plan,
            'score' => $score,
            'reasons' => $reasons,
            'daily_cost' => $daily_cost
        ];
    }
    
    // Sort by score
    arsort($scores);
    
    // Get top 3 recommendations
    $top_recommendations = array_slice($scores, 0, 3, true);
    
    return [
        'recommendations' => $top_recommendations,
        'all_scores' => $scores
    ];
}

// Get recommendations
$analytics = analyzeMemberProfile($user, $available_plans);

// Ensure analytics is always an array to prevent null access warnings
if (!$analytics || !is_array($analytics)) {
    $analytics = [
        'recommendations' => [],
        'all_scores' => []
    ];
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
    
    // Check if there's a recent payment for the plan
    if (isset($user['payment_status'])) {
        switch ($user['payment_status']) {
            case 'Completed':
                return '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">Active</span>';
            case 'Pending':
                return '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800">Pending Payment</span>';
            case 'Failed':
                return '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">Payment Failed</span>';
            default:
                return '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">Plan Selected</span>';
        }
    }
    
    // Default to plan selected if no payment status
    return '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">Plan Selected</span>';
}

// Helper function to format payment status with CSS classes
function formatPaymentStatus($status) {
    switch ($status) {
        case 'Completed':
            return 'bg-green-100 text-green-800';
        case 'Pending':
            return 'bg-yellow-100 text-yellow-800';
        case 'Failed':
            return 'bg-red-100 text-red-800';
        case 'Rejected':
            return 'bg-red-100 text-red-800';
        default:
            return 'bg-gray-100 text-gray-800';
    }
}

// Helper function to get membership days remaining
function getMembershipDaysRemaining($user) {
    if (!$user || !isset($user['membership_end_date']) || empty($user['membership_end_date'])) {
        return 0;
    }
    
    $end_date = new DateTime($user['membership_end_date']);
    $current_date = new DateTime();
    $interval = $current_date->diff($end_date);
    
    // Return positive days if membership is still active, 0 if expired
    return $interval->invert ? 0 : $interval->days;
}

// Handle plan selection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['select_plan'])) {
    $selected_plan_id = $_POST['plan_id'];
    
    // Update user's selected plan (not active yet - needs payment)
    $update_sql = "UPDATE users SET selected_plan_id = ? WHERE id = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("ii", $selected_plan_id, $user_id);
    
    if ($update_stmt->execute()) {
        header("Location: payment.php?plan_id=" . $selected_plan_id);
        exit();
    }
}

// Update profile picture variable for display
$profile_picture = (!empty($user['profile_picture'])) 
    ? "../uploads/profile_pictures/" . $user['profile_picture']
    : 'https://i.pravatar.cc/40?img=1';

$display_name = (!empty($user['username'])) ? $user['username'] : ((!empty($user['email'])) ? $user['email'] : 'User');
$page_title = 'Membership';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Membership - Almo Fitness</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="css/dark-mode.css">
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
        .plan-card {
            transition: all 0.3s ease;
        }
        .plan-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        .recommendation-badge {
            position: absolute;
            top: -10px;
            right: -10px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 8px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            z-index: 10;
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
                    <?php echo $page_title ?? 'Membership'; ?>
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
    <div class="ml-64 p-6" id="mainContent">
        <div class="max-w-7xl mx-auto">
            <!-- Current Membership Status -->
            <div class="bg-white rounded-xl shadow-sm p-6 mb-8">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-2xl font-semibold text-gray-800 mb-2">Your Membership Status</h2>
                        <p class="text-gray-600">Current Plan: <span class="font-medium text-blue-600"><?php echo htmlspecialchars($user['plan_name'] ?? 'No Active Plan'); ?></span></p>
                        <?php if (isset($user['membership_end_date']) && $user['membership_end_date']): ?>
                        <p class="text-gray-600">Expires: <span class="font-medium text-red-600"><?php echo date('M d, Y', strtotime($user['membership_end_date'])); ?></span></p>
                        <p class="text-gray-600">Days Remaining: <span class="font-medium text-orange-600"><?php echo getMembershipDaysRemaining($user); ?></span></p>
                        <?php endif; ?>
                    </div>
                    <div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-crown text-2xl text-blue-500"></i>
                    </div>
                </div>
            </div>

            <!-- Payment Status Section -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <!-- Total Paid -->
                <div class="bg-white rounded-xl shadow-sm p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 mb-1">Total Paid</p>
                            <h3 class="text-2xl font-bold text-green-600">₱<?php echo number_format($user['total_paid'] ?? 0, 2); ?></h3>
                            <p class="text-sm text-gray-500"><?php echo $user['completed_payments'] ?? 0; ?> payments</p>
                        </div>
                        <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-check-circle text-green-500"></i>
                        </div>
                    </div>
                </div>

                <!-- Outstanding Balance -->
                <div class="bg-white rounded-xl shadow-sm p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 mb-1">Outstanding Balance</p>
                            <h3 class="text-2xl font-bold <?php echo ($user['balance'] ?? 0) > 0 ? 'text-red-600' : 'text-gray-600'; ?>">
                                ₱<?php echo number_format($user['balance'] ?? 0, 2); ?>
                            </h3>
                            <?php if (($user['balance'] ?? 0) > 0): ?>
                                <a href="payment.php" class="text-sm text-red-600 hover:text-red-700">Pay Now</a>
                            <?php else: ?>
                                <p class="text-sm text-green-600">All Paid</p>
                            <?php endif; ?>
                        </div>
                        <div class="w-12 h-12 <?php echo ($user['balance'] ?? 0) > 0 ? 'bg-red-100' : 'bg-gray-100'; ?> rounded-full flex items-center justify-center">
                            <i class="fas fa-wallet <?php echo ($user['balance'] ?? 0) > 0 ? 'text-red-500' : 'text-gray-500'; ?>"></i>
                        </div>
                    </div>
                </div>

                <!-- Membership Status -->
                <div class="bg-white rounded-xl shadow-sm p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 mb-1">Membership Status</p>
                            <div class="mb-1"><?php echo getMembershipStatusBadge($user); ?></div>
                            <?php if (isset($user['last_payment_date']) && $user['last_payment_date']): ?>
                                <p class="text-sm text-gray-500">Last: <?php echo date('M d, Y', strtotime($user['last_payment_date'])); ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center">
                            <i class="fas fa-id-card text-blue-500"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Payments -->
            <?php if ($recent_payments && !empty($recent_payments)): ?>
            <div class="bg-white rounded-xl shadow-sm p-6 mb-8">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-xl font-semibold text-gray-800">Recent Payments</h2>
                    <a href="payment.php" class="text-sm text-blue-500 hover:text-blue-600">View All</a>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($recent_payments as $payment): 
                        // Ensure payment data is valid before proceeding
                        if (!isset($payment['amount']) || !isset($payment['payment_status']) || !isset($payment['description']) || !isset($payment['payment_date']) || !isset($payment['payment_method'])) {
                            continue; // Skip invalid payments
                        }
                    ?>
                    <div class="border border-gray-200 rounded-lg p-4">
                        <div class="flex items-center justify-between mb-2">
                            <span class="font-medium text-gray-800">₱<?php echo number_format($payment['amount'], 2); ?></span>
                            <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo formatPaymentStatus($payment['payment_status']); ?>">
                                <?php echo htmlspecialchars($payment['payment_status']); ?>
                            </span>
                        </div>
                        <p class="text-sm text-gray-600 mb-1"><?php echo htmlspecialchars($payment['description']); ?></p>
                        <p class="text-xs text-gray-500"><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></p>
                        <p class="text-xs text-gray-500"><?php echo htmlspecialchars($payment['payment_method']); ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- AI-Powered Recommendations -->
            <div class="bg-gradient-to-r from-blue-50 to-purple-50 rounded-xl shadow-sm p-6 mb-8">
                <div class="flex items-center mb-4">
                    <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center mr-4">
                        <i class="fas fa-brain text-white text-xl"></i>
                    </div>
                    <div>
                        <h2 class="text-2xl font-semibold text-gray-800">AI-Powered Plan Recommendations</h2>
                        <p class="text-gray-600">Based on your profile and fitness data</p>
                    </div>
                </div>
                <?php
                // If walang recommendations, gamitin ang top 3 plans bilang default
                $recommendations = isset($analytics['recommendations']) && is_array($analytics['recommendations']) ? $analytics['recommendations'] : [];
                if (empty($recommendations)) {
                    $recommendations = is_array($available_plans) ? array_slice($available_plans, 0, 3) : [];
                }
                ?>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                    <?php 
                    $rank = 1;
                    // Kung personalized, $recommendations ay associative array, kung default, indexed array
                    foreach ($recommendations as $plan_id => $data): 
                        if (isset($data['plan'])) {
                            $plan = $data['plan'];
                            $score = $data['score'];
                            $reasons = $data['reasons'];
                            $daily_cost = $data['daily_cost'];
                        } else {
                            $plan = $data;
                            $score = null;
                            $reasons = [];
                            $daily_cost = isset($plan['price']) && isset($plan['duration']) && $plan['duration'] > 0 
                                ? $plan['price'] / $plan['duration'] 
                                : 0;
                        }
                        
                        // Ensure plan data is valid before proceeding
                        if (!isset($plan['name']) || !isset($plan['price']) || !isset($plan['duration'])) {
                            continue; // Skip invalid plans
                        }
                    ?>
                    <div class="bg-white rounded-lg p-4 border-l-4 border-blue-500">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-sm font-medium text-blue-600">#<?php echo $rank; ?> Recommendation</span>
                            <span class="text-sm font-bold text-gray-800">
                                <?php echo $score !== null ? 'Score: ' . $score : 'Recommended'; ?>
                            </span>
                        </div>
                        <h3 class="font-semibold text-gray-800 mb-1"><?php echo htmlspecialchars($plan['name']); ?></h3>
                        <p class="text-sm text-gray-600 mb-2">₱<?php echo number_format($plan['price'], 2); ?> / <?php echo $plan['duration']; ?> days</p>
                        <p class="text-xs text-gray-500">Daily cost: ₱<?php echo number_format($daily_cost, 2); ?></p>
                        <div class="mt-3">
                            <p class="text-xs font-medium text-gray-700 mb-1">Why this plan:</p>
                            <ul class="text-xs text-gray-600 space-y-1">
                                <?php if (!empty($reasons) && is_array($reasons)) {
                                    foreach (array_slice($reasons, 0, 2) as $reason): ?>
                                        <li class="flex items-start">
                                            <i class="fas fa-check-circle text-green-500 mr-1 mt-0.5 text-xs"></i>
                                            <?php echo htmlspecialchars($reason); ?>
                                        </li>
                                <?php endforeach; 
                                } else { ?>
                                    <li class="flex items-start">
                                        <i class="fas fa-check-circle text-green-500 mr-1 mt-0.5 text-xs"></i>
                                        Popular plan for new members
                                    </li>
                                <?php } ?>
                            </ul>
                        </div>
                    </div>
                    <?php 
                    $rank++;
                    endforeach; 
                    ?>
                </div>
            </div>

            <!-- Membership Plans -->
            <div class="mb-8">
                <h2 class="text-2xl font-semibold text-gray-800 mb-6">Choose Your Plan</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php 
                    $rank = 1;
                    foreach ($available_plans as $plan): 
                        // Ensure plan data is valid before proceeding
                        if (!isset($plan['id']) || !isset($plan['name']) || !isset($plan['price']) || !isset($plan['duration'])) {
                            continue; // Skip invalid plans
                        }
                        
                        $plan_data = isset($analytics['all_scores']) && isset($analytics['all_scores'][$plan['id']]) ? $analytics['all_scores'][$plan['id']] : null;
                        $is_recommended = isset($analytics['recommendations']) && isset($analytics['recommendations'][$plan['id']]);
                        $recommendation_rank = $is_recommended && isset($analytics['recommendations']) && is_array($analytics['recommendations']) ? array_search($plan['id'], array_keys($analytics['recommendations'])) + 1 : null;
                    ?>
                    <div class="bg-white rounded-xl shadow-sm overflow-hidden transition-transform hover:scale-105 flex flex-col plan-card relative">
                        <?php if ($is_recommended): ?>
                        <div class="recommendation-badge">
                            #<?php echo $recommendation_rank; ?> Pick
                        </div>
                        <?php endif; ?>
                        
                        <div class="p-6 bg-gradient-to-br from-gray-50 to-gray-100">
                            <div class="flex justify-between items-start">
                                <div>
                                    <h3 class="text-xl font-semibold text-gray-800"><?php echo htmlspecialchars($plan['name']); ?></h3>
                                    <p class="text-gray-500 text-sm mt-1">
                                        <?php 
                                        if ($plan_data && isset($plan_data['score']) && $plan_data['score'] > 80) {
                                            echo "Perfect match for you!";
                                        } elseif ($plan_data && isset($plan_data['score']) && $plan_data['score'] > 60) {
                                            echo "Great choice for your profile";
                                        } else {
                                            echo "Standard option";
                                        }
                                        ?>
                                    </p>
                                </div>
                                <div class="w-10 h-10 bg-gray-200 rounded-full flex items-center justify-center">
                                    <i class="fas fa-crown text-gray-500"></i>
                                </div>
                            </div>
                            <div class="mt-4">
                                <span class="text-3xl font-bold text-gray-800">₱<?php echo number_format($plan['price'], 2); ?></span>
                                <span class="text-gray-500">/<?php echo $plan['duration']; ?> days</span>
                            </div>
                            <?php if ($plan_data): ?>
                            <div class="mt-2">
                                <div class="flex items-center">
                                    <div class="flex-1 bg-gray-200 rounded-full h-2">
                                        <div class="bg-blue-500 h-2 rounded-full" style="width: <?php echo min(100, (isset($plan_data['score']) ? $plan_data['score'] : 0) / 100 * 100); ?>%"></div>
                                    </div>
                                    <span class="ml-2 text-xs text-gray-600"><?php echo isset($plan_data['score']) ? $plan_data['score'] : 0; ?>% match</span>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="p-6 bg-white flex-1 flex flex-col justify-between">
                            <div>
                                <ul class="space-y-3 mb-4">
                                    <?php 
                                    $benefits = [
                                        'Daily Pass' => ['Full gym access for one day', 'Locker usage', 'Basic amenities', 'Professional equipment access'],
                                        'Monthly Plan' => ['Unlimited gym access for 30 days', 'Locker & towel service', 'Professional equipment access'],
'Annual Plan' => ['Unlimited gym access for 365 days', 'Locker & towel service', 'Professional equipment access', 'Priority booking', 'Best value for money']
                                    ];
                                    $plan_benefits = isset($benefits[$plan['name']]) ? $benefits[$plan['name']] : ['Full gym access', 'Professional equipment', 'Clean facilities'];
                                    foreach (is_array($plan_benefits) ? array_slice($plan_benefits, 0, 4) : [] as $benefit): 
                                    ?>
                                    <li class="flex items-center text-gray-600">
                                        <i class="fas fa-check-circle text-green-500 mr-2"></i>
                                        <?php echo htmlspecialchars($benefit); ?>
                                    </li>
                                    <?php endforeach; ?>
                                </ul>
                                
                                <?php if ($plan_data && isset($plan_data['reasons']) && !empty($plan_data['reasons']) && is_array($plan_data['reasons']) && isset($plan_data['reasons'][0])): ?>
                                <div class="bg-blue-50 rounded-lg p-3 mb-4">
                                    <p class="text-xs font-medium text-blue-800 mb-1">Why this plan fits you:</p>
                                    <p class="text-xs text-blue-700"><?php echo htmlspecialchars($plan_data['reasons'][0]); ?></p>
                                </div>
                                <?php endif; ?>
                                                        </div>
                            
                            <form method="POST" class="w-full">
                                <input type="hidden" name="plan_id" value="<?php echo $plan['id']; ?>">
                                <button type="submit" name="select_plan" class="w-full px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium">
                                    <?php if ($is_recommended): ?>
                                    <i class="fas fa-star mr-2"></i>Choose This Plan
                                    <?php else: ?>
                                    Select Plan
                                    <?php endif; ?>
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Additional Benefits -->
            <div class="bg-white rounded-xl shadow-sm p-6">
                <h2 class="text-xl font-semibold text-gray-800 mb-4">Additional Benefits</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">

                    <div class="flex items-start space-x-4">
                        <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-dumbbell text-blue-500"></i>
                        </div>
                        <div>
                            <h3 class="font-medium text-gray-800">Equipment Access</h3>
                            <p class="text-gray-600 text-sm">Full access to state-of-the-art gym equipment and facilities.</p>
                        </div>
                    </div>
                    <div class="flex items-start space-x-4">
                        <div class="w-12 h-12 bg-purple-100 rounded-full flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-user-friends text-purple-500"></i>
                        </div>
                        <div>
                            <h3 class="font-medium text-gray-800">Personal Training</h3>
                            <p class="text-gray-600 text-sm">One-on-one sessions with certified personal trainers.</p>
                        </div>
                    </div>
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

        // Simple Working Notification System
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Initializing real-time notification system...');
            
            // Load dark mode preference
            const savedDarkMode = localStorage.getItem('darkMode');
            if (savedDarkMode === 'true') {
                document.body.classList.add('dark-mode');
                console.log('Dark mode loaded from localStorage');
            }
            
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
                            { id: 1, title: 'Membership Update', message: 'Your membership status has been updated.', read: false, type: 'info' },
                            { id: 2, title: 'Payment Received', message: 'Your payment has been processed successfully.', read: false, type: 'payment' },
                            { id: 3, title: 'Plan Expiry', message: 'Your current plan expires in 30 days.', read: false, type: 'warning' }
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
</body>
</html>
<?php include 'footer.php'; ?>
</body>
</html> 