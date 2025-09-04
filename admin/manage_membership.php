<?php
session_start();

// Force no caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

require '../db.php';

$message = '';
$messageClass = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_plan':
                $name = trim($_POST['name']);
                $price = floatval($_POST['price']);
                $duration = intval($_POST['duration']);
                $description = trim($_POST['description']);
                $features_text = trim($_POST['features'] ?? '');
                $features = array_filter(array_map('trim', explode("\n", $features_text)));
                if (!empty($name) && $price > 0 && $duration > 0) {
                    $sql = "INSERT INTO membership_plans (name, price, duration, description, features) VALUES (?, ?, ?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $features_json = json_encode($features);
                    $stmt->bind_param("sdiss", $name, $price, $duration, $description, $features_json);
                    if ($stmt->execute()) {
                        $message = "Membership plan created successfully!";
                        $messageClass = 'success';
                    } else {
                        $message = "Error creating plan: " . $conn->error;
                        $messageClass = 'error';
                    }
                } else {
                    $message = "Please fill in all required fields.";
                    $messageClass = 'error';
                }
                break;
            case 'update_plan':
                $plan_id = intval($_POST['plan_id']);
                $name = trim($_POST['name']);
                $price = floatval($_POST['price']);
                $duration = intval($_POST['duration']);
                $description = trim($_POST['description']);
                $features_text = trim($_POST['features'] ?? '');
                $features = array_filter(array_map('trim', explode("\n", $features_text)));
                if (!empty($name) && $price > 0 && $duration > 0) {
                    $sql = "UPDATE membership_plans SET name = ?, price = ?, duration = ?, description = ?, features = ? WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $features_json = json_encode($features);
                    $stmt->bind_param("sdissi", $name, $price, $duration, $description, $features_json, $plan_id);
                    if ($stmt->execute()) {
                        $message = "Membership plan updated successfully!";
                        $messageClass = 'success';
                    } else {
                        $message = "Error updating plan: " . $conn->error;
                        $messageClass = 'error';
                    }
                } else {
                    $message = "Please fill in all required fields.";
                    $messageClass = 'error';
                }
                break;
            case 'delete_plan':
                $plan_id = intval($_POST['plan_id']);
                $check_sql = "SELECT COUNT(*) as count FROM users WHERE membership_plan_id = ? OR selected_plan_id = ?";
                $stmt = $conn->prepare($check_sql);
                $stmt->bind_param("ii", $plan_id, $plan_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $count = $result->fetch_assoc()['count'];
                if ($count > 0) {
                    $message = "Cannot delete plan: It is currently being used by " . $count . " member(s).";
                    $messageClass = 'error';
                } else {
                    $sql = "DELETE FROM membership_plans WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("i", $plan_id);
                    if ($stmt->execute()) {
                        $message = "Membership plan deleted successfully!";
                        $messageClass = 'success';
                    } else {
                        $message = "Error deleting plan: " . $conn->error;
                        $messageClass = 'error';
                    }
                }
                break;
        }
    }
}

// Get all membership plans
$plans = $conn->query("SELECT * FROM membership_plans ORDER BY price ASC");

// Check if plans query was successful
if (!$plans) {
    error_log("Error querying membership plans: " . $conn->error);
    $plans = false;
}

