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
$demographics_data = $demographics->fetch_assoc();

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
while ($member = $member_preferences->fetch_assoc()) {
    // Debug: Check if required fields exist
    if (!isset($member['fitness_goal'])) {
        $member['fitness_goal'] = 'general_fitness'; // Default value
    }
    if (!isset($member['date_of_birth'])) {
        $member['date_of_birth'] = null; // Default value
    }
    $recommendations = [];
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
    arsort($recommendations);
    $member_recommendations[$member['id']] = [
        'member' => $member,
        'recommendations' => $recommendations,
        'top_recommendation' => array_key_first($recommendations)
    ];
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
                <div class="relative">
                    <button class="text-white hover:text-gray-200 p-2 rounded-full hover:bg-gray-700/30 transition-colors">
                        <i class="fas fa-bell text-lg"></i>
                    </button>
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
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
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
                            <p class="text-2xl font-semibold text-gray-900"><?php echo $plans->num_rows; ?></p>
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
            </div>

            <!-- Plan Popularity Chart -->
            <div class="bg-white rounded-lg shadow p-6 mb-8">
                <h2 class="text-xl font-semibold text-gray-800 mb-4">Plan Popularity Analysis</h2>
                <div class="h-64">
                    <canvas id="planPopularityChart"></canvas>
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
                            <?php echo $plans->num_rows; ?> Plans
                        </span>
                        <button onclick="openCreateModal()" class="bg-red-600 text-white px-4 py-2 rounded-md hover:bg-red-700 transition-colors">
                            <i class="fas fa-plus mr-1"></i>Create Plan
                        </button>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php 
                    $plans->data_seek(0);
                    while ($plan = $plans->fetch_assoc()): 
                        $features = json_decode($plan['features'] ?? '[]', true);
                    ?>
                    <div class="bg-white border rounded-lg shadow-sm hover:shadow-md transition-all duration-200 plan-card relative">
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
                                <?php foreach (array_slice($features, 0, 2) as $feature): ?>
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
                                <div class="flex space-x-1">
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
                    </div>
                    <?php endwhile; ?>
                </div>
            </div>

            <!-- Compact Predictive Analytics -->
            <div class="bg-white rounded-lg shadow-sm p-4 mb-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-semibold text-gray-800 flex items-center">
                        <i class="fas fa-chart-line text-red-500 mr-2"></i>Analytics & Recommendations
                    </h2>
                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                        AI Insights
                    </span>
                </div>
                
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <!-- Compact Member Demographics -->
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h3 class="text-md font-semibold text-gray-800 mb-3 flex items-center">
                            <i class="fas fa-users text-blue-600 mr-2"></i>Demographics
                        </h3>
                        <div class="space-y-2">
                            <div class="flex justify-between items-center text-sm">
                                <span class="text-gray-600">Gender</span>
                                <span class="font-medium">M: <?php echo $demographics_data['male_count']; ?> | F: <?php echo $demographics_data['female_count']; ?></span>
                            </div>
                            <div class="flex justify-between items-center text-sm">
                                <span class="text-gray-600">Avg Age</span>
                                <span class="font-medium"><?php echo round($demographics_data['avg_age'] ?? 0); ?> years</span>
                            </div>
                            <div class="flex justify-between items-center text-sm">
                                <span class="text-gray-600">Active/Total</span>
                                <span class="font-medium"><?php echo $demographics_data['active_members']; ?>/<?php echo $demographics_data['total_members']; ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Compact Plan Recommendations -->
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h3 class="text-md font-semibold text-gray-800 mb-3 flex items-center">
                            <i class="fas fa-star text-green-600 mr-2"></i>Top Recommendations
                        </h3>
                        <div class="space-y-2 max-h-48 overflow-y-auto">
                            <?php 
                            foreach (array_slice($member_recommendations, 0, 3) as $member_id => $data): 
                                $member = $data['member'];
                                $top_plan_id = $data['top_recommendation'] ?? null;
                                $top_plan = null;
                                $score = null;
                                if ($top_plan_id && isset($data['recommendations'][$top_plan_id])) {
                                    $top_plan = $data['recommendations'][$top_plan_id]['plan'];
                                    $score = $data['recommendations'][$top_plan_id]['score'];
                                }
                            ?>
                            <div class="bg-white rounded p-2 border border-gray-200">
                                <div class="flex justify-between items-center">
                                    <div class="flex items-center space-x-2">
                                        <div class="w-8 h-8 rounded-full bg-red-500 flex items-center justify-center">
                                            <?php if (!empty($member['profile_picture'])): ?>
                                                <img src="../uploads/profile_pictures/<?php echo htmlspecialchars($member['profile_picture']); ?>"
                                                     alt="Profile Picture"
                                                     class="w-8 h-8 rounded-full object-cover">
                                            <?php else: ?>
                                                <span class="text-white text-xs font-bold">
                                                    <?php echo strtoupper(substr($member['username'], 0, 1)); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($member['username']); ?></div>
                                            <div class="text-xs text-gray-500">
                                                Age: <?php 
                                                    if (!empty($member['date_of_birth'])) {
                                                        $birth_date = new DateTime($member['date_of_birth']);
                                                        $today = new DateTime();
                                                        $age = $today->diff($birth_date)->y;
                                                        echo $age;
                                                    } else {
                                                        echo 'N/A';
                                                    }
                                                ?> | Attendance: <?php echo $member['attendance_count']; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-xs font-medium text-green-600"><?php echo $top_plan ? htmlspecialchars($top_plan['name']) : 'None'; ?></div>
                                        <div class="text-xs text-gray-500"><?php echo $score !== null ? $score : 'N/A'; ?>%</div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Compact Detailed Member Recommendations -->
            <div class="bg-white rounded-lg shadow-sm p-4">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-lg font-semibold text-gray-800 flex items-center">
                        <i class="fas fa-list-alt text-red-500 mr-2"></i>Member Recommendations
                    </h2>
                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                        <?php echo count($member_recommendations); ?> Members
                    </span>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Member</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Current Plan</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Recommended</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Score</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach (array_slice($member_recommendations, 0, 10) as $member_id => $data): 
                                $member = $data['member'];
                                $top_plan_id = $data['top_recommendation'] ?? null;
                                $top_plan = null;
                                $score = null;
                                if ($top_plan_id && isset($data['recommendations'][$top_plan_id])) {
                                    $top_plan = $data['recommendations'][$top_plan_id]['plan'];
                                    $score = $data['recommendations'][$top_plan_id]['score'];
                                }
                            ?>
                            <tr class="hover:bg-gray-50 transition-colors duration-200">
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="w-8 h-8 rounded-full bg-red-500 flex items-center justify-center mr-2">
                                            <?php if (!empty($member['profile_picture'])): ?>
                                                <img src="../uploads/profile_pictures/<?php echo htmlspecialchars($member['profile_picture']); ?>"
                                                     alt="Profile Picture"
                                                     class="w-8 h-8 rounded-full object-cover">
                                            <?php else: ?>
                                                <span class="text-white text-xs font-bold">
                                                    <?php echo strtoupper(substr($member['username'], 0, 1)); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($member['username']); ?></div>
                                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars($member['email']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                        <?php echo htmlspecialchars($member['current_plan'] ?? 'No Plan'); ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div class="text-sm font-medium text-green-600"><?php echo $top_plan ? htmlspecialchars($top_plan['name']) : 'None'; ?></div>
                                    <div class="text-xs text-gray-500"><?php echo $top_plan ? '₱' . number_format($top_plan['price'], 2) : ''; ?></div>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="w-16 bg-gray-200 rounded-full h-2 mr-2">
                                            <div class="bg-green-500 h-2 rounded-full" style="width: <?php echo $score !== null ? $score : 0; ?>%"></div>
                                        </div>
                                        <span class="text-xs text-gray-900"><?php echo $score !== null ? $score : 'N/A'; ?>%</span>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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
        
        // Plan Popularity Chart
        const planData = <?php 
            $plans->data_seek(0);
            $chart_data = [];
            while ($plan = $plans->fetch_assoc()) {
                // Count members for each plan
                $member_count_sql = "SELECT COUNT(*) as count FROM users WHERE selected_plan_id = ? AND role = 'member'";
                $stmt = $conn->prepare($member_count_sql);
                $stmt->bind_param("i", $plan['id']);
                $stmt->execute();
                $member_count = $stmt->get_result()->fetch_assoc()['count'];
                $stmt->close();
                
                // Calculate total revenue for each plan
                $revenue_sql = "SELECT SUM(ph.amount) as total FROM payment_history ph 
                               JOIN users u ON ph.user_id = u.id 
                               WHERE u.selected_plan_id = ? AND ph.payment_status = 'Approved'";
                $stmt = $conn->prepare($revenue_sql);
                $stmt->bind_param("i", $plan['id']);
                $stmt->execute();
                $revenue = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
                $stmt->close();
                
                $chart_data[] = [
                    'name' => $plan['name'],
                    'member_count' => $member_count,
                    'revenue' => $revenue,
                    'daily_cost' => $plan['price'] / $plan['duration']
                ];
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
                    backgroundColor: 'rgba(59, 130, 246, 0.8)',
                    borderColor: 'rgba(59, 130, 246, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Number of Members'
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            afterBody: function(context) {
                                const plan = planData[context[0].dataIndex];
                                return [
                                    `Revenue: ₱${plan.revenue.toLocaleString()}`,
                                    `Daily Cost: ₱${plan.daily_cost.toFixed(2)}`
                                ];
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>
</html> 