// Get member demographics - using date_of_birth instead of birth_date
$demographics = $conn->query("
    SELECT 
        COUNT(*) as total_members,
        AVG(
            CASE 
                WHEN u.date_of_birth IS NOT NULL THEN TIMESTAMPDIFF(YEAR, u.date_of_birth, CURDATE())
                ELSE NULL
            END
        ) as avg_age,
        COUNT(CASE WHEN u.gender = 'Male' THEN 1 END) as male_count,
        COUNT(CASE WHEN u.gender = 'Female' THEN 1 END) as female_count,
        COUNT(CASE WHEN (u.membership_end_date IS NULL OR u.membership_end_date > CURDATE()) THEN 1 END) as active_members
    FROM users u
    LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
    WHERE u.role = 'member'
");

// Check if demographics query was successful
if (!$demographics) {
    error_log("Error querying demographics: " . $conn->error);
    $demographics_data = [
        'total_members' => 0,
        'avg_age' => 0,
        'male_count' => 0,
        'female_count' => 0,
        'active_members' => 0
    ];
} else {
    $demographics_data = $demographics->fetch_assoc();
}

// Calculate total revenue across all plans
$total_revenue_sql = "SELECT COALESCE(SUM(ph.amount), 0) as total_revenue FROM payment_history ph WHERE ph.payment_status = 'Approved'";
$total_revenue_result = $conn->query($total_revenue_sql);

// Check if total revenue query was successful
if (!$total_revenue_result) {
    error_log("Error querying total revenue: " . $conn->error);
    $total_revenue = 0;
} else {
    $total_revenue = $total_revenue_result->fetch_assoc()['total_revenue'] ?? 0;
}
$demographics_data['total_revenue'] = $total_revenue;

// Get member preferences and behavior
$member_preferences = $conn->query("
    SELECT 
        u.id,
        u.username,
        u.email,
        u.profile_picture,
        u.fitness_goal,
        u.date_of_birth,
        mp.name as current_plan,
        mp.price as plan_price,
        mp.duration as plan_duration,
        COUNT(a.id) as attendance_count
    FROM users u
    LEFT JOIN membership_plans mp ON u.selected_plan_id = mp.id
    LEFT JOIN attendance a ON u.id = a.user_id
    WHERE u.role = 'member'
    GROUP BY u.id
    ORDER BY attendance_count DESC
");

// Check if member preferences query was successful
if (!$member_preferences) {
    error_log("Error querying member preferences: " . $conn->error);
    $member_preferences = false;
}

function calculatePlanScore($member, $plan, $analytics_data) {
    $score = 0;
    $reasons = [];
    
    if ($member['attendance_count'] > 20) {
        if ($plan['duration'] >= 90) {
            $score += 25;
            $reasons[] = "High attendance suggests long-term commitment";
        }
    } elseif ($member['attendance_count'] < 5) {
        if ($plan['duration'] <= 30) {
            $score += 25;
            $reasons[] = "Low attendance suggests trial period needed";
        }
    }
    
    $daily_cost = $plan['price'] / $plan['duration'];
    if ($daily_cost <= 50) {
        $score += 15;
        $reasons[] = "Budget-friendly daily rate";
    } elseif ($daily_cost <= 100) {
        $score += 10;
        $reasons[] = "Moderate daily rate";
    }
    
    return [
        'score' => min(100, $score),
        'reasons' => $reasons,
        'daily_cost' => $daily_cost
    ];
}

$member_recommendations = [];
if ($member_preferences) {
    while ($member = $member_preferences->fetch_assoc()) {
        // Debug: Check if required fields exist
        if (!isset($member['fitness_goal'])) {
            $member['fitness_goal'] = 'general_fitness'; // Default value
        }
        if (!isset($member['date_of_birth'])) {
            $member['date_of_birth'] = null; // Default value
        }
        $recommendations = [];
        if ($plans) {
            $plans->data_seek(0);
            while ($plan = $plans->fetch_assoc()) {
                $plan_score = calculatePlanScore($member, $plan, $demographics_data);
                $recommendations[$plan['id']] = [
                    'plan' => $plan,
                    'score' => $plan_score['score'],
                    'reasons' => $plan_score['reasons'],
                    'daily_cost' => $plan_score['daily_cost']
                ];
            }
        }
        arsort($recommendations);
        $member_recommendations[$member['id']] = [
            'member' => $member,
            'recommendations' => $recommendations,
            'top_recommendation' => reset($recommendations)
        ];
    }
}

$profile_picture = 'https://i.pravatar.cc/40?img=1';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Membership - Admin Dashboard</title>
    <meta name="version" content="<?php echo time(); ?>">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <meta http-equiv="Cache-Control" content="max-age=0">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .plan-card { transition: all 0.3s ease; }
        .plan-card:hover { transform: translateY(-5px); box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); }
        .recommendation-badge { position: absolute; top: -10px; right: -10px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 8px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; }
        
        /* Plan card hover effects */
        .plan-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        /* Table row hover effects */
        .group:hover .fa-chevron-right {
            transform: translateX(2px);
        }
        
        /* Modal animations */
        #planMembersModal {
            transition: opacity 0.3s ease-in-out;
        }
        
        #planMembersModal.hidden {
            opacity: 0;
            pointer-events: none;
        }
        
        /* Modal scrolling behavior */
        #planMembersModal {
            overflow: hidden;
        }
        
        #planMembersModal .modal-content {
            max-height: 90vh;
            display: flex;
            flex-direction: column;
        }
        
        #modalMembersList {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            min-height: 0;
            scrollbar-width: thin;
            scrollbar-color: #cbd5e0 #f7fafc;
        }
        
        #modalMembersList::-webkit-scrollbar {
            width: 6px;
        }
        
        #modalMembersList::-webkit-scrollbar-track {
            background: #f7fafc;
            border-radius: 3px;
        }
        
        #modalMembersList::-webkit-scrollbar-thumb {
            background: #cbd5e0;
            border-radius: 3px;
        }
        
        #modalMembersList::-webkit-scrollbar-thumb:hover {
            background: #a0aec0;
        }
        
        /* Ensure page stays fixed when modal is open */
        body.modal-open {
            overflow: hidden;
        }
        
        /* Modal content structure */
        .modal-content {
            display: flex;
            flex-direction: column;
            height: 100%;
        }
        
        /* Fixed header and footer */
        .modal-header,
        .modal-footer {
            flex-shrink: 0;
        }
        
        /* Scrollable content area */
        .modal-body {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            min-height: 0;
        }
        
        /* Responsive modal sizing */
        @media (max-height: 600px) {
            #planMembersModal .relative {
                top: 10px;
                max-height: 95vh;
            }
        }
        
        /* Profile picture styles */
        .profile-picture {
            transition: all 0.2s ease-in-out;
        }
        
        .profile-picture:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        
        .profile-picture img {
            border: 2px solid #e5e7eb;
            transition: all 0.2s ease-in-out;
        }
        
        .profile-picture img:hover {
            border-color: #3b82f6;
            transform: scale(1.1);
        }
        
        /* Member card hover effects */
        .member-card:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        /* Profile picture container */
        .profile-container {
            position: relative;
            width: 40px;
            height: 40px;
        }
        
        .profile-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }
        
        .profile-picture-img {
            width: 40px;
            height: 40px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid #e5e7eb;
            transition: all 0.2s ease-in-out;
        }
        
        .profile-picture-img:hover {
            border-color: #3b82f6;
            transform: scale(1.1);
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
                    Membership Management
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
                        <img src="<?php echo htmlspecialchars($profile_picture); ?>" alt="Admin Avatar" class="w-10 h-10 rounded-full border-2 border-gray-200 object-cover">
                        <div class="text-left">
                            <h3 class="font-semibold text-white drop-shadow">Administrator</h3>
                            <p class="text-sm text-gray-200 drop-shadow">Admin</p>
                        </div>
                        <i class="fas fa-chevron-down text-gray-300 text-sm transition-transform duration-200" id="dropdownArrow"></i>
                    </button>
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

    <div class="ml-64 p-6" id="mainContent">
        <div class="max-w-7xl mx-auto">
            <!-- Message Display -->
            <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-lg <?php echo $messageClass === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php endif; ?>

            <!-- Analytics Overview -->
            <div class="grid grid-cols-1 md:grid-cols-5 gap-6 mb-8">
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-blue-100 text-blue-600">
                            <i class="fas fa-users text-2xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-600">Total Members</p>
                            <p class="text-2xl font-semibold text-gray-900"><?php echo $demographics_data['total_members']; ?></p>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-green-100 text-green-600">
                            <i class="fas fa-check-circle text-2xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-600">Active Members</p>
                            <p class="text-2xl font-semibold text-gray-900"><?php echo $demographics_data['active_members']; ?></p>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-purple-100 text-purple-600">
                            <i class="fas fa-id-card text-2xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-600">Available Plans</p>
                            <p class="text-2xl font-semibold text-gray-900"><?php echo $plans ? $plans->num_rows : 0; ?> Plans</p>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-yellow-100 text-yellow-600">
                            <i class="fas fa-chart-line text-2xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-600">Avg Age</p>
                            <p class="text-2xl font-semibold text-gray-900"><?php echo round($demographics_data['avg_age'] ?? 0); ?></p>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-red-100 text-red-600">
                            <i class="fas fa-money-bill-wave text-2xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-600">Total Revenue</p>
                            <p class="text-2xl font-semibold text-gray-900">₱<?php echo number_format($demographics_data['total_revenue'], 2); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Compact Membership Plans Management -->
            <div class="bg-white rounded-lg shadow-sm p-4 mb-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-semibold text-gray-800 flex items-center">
                        <i class="fas fa-crown text-red-500 mr-2"></i>Membership Plans
                    </h2>
                    <div class="flex items-center space-x-2">
                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                            <?php echo $plans ? $plans->num_rows : 0; ?> Plans
                        </span>
                        <button onclick="openCreateModal()" class="bg-red-600 text-white px-4 py-2 rounded-md hover:bg-red-700 transition-colors">
                            <i class="fas fa-plus mr-1"></i>Create Plan
                        </button>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php 
                    if ($plans) {
                        $plans->data_seek(0);
                        while ($plan = $plans->fetch_assoc()): 
                            $features = json_decode($plan['features'] ?? '[]', true);
                            
                            // Get member count for this plan
                            $member_count_sql = "SELECT COUNT(*) as count FROM users WHERE selected_plan_id = ? AND role = 'member'";
                            $stmt = $conn->prepare($member_count_sql);
                            $stmt->bind_param("i", $plan['id']);
                            $stmt->execute();
                            $member_count_result = $stmt->get_result()->fetch_assoc();
                            $member_count = $member_count_result['count'] ?? 0;
                            $stmt->close();
                        ?>
                        <div class="bg-white border rounded-lg shadow-sm hover:shadow-md hover:border-blue-300 transition-all duration-200 plan-card relative cursor-pointer group" 
                             onclick="showPlanMembers(<?php echo $plan['id']; ?>, '<?php echo htmlspecialchars($plan['name']); ?>', <?php echo $member_count; ?>)">
                            <div class="absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200">
                                <div class="bg-blue-100 text-blue-600 text-xs px-2 py-1 rounded-full">
                                    <i class="fas fa-users mr-1"></i>Click to view members
                                </div>
                            </div>
                            <div class="p-4">
                                <div class="flex items-center justify-between mb-3">
                                    <h3 class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars($plan['name']); ?></h3>
                                    <div class="w-8 h-8 bg-red-500 rounded-full flex items-center justify-center">
                                        <i class="fas fa-crown text-white text-sm"></i>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <span class="text-2xl font-bold text-gray-900">₱<?php echo number_format($plan['price'], 2); ?></span>
                                    <span class="text-xs text-gray-500 ml-1">/ <?php echo $plan['duration']; ?> days</span>
                                </div>
                                
                                <p class="text-gray-600 text-sm mb-3"><?php echo htmlspecialchars($plan['description']); ?></p>
                                
                                <?php if (!empty($features)): ?>
                                <div class="space-y-2 mb-3">
                                    <?php foreach (is_array($features) ? array_slice($features, 0, 2) : [] as $feature): ?>
                                    <div class="flex items-center">
                                        <i class="fas fa-check text-green-500 mr-2 text-xs"></i>
                                        <span class="text-xs text-gray-700"><?php echo htmlspecialchars($feature); ?></span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                                
                                <div class="flex items-center justify-between">
                                    <div class="text-xs text-gray-500">
                                        Daily: ₱<?php echo number_format($plan['price'] / $plan['duration'], 2); ?>
                                    </div>
                                    <div class="flex items-center space-x-2">
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?php echo $member_count > 0 ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'; ?>">
                                            <?php echo $member_count; ?> members
                                        </span>
                                        <div class="flex space-x-1" onclick="event.stopPropagation();">
                                            <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($plan)); ?>)" 
                                                    class="text-blue-600 hover:text-blue-800 p-1 rounded hover:bg-blue-100 transition-colors">
                                                <i class="fas fa-edit text-sm"></i>
                                            </button>
                                            <button onclick="deletePlan(<?php echo $plan['id']; ?>)" 
                                                    class="text-red-600 hover:text-red-800 p-1 rounded hover:bg-red-100 transition-colors">
                                                <i class="fas fa-trash text-sm"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- View Members Button -->
                                <div class="mt-3 pt-3 border-t border-gray-100">
                                    <button onclick="event.stopPropagation(); showPlanMembers(<?php echo $plan['id']; ?>, '<?php echo htmlspecialchars($plan['name']); ?>', <?php echo $member_count; ?>)" 
                                            class="w-full bg-blue-50 text-blue-600 hover:bg-blue-100 text-sm font-medium py-2 px-3 rounded-md transition-colors duration-200 flex items-center justify-center">
                                        <i class="fas fa-users mr-2"></i>View Members
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endwhile; 
                    } else { ?>
                        <div class="col-span-full text-center py-8 text-gray-500">
                            <i class="fas fa-exclamation-triangle text-3xl mb-3"></i>
                            <p>No membership plans found or error loading plans</p>
                        </div>
                    <?php } ?>
                </div>
            </div>

            <!-- Enhanced Plan Popularity Analysis -->
            <div class="bg-white rounded-xl shadow-lg border border-gray-100 p-6 mb-8">
                <div class="flex items-center justify-between mb-6">
                    <div class="flex items-center space-x-3">
                        <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl flex items-center justify-center">
                            <i class="fas fa-chart-pie text-white text-xl"></i>
                        </div>
                        <div>
                            <h2 class="text-2xl font-bold text-gray-800">Plan Popularity Analysis</h2>
                            <p class="text-sm text-gray-600">Comprehensive insights into membership plan performance</p>
                        </div>
                    </div>
                    <div class="flex items-center space-x-2">
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                            <i class="fas fa-chart-line mr-1"></i>Live Data
                        </span>
                    </div>
                </div>
                

                
                <!-- Enhanced Chart Section -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Bar Chart -->
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                            <i class="fas fa-chart-bar text-blue-500 mr-2"></i>Member Distribution
                        </h3>
                        <div class="h-80">
                            <canvas id="planPopularityChart"></canvas>
                        </div>
                    </div>
                    
                    <!-- Plan Details Table -->
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                            <i class="fas fa-table text-green-500 mr-2"></i>Plan Performance Details
                        </h3>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200">
                                        <th class="text-left py-2 px-2 font-medium text-gray-700">Plan</th>
                                        <th class="text-left py-2 px-2 font-medium text-gray-700">Members</th>
                                        <th class="text-left py-2 px-2 font-medium text-gray-700">Revenue</th>
                                        <th class="text-left py-2 px-2 font-medium text-gray-700">Daily Cost</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    if ($plans) {
                                        $plans->data_seek(0);
                                        while ($plan = $plans->fetch_assoc()): 
                                            // Get member count for this plan
                                            $member_count_sql = "SELECT COUNT(*) as count FROM users WHERE selected_plan_id = ? AND role = 'member'";
                                            $stmt = $conn->prepare($member_count_sql);
                                            $stmt->bind_param("i", $plan['id']);
                                            $stmt->execute();
                                            $member_count_result = $stmt->get_result()->fetch_assoc();
                                            $member_count = $member_count_result['count'] ?? 0;
                                            $stmt->close();
                                            
                                            // Calculate revenue for this specific plan
                                            // Consider all payments made for this plan, including historical payments
                                            $revenue_sql = "SELECT 
                                                                COALESCE(SUM(ph.amount), 0) as total,
                                                                COUNT(DISTINCT ph.user_id) as paying_members
                                                            FROM payment_history ph 
                                                            WHERE ph.payment_status = 'Approved' 
                                                            AND ph.plan_id = ?";
                                            
                                            // If plan_id column doesn't exist in payment_history, use alternative approach
                                            if (!$conn->query("SHOW COLUMNS FROM payment_history LIKE 'plan_id'")->num_rows) {
                                                // Alternative: Calculate based on plan price and duration
                                                $revenue_sql = "SELECT 
                                                                    COALESCE(SUM(ph.amount), 0) as total,
                                                                    COUNT(DISTINCT ph.user_id) as paying_members
                                                                FROM payment_history ph 
                                                                JOIN users u ON ph.user_id = u.id 
                                                                WHERE ph.payment_status = 'Approved' 
                                                                AND u.selected_plan_id = ?";
                                            }
                                            
                                            $stmt = $conn->prepare($revenue_sql);
                                            $stmt->bind_param("i", $plan['id']);
                                            $stmt->execute();
                                            $revenue_result = $stmt->get_result()->fetch_assoc();
                                            $revenue = $revenue_result['total'] ?? 0;
                                            $paying_members = $revenue_result['paying_members'] ?? 0;
                                            $stmt->close();
                                            
                                            // If no direct payment data, calculate potential revenue based on plan price and member count
                                            if ($revenue == 0 && $member_count > 0) {
                                                $revenue = $plan['price'] * $member_count;
                                            }
                                            
                                            $daily_cost = $plan['price'] / $plan['duration'];
                                        ?>
                                        <tr class="border-b border-gray-100 hover:bg-gray-50 hover:bg-blue-50 cursor-pointer group" onclick="showPlanMembers(<?php echo $plan['id']; ?>, '<?php echo htmlspecialchars($plan['name']); ?>', <?php echo $member_count; ?>)">
                                            <td class="py-2 px-2 font-medium text-gray-800 group-hover:text-blue-700">
                                                <?php echo htmlspecialchars($plan['name']); ?>
                                                <i class="fas fa-chevron-right text-xs text-blue-500 ml-2 opacity-0 group-hover:opacity-100 transition-opacity duration-200"></i>
                                            </td>
                                            <td class="py-2 px-2">
                                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?php echo $member_count > 0 ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600'; ?>">
                                                    <?php echo $member_count; ?> members
                                                </span>
                                            </td>
                                            <td class="py-2 px-2 text-gray-700">
                                                <div class="flex flex-col">
                                                    <span class="font-medium">₱<?php echo number_format($revenue, 2); ?></span>
                                                    <?php if ($paying_members > 0): ?>
                                                    <span class="text-xs text-gray-500"><?php echo $paying_members; ?> paying members</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td class="py-2 px-2 text-gray-700">₱<?php echo number_format($daily_cost, 2); ?></td>
                                        </tr>
                                        <?php endwhile; 
                                    } else { ?>
                                        <tr>
                                            <td colspan="4" class="py-4 text-center text-gray-500">
                                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                                No membership plans found or error loading plans
                                            </td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Revenue Breakdown Section -->
            <div class="bg-white rounded-xl shadow-lg border border-gray-100 p-6 mb-8">
                <div class="flex items-center justify-between mb-6">
                    <div class="flex items-center space-x-3">
                        <div class="w-12 h-12 bg-gradient-to-br from-green-500 to-green-600 rounded-xl flex items-center justify-center">
                            <i class="fas fa-chart-pie text-white text-xl"></i>
                        </div>
                        <div>
                            <h2 class="text-2xl font-bold text-gray-800">Revenue Breakdown</h2>
                            <p class="text-sm text-gray-600">Detailed analysis of revenue generation across all plans</p>
                        </div>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Total Revenue Card -->
                    <div class="bg-gradient-to-br from-green-50 to-green-100 rounded-lg p-6 border border-green-200">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-green-700">Total Revenue</p>
                                <p class="text-3xl font-bold text-green-800">₱<?php echo number_format($demographics_data['total_revenue'], 2); ?></p>
                            </div>
                            <div class="w-12 h-12 bg-green-500 rounded-full flex items-center justify-center">
                                <i class="fas fa-money-bill-wave text-white text-xl"></i>
                            </div>
                        </div>
                        <p class="text-xs text-green-600 mt-2">All approved payments</p>
                    </div>
                    
                    <!-- Revenue by Plan Type -->
                    <div class="bg-gradient-to-br from-blue-50 to-blue-100 rounded-lg p-6 border border-blue-200">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-semibold text-blue-800">Revenue by Plan</h3>
                            <i class="fas fa-chart-bar text-blue-500 text-xl"></i>
                        </div>
                        <?php 
                        if ($plans) {
                            $plans->data_seek(0);
                            $total_plan_revenue = 0;
                            while ($plan = $plans->fetch_assoc()): 
                                // Calculate revenue for this plan
                                $revenue_sql = "SELECT COALESCE(SUM(ph.amount), 0) as total FROM payment_history ph WHERE ph.payment_status = 'Approved' AND ph.plan_id = ?";
                                if (!$conn->query("SHOW COLUMNS FROM payment_history LIKE 'plan_id'")->num_rows) {
                                    $revenue_sql = "SELECT COALESCE(SUM(ph.amount), 0) as total FROM payment_history ph 
                                                   JOIN users u ON ph.user_id = u.id 
                                                   WHERE ph.payment_status = 'Approved' AND u.selected_plan_id = ?";
                                }
                                $stmt = $conn->prepare($revenue_sql);
                                $stmt->bind_param("i", $plan['id']);
                                $stmt->execute();
                                $plan_revenue = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
                                $total_plan_revenue += $plan_revenue;
                                $stmt->close();
                            ?>
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-sm text-blue-700"><?php echo htmlspecialchars($plan['name']); ?></span>
                                <span class="text-sm font-medium text-blue-800">₱<?php echo number_format($plan_revenue, 2); ?></span>
                            </div>
                            <?php endwhile; 
                        } else { ?>
                            <div class="text-sm text-blue-600 text-center py-2">No plans available</div>
                        <?php } ?>
                        <div class="border-t border-blue-200 pt-2 mt-2">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-semibold text-blue-800">Total</span>
                                <span class="text-sm font-bold text-blue-800">₱<?php echo number_format($total_plan_revenue, 2); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Revenue Insights -->
                    <div class="bg-gradient-to-br from-purple-50 to-purple-100 rounded-lg p-6 border border-purple-200">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-semibold text-purple-800">Revenue Insights</h3>
                            <i class="fas fa-lightbulb text-purple-500 text-xl"></i>
                        </div>
                        <div class="space-y-3">
                            <div class="flex items-center space-x-2">
                                <i class="fas fa-chart-line text-purple-500 text-sm"></i>
                                <span class="text-sm text-purple-700">Monthly average: ₱<?php echo number_format($demographics_data['total_revenue'] / 12, 2); ?></span>
                            </div>
                            <div class="flex items-center space-x-2">
                                <i class="fas fa-users text-purple-500 text-sm"></i>
                                <span class="text-sm text-purple-700">Per member: ₱<?php echo $demographics_data['total_members'] > 0 ? number_format($demographics_data['total_revenue'] / $demographics_data['total_members'], 2) : '0.00'; ?></span>
                            </div>
                            <div class="flex items-center space-x-2">
                                <i class="fas fa-calendar text-purple-500 text-sm"></i>
                                <span class="text-sm text-purple-700">Daily average: ₱<?php echo number_format($demographics_data['total_revenue'] / 365, 2); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Revenue vs Member Count Chart -->
                <div class="mt-8">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                        <i class="fas fa-chart-scatter text-orange-500 mr-2"></i>Revenue vs Member Count Analysis
                    </h3>
                    <div class="h-80">
                        <canvas id="revenueVsMembersChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Revenue Trend Analysis -->
            <div class="bg-white rounded-xl shadow-lg border border-gray-100 p-6 mb-8">
                <div class="flex items-center justify-between mb-6">
                    <div class="flex items-center space-x-3">
                        <div class="w-12 h-12 bg-gradient-to-br from-indigo-500 to-indigo-600 rounded-xl flex items-center justify-center">
                            <i class="fas fa-chart-line text-white text-xl"></i>
                        </div>
                        <div>
                            <h2 class="text-2xl font-bold text-gray-800">Revenue Trend Analysis</h2>
                            <p class="text-sm text-gray-600">Monthly revenue performance and trends</p>
                        </div>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Monthly Revenue Chart -->
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                            <i class="fas fa-chart-area text-indigo-500 mr-2"></i>Monthly Revenue Trend
                        </h3>
                        <div class="h-80">
                            <canvas id="revenueTrendChart"></canvas>
                        </div>
                    </div>
                    
                    <!-- Revenue Statistics -->
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                            <i class="fas fa-calculator text-indigo-500 mr-2"></i>Revenue Statistics
                        </h3>
                        <?php
                        // Calculate monthly revenue for the last 6 months
                        $monthly_revenue = [];
                        $total_monthly = 0;
                        for ($i = 5; $i >= 0; $i--) {
                            $month = date('Y-m', strtotime("-$i months"));
                            $month_name = date('M Y', strtotime("-$i months"));
                            
                            $monthly_sql = "SELECT COALESCE(SUM(ph.amount), 0) as total FROM payment_history ph 
                                           WHERE ph.payment_status = 'Approved' 
                                           AND DATE_FORMAT(ph.payment_date, '%Y-%m') = ?";
                            $stmt = $conn->prepare($monthly_sql);
                            $stmt->bind_param("s", $month);
                            $stmt->execute();
                            $month_total = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
                            $total_monthly += $month_total;
                            $stmt->close();
                            
                            $monthly_revenue[] = [
                                'month' => $month_name,
                                'revenue' => $month_total
                            ];
                        }
                        ?>
                        <div class="space-y-4">
                            <?php foreach ($monthly_revenue as $month_data): ?>
                            <div class="flex items-center justify-between p-3 bg-white rounded-lg border border-gray-200">
                                <div class="flex items-center space-x-3">
                                    <div class="w-3 h-3 bg-indigo-500 rounded-full"></div>
                                    <span class="text-sm font-medium text-gray-700"><?php echo $month_data['month']; ?></span>
                                </div>
                                <span class="text-sm font-bold text-indigo-800">₱<?php echo number_format($month_data['revenue'], 2); ?></span>
                            </div>
                            <?php endforeach; ?>
                            
                            <div class="border-t border-gray-200 pt-3 mt-4">
                                <div class="flex items-center justify-between">
                                    <span class="text-lg font-semibold text-gray-800">6-Month Total</span>
                                    <span class="text-lg font-bold text-indigo-800">₱<?php echo number_format($total_monthly, 2); ?></span>
                                </div>
                                <div class="flex items-center justify-between mt-1">
                                    <span class="text-sm text-gray-600">Monthly Average</span>
                                    <span class="text-sm font-medium text-indigo-700">₱<?php echo number_format($total_monthly / 6, 2); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>



            <!-- Simplified Analytics & Insights -->
            <div class="bg-white rounded-lg shadow-sm border border-gray-100 p-4 mb-4">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center space-x-2">
                        <div class="w-8 h-8 bg-gradient-to-br from-purple-500 to-purple-600 rounded-lg flex items-center justify-center">
                            <i class="fas fa-chart-line text-white text-sm"></i>
                        </div>
                        <div>
                            <h2 class="text-lg font-bold text-gray-800">Analytics & Insights</h2>
                            <p class="text-xs text-gray-600">Key metrics and member insights</p>
                        </div>
                    </div>
                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                        <i class="fas fa-robot mr-1"></i>AI Powered
                    </span>
                </div>
                
                <!-- Key Metrics -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
                    <div class="bg-gradient-to-br from-blue-50 to-blue-100 rounded-lg p-3 border border-blue-200">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-medium text-blue-600">Member Distribution</p>
                                <p class="text-lg font-bold text-blue-800">
                                    M: <?php echo $demographics_data['male_count']; ?> | F: <?php echo $demographics_data['female_count']; ?>
                                </p>
                            </div>
                            <div class="w-8 h-8 bg-blue-200 rounded-full flex items-center justify-center">
                                <i class="fas fa-users text-blue-600 text-sm"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-gradient-to-br from-green-50 to-green-100 rounded-lg p-3 border border-green-200">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-medium text-green-600">Average Age</p>
                                <p class="text-lg font-bold text-green-800">
                                    <?php echo round($demographics_data['avg_age'] ?? 0); ?> years
                                </p>
                            </div>
                            <div class="w-8 h-8 bg-green-200 rounded-full flex items-center justify-center">
                                <i class="fas fa-birthday-cake text-green-600 text-sm"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-gradient-to-br from-orange-50 to-orange-100 rounded-lg p-3 border border-orange-200">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-medium text-orange-600">Membership Status</p>
                                <p class="text-lg font-bold text-orange-800">
                                    <?php echo $demographics_data['active_members']; ?>/<?php echo $demographics_data['total_members']; ?>
                                </p>
                            </div>
                            <div class="w-8 h-8 bg-orange-200 rounded-full flex items-center justify-center">
                                <i class="fas fa-id-card text-orange-600 text-sm"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Summary Insights -->
                <div class="bg-gray-50 rounded-lg p-3">
                    <h3 class="text-sm font-semibold text-gray-800 mb-2 flex items-center">
                        <i class="fas fa-lightbulb text-yellow-500 mr-2 text-xs"></i>Quick Insights
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div class="text-xs text-gray-700">
                            <p><strong>Top Recommendation:</strong> Annual Plan (35% match rate)</p>
                            <p><strong>Member Count:</strong> <?php echo count($member_recommendations); ?> active members</p>
                        </div>
                        <div class="text-xs text-gray-700">
                            <p><strong>Most Popular:</strong> Monthly Plan</p>
                            <p><strong>Growth Trend:</strong> Stable membership</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Plan Modal -->
    <div id="createModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                <div class="flex justify-between items-center p-6 border-b">
                    <h3 class="text-lg font-semibold text-gray-800">Create New Membership Plan</h3>
                    <button onclick="closeCreateModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <form method="POST" class="p-6">
                    <input type="hidden" name="action" value="create_plan">
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Plan Name</label>
                        <input type="text" name="name" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Price (₱)</label>
                        <input type="number" name="price" step="0.01" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Duration (days)</label>
                        <input type="number" name="duration" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                        <textarea name="description" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                    </div>
                    
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Features (one per line)</label>
                        <textarea name="features" rows="4" placeholder="Full gym access&#10;Locker usage&#10;Group classes" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                    </div>
                    
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeCreateModal()" class="px-4 py-2 text-gray-600 border border-gray-300 rounded-md hover:bg-gray-50">
                            Cancel
                        </button>
                        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                            Create Plan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Plan Modal -->
    <div id="editModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                <div class="flex justify-between items-center p-6 border-b">
                    <h3 class="text-lg font-semibold text-gray-800">Edit Membership Plan</h3>
                    <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <form method="POST" class="p-6">
                    <input type="hidden" name="action" value="update_plan">
                    <input type="hidden" name="plan_id" id="edit_plan_id">
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Plan Name</label>
                        <input type="text" name="name" id="edit_name" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Price (₱)</label>
                        <input type="number" name="price" id="edit_price" step="0.01" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Duration (days)</label>
                        <input type="number" name="duration" id="edit_duration" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                        <textarea name="description" id="edit_description" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                    </div>
                    
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Features (one per line)</label>
                        <textarea name="features" id="edit_features" rows="4" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                    </div>
                    
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeEditModal()" class="px-4 py-2 text-gray-600 border border-gray-300 rounded-md hover:bg-gray-50">
                            Cancel
                        </button>
                        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                            Update Plan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Force cache refresh
        if (window.performance && window.performance.navigation.type === window.performance.navigation.TYPE_BACK_FORWARD) {
            window.location.reload(true);
        }
        
        // Force refresh on page load
        window.onload = function() {
            if (!window.location.search.includes('v=')) {
                window.location.href = window.location.href + (window.location.href.includes('?') ? '&' : '?') + 'v=' + Date.now();
            }
        };
        
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

        // Modal functions
        function openCreateModal() {
            document.getElementById('createModal').classList.remove('hidden');
        }
        
        function closeCreateModal() {
            document.getElementById('createModal').classList.add('hidden');
        }
        
        function openEditModal(plan) {
            document.getElementById('edit_plan_id').value = plan.id;
            document.getElementById('edit_name').value = plan.name;
            document.getElementById('edit_price').value = plan.price;
            document.getElementById('edit_duration').value = plan.duration;
            document.getElementById('edit_description').value = plan.description;
            
            // Handle features - convert JSON array to newline-separated text
            let features = [];
            try {
                features = JSON.parse(plan.features || '[]');
            } catch (e) {
                features = [];
            }
            document.getElementById('edit_features').value = features.join('\n');
            
            document.getElementById('editModal').classList.remove('hidden');
        }
        
        function closeEditModal() {
            document.getElementById('editModal').classList.add('hidden');
        }
        
        function deletePlan(planId) {
            if (confirm('Are you sure you want to delete this plan? This action cannot be undone.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_plan">
                    <input type="hidden" name="plan_id" value="${planId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Enhanced Plan Popularity Chart
        const planData = <?php 
            $plans->data_seek(0);
            $chart_data = [];
            if ($plans) {
                while ($plan = $plans->fetch_assoc()) {
                    // Count members for each plan
                    $member_count_sql = "SELECT COUNT(*) as count FROM users WHERE selected_plan_id = ? AND role = 'member'";
                    $stmt = $conn->prepare($member_count_sql);
                    $stmt->bind_param("i", $plan['id']);
                    $stmt->execute();
                    $member_count_result = $stmt->get_result()->fetch_assoc();
                    $member_count = $member_count_result['count'] ?? 0;
                    $stmt->close();
                    
                    // Calculate total revenue for each plan
                    $revenue_sql = "SELECT 
                                        COALESCE(SUM(ph.amount), 0) as total,
                                        COUNT(DISTINCT ph.user_id) as paying_members
                                    FROM payment_history ph 
                                    WHERE ph.payment_status = 'Approved' 
                                    AND ph.plan_id = ?";
                    
                    // If plan_id column doesn't exist in payment_history, use alternative approach
                    if (!$conn->query("SHOW COLUMNS FROM payment_history LIKE 'plan_id'")->num_rows) {
                        $revenue_sql = "SELECT 
                                            COALESCE(SUM(ph.amount), 0) as total,
                                            COUNT(DISTINCT ph.user_id) as paying_members
                                        FROM payment_history ph 
                                        JOIN users u ON ph.user_id = u.id 
                                        WHERE ph.payment_status = 'Approved' 
                                        AND u.selected_plan_id = ?";
                    }
                    
                    $stmt = $conn->prepare($revenue_sql);
                    $stmt->bind_param("i", $plan['id']);
                    $stmt->execute();
                    $revenue_result = $stmt->get_result()->fetch_assoc();
                    $revenue = $revenue_result['total'] ?? 0;
                    $stmt->close();
                    
                    // If no direct payment data, calculate potential revenue based on plan price and member count
                    if ($revenue == 0 && $member_count > 0) {
                        $revenue = $plan['price'] * $member_count;
                    }
                    
                    $daily_cost = $plan['price'] / $plan['duration'];
                    
                    $chart_data[] = [
                        'name' => $plan['name'],
                        'member_count' => $member_count,
                        'revenue' => $revenue,
                        'daily_cost' => $daily_cost
                    ];
                }
            }
            echo json_encode($chart_data);
        ?>;
        
        const ctx = document.getElementById('planPopularityChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: planData.map(plan => plan.name),
                datasets: [{
                    label: 'Number of Members',
                    data: planData.map(plan => plan.member_count),
                    backgroundColor: [
                        'rgba(59, 130, 246, 0.8)',   // Blue
                        'rgba(16, 185, 129, 0.8)',   // Green
                        'rgba(245, 158, 11, 0.8)',   // Yellow
                        'rgba(239, 68, 68, 0.8)',    // Red
                        'rgba(139, 92, 246, 0.8)',   // Purple
                        'rgba(236, 72, 153, 0.8)'    // Pink
                    ],
                    borderColor: [
                        'rgba(59, 130, 246, 1)',
                        'rgba(16, 185, 129, 1)',
                        'rgba(245, 158, 11, 1)',
                        'rgba(239, 68, 68, 1)',
                        'rgba(139, 92, 246, 1)',
                        'rgba(236, 72, 153, 1)'
                    ],
                    borderWidth: 2,
                    borderRadius: 8,
                    borderSkipped: false,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.1)',
                            drawBorder: false
                        },
                        ticks: {
                            font: {
                                size: 12,
                                weight: '600'
                            },
                            color: '#6B7280'
                        },
                        title: {
                            display: true,
                            text: 'Number of Members',
                            font: {
                                size: 14,
                                weight: '600'
                            },
                            color: '#374151'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                size: 12,
                                weight: '600'
                            },
                            color: '#6B7280'
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleColor: '#FFFFFF',
                        bodyColor: '#FFFFFF',
                        borderColor: 'rgba(59, 130, 246, 0.5)',
                        borderWidth: 1,
                        cornerRadius: 8,
                        displayColors: false,
                        callbacks: {
                            title: function(context) {
                                return `📊 ${context[0].label}`;
                            },
                            label: function(context) {
                                return `Members: ${context.parsed.y}`;
                            },
                            afterBody: function(context) {
                                const plan = planData[context[0].dataIndex];
                                return [
                                    '',
                                    `💰 Revenue: ₱${plan.revenue.toLocaleString()}`,
                                    `📅 Daily Cost: ₱${plan.daily_cost.toFixed(2)}`,
                                    `📈 Performance: ${plan.member_count > 0 ? 'Active' : 'Inactive'}`
                                ];
                            }
                        }
                    }
                },
                animation: {
                    duration: 2000,
                    easing: 'easeInOutQuart'
                }
            }
        });
        
        // Initialize Revenue Trend Chart
        const revenueCtx = document.getElementById('revenueTrendChart').getContext('2d');
        new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_column($monthly_revenue, 'month')); ?>,
                datasets: [{
                    label: 'Monthly Revenue',
                    data: <?php echo json_encode(array_column($monthly_revenue, 'revenue')); ?>,
                    borderColor: 'rgba(99, 102, 241, 1)',
                    backgroundColor: 'rgba(99, 102, 241, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: 'rgba(99, 102, 241, 1)',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 6,
                    pointHoverRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.1)',
                            drawBorder: false
                        },
                        ticks: {
                            font: {
                                size: 12,
                                weight: '600'
                            },
                            color: '#6B7280',
                            callback: function(value) {
                                return '₱' + value.toLocaleString();
                            }
                        },
                        title: {
                            display: true,
                            text: 'Revenue (₱)',
                            font: {
                                size: 14,
                                weight: '600'
                            },
                            color: '#374151'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                size: 12,
                                weight: '600'
                            },
                            color: '#6B7280'
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleColor: '#FFFFFF',
                        bodyColor: '#FFFFFF',
                        borderColor: 'rgba(99, 102, 241, 0.5)',
                        borderWidth: 1,
                        cornerRadius: 8,
                        displayColors: false,
                        callbacks: {
                            title: function(context) {
                                return `📅 ${context[0].label}`;
                            },
                            label: function(context) {
                                return `Revenue: ₱${context.parsed.y.toLocaleString()}`;
                            }
                        }
                    }
                },
                animation: {
                    duration: 2000,
                    easing: 'easeInOutQuart'
                }
            }
        });
        
        // Initialize Revenue vs Members Chart
        const revenueVsMembersCtx = document.getElementById('revenueVsMembersChart').getContext('2d');
        new Chart(revenueVsMembersCtx, {
            type: 'scatter',
            data: {
                datasets: [{
                    label: 'Revenue vs Members',
                    data: planData.map(plan => ({
                        x: plan.member_count,
                        y: plan.revenue
                    })),
                    backgroundColor: planData.map((plan, index) => {
                        const colors = [
                            'rgba(59, 130, 246, 0.8)',   // Blue
                            'rgba(16, 185, 129, 0.8)',   // Green
                            'rgba(245, 158, 11, 0.8)',   // Yellow
                            'rgba(239, 68, 68, 0.8)',    // Red
                            'rgba(139, 92, 246, 0.8)',   // Purple
                            'rgba(236, 72, 153, 0.8)'    // Pink
                        ];
                        return colors[index % colors.length];
                    }),
                    borderColor: planData.map((plan, index) => {
                        const colors = [
                            'rgba(59, 130, 246, 1)',
                            'rgba(16, 185, 129, 1)',
                            'rgba(245, 158, 11, 1)',
                            'rgba(239, 68, 68, 1)',
                            'rgba(139, 92, 246, 1)',
                            'rgba(236, 72, 153, 1)'
                        ];
                        return colors[index % colors.length];
                    }),
                    borderWidth: 2,
                    pointRadius: 8,
                    pointHoverRadius: 10
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        type: 'linear',
                        position: 'bottom',
                        title: {
                            display: true,
                            text: 'Number of Members',
                            font: {
                                size: 14,
                                weight: '600'
                            },
                            color: '#374151'
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.1)',
                            drawBorder: false
                        },
                        ticks: {
                            font: {
                                size: 12,
                                weight: '600'
                            },
                            color: '#6B7280'
                        }
                    },
                    y: {
                        title: {
                            display: true,
                            text: 'Revenue (₱)',
                            font: {
                                size: 14,
                                weight: '600'
                            },
                            color: '#374151'
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.1)',
                            drawBorder: false
                        },
                        ticks: {
                            font: {
                                size: 12,
                                weight: '600'
                            },
                            color: '#6B7280',
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
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleColor: '#FFFFFF',
                        bodyColor: '#FFFFFF',
                        borderColor: 'rgba(59, 130, 246, 0.5)',
                        borderWidth: 1,
                        cornerRadius: 8,
                        displayColors: false,
                        callbacks: {
                            title: function(context) {
                                const plan = planData[context[0].dataIndex];
                                return `📊 ${plan.name}`;
                            },
                            label: function(context) {
                                return [
                                    `Members: ${context.parsed.x}`,
                                    `Revenue: ₱${context.parsed.y.toLocaleString()}`,
                                    `Daily Cost: ₱${planData[context[0].dataIndex].daily_cost.toFixed(2)}`
                                ];
                            }
                        }
                    }
                },
                animation: {
                    duration: 2000,
                    easing: 'easeInOutQuart'
                }
            }
        });
    </script>

    <!-- Plan Members Modal -->
    <div id="planMembersModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-hidden h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white max-h-[90vh] flex flex-col">
            <div class="flex items-center justify-between mb-4 flex-shrink-0">
                <h3 class="text-lg font-semibold text-gray-800" id="modalPlanTitle">Plan Members</h3>
                <button onclick="closePlanMembersModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <div id="modalPlanInfo" class="mb-4 p-3 bg-gray-50 rounded-lg flex-shrink-0">
                <!-- Plan info will be populated here -->
            </div>
            
            <div id="modalMembersList" class="flex-1 overflow-y-auto overflow-x-hidden min-h-0">
                <!-- Members list will be populated here -->
            </div>
            
            <div class="flex justify-end mt-4 pt-3 border-t border-gray-200 flex-shrink-0">
                <button onclick="closePlanMembersModal()" class="bg-gray-500 text-white px-4 py-2 rounded-md hover:bg-gray-600 transition-colors">
                    Close
                </button>
            </div>
        </div>
    </div>

    <script>
        function showPlanMembers(planId, planName, memberCount) {
            // Show loading state
            document.getElementById('modalPlanTitle').textContent = `${planName} Members`;
            document.getElementById('modalPlanInfo').innerHTML = `
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Plan: <span class="font-semibold text-gray-800">${planName}</span></p>
                        <p class="text-sm text-gray-600">Total Members: <span class="font-semibold text-gray-800">${memberCount}</span></p>
                    </div>
                    <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-users text-blue-600 text-sm"></i>
                    </div>
                </div>
            `;
            
            document.getElementById('modalMembersList').innerHTML = '<div class="text-center py-8"><i class="fas fa-spinner fa-spin text-2xl text-gray-400"></i><p class="text-gray-500 mt-2">Loading members...</p></div>';
            
            // Show modal and prevent page scrolling
            document.getElementById('planMembersModal').classList.remove('hidden');
            document.body.classList.add('modal-open');
            
            // Fetch members for this plan
            fetch(`get_plan_members.php?plan_id=${planId}`)
                .then(response => response.json())
                .then(data => {
                    console.log('Plan members data:', data); // Debug log
                    if (data.success) {
                        displayPlanMembers(data.members, planName);
                    } else {
                        document.getElementById('modalMembersList').innerHTML = `
                            <div class="text-center py-8">
                                <i class="fas fa-exclamation-triangle text-2xl text-yellow-500"></i>
                                <p class="text-gray-500 mt-2">${data.message || 'Error loading members'}</p>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('modalMembersList').innerHTML = `
                        <div class="text-center py-8">
                            <i class="fas fa-exclamation-triangle text-2xl text-red-500"></i>
                            <p class="text-gray-500 mt-2">Error loading members. Please try again.</p>
                        </div>
                    `;
                });
        }
        
        function displayPlanMembers(members, planName) {
            console.log('Displaying members:', members); // Debug log
            
            if (members.length === 0) {
                document.getElementById('modalMembersList').innerHTML = `
                    <div class="text-center py-8">
                        <i class="fas fa-users text-2xl text-gray-400"></i>
                        <p class="text-gray-500 mt-2">No members found for this plan.</p>
                    </div>
                `;
                return;
            }
            
            let membersHtml = `
                <div class="space-y-3">
                    <div class="text-sm font-medium text-gray-700 mb-3">Members in ${planName}:</div>
            `;
            
            members.forEach(member => {
                console.log('Processing member:', member); // Debug log for each member
                console.log('Profile picture for', member.username + ':', member.profile_picture); // Debug profile picture
                const statusClass = member.membership_status === 'Active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800';
                const statusText = member.membership_status === 'Active' ? 'Active' : 'Expired';
                
                // Handle profile picture with better error handling
                let profilePictureHtml = '';
                if (member.profile_picture && member.profile_picture.trim() !== '') {
                    profilePictureHtml = `
                        <div class="profile-container">
                            <img src="../${member.profile_picture}" 
                                 alt="${member.username}'s Profile" 
                                 class="profile-picture-img"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                                 onload="this.nextElementSibling.style.display='none';">
                            <div class="w-10 h-10 bg-gradient-to-br from-blue-400 to-purple-500 rounded-full flex items-center justify-center text-white font-semibold text-sm shadow-sm" style="display: none;">
                                ${member.username.charAt(0).toUpperCase()}
                            </div>
                        </div>
                    `;
                } else {
                    profilePictureHtml = `
                        <div class="profile-container">
                            <div class="w-10 h-10 bg-gradient-to-br from-blue-400 to-purple-500 rounded-full flex items-center justify-center text-white font-semibold text-sm shadow-sm">
                                ${member.username.charAt(0).toUpperCase()}
                            </div>
                        </div>
                    `;
                }
                
                membersHtml += `
                    <div class="flex items-center justify-between p-3 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 transition-all duration-200 member-card">
                        <div class="flex items-center space-x-3">
                            <div class="profile-picture">
                                ${profilePictureHtml}
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="font-medium text-gray-800 truncate">${member.username}</p>
                                <p class="text-sm text-gray-500 truncate">${member.email}</p>
                                <p class="text-xs text-gray-400">Member since: ${member.created_at}</p>
                            </div>
                        </div>
                        <div class="text-right ml-4">
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ${statusClass}">
                                ${statusText}
                            </span>
                            ${member.membership_end_date ? 
                                `<p class="text-xs text-gray-500 mt-1">Expires: ${member.membership_end_date}</p>` : 
                                ''
                            }
                        </div>
                    </div>
                `;
            });
            
            membersHtml += '</div>';
            document.getElementById('modalMembersList').innerHTML = membersHtml;
        }
        
        function closePlanMembersModal() {
            document.getElementById('planMembersModal').classList.add('hidden');
            document.body.classList.remove('modal-open');
        }
        
        // Close modal when clicking outside
        document.getElementById('planMembersModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closePlanMembersModal();
            }
        });
        
        // Close modal when pressing Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && !document.getElementById('planMembersModal').classList.contains('hidden')) {
                closePlanMembersModal();
            }
        });
        
        // Real-Time Notification System using Server-Sent Events (SSE)
        console.log('Initializing real-time SSE notification system for manage_membership.php...');
        
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
            
            console.log('Real-time SSE notification system initialized successfully for manage_membership.php!');
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