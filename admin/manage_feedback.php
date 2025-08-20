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

// Handle feedback actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'delete_feedback':
                $feedback_id = intval($_POST['feedback_id']);
                $sql = "DELETE FROM gym_feedback WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $feedback_id);
                if ($stmt->execute()) {
                    $message = "Feedback deleted successfully!";
                    $messageClass = 'success';
                } else {
                    $message = "Error deleting feedback: " . $conn->error;
                    $messageClass = 'error';
                }
                break;
        }
    }
}

// Get feedback statistics
$stats_sql = "
SELECT 
    COUNT(*) as total_feedback,
    COUNT(CASE WHEN category = 'facilities' THEN 1 END) as facilities_feedback,
    COUNT(CASE WHEN category = 'services' THEN 1 END) as services_feedback,
    COUNT(CASE WHEN category = 'system' THEN 1 END) as system_feedback,
    COUNT(CASE WHEN category = 'general' THEN 1 END) as general_feedback,
    COUNT(CASE WHEN DATE(created_at) = CURDATE() THEN 1 END) as today_feedback,
    COUNT(CASE WHEN DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 END) as this_week_feedback
FROM gym_feedback";

$stats_result = $conn->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Get all feedback with user details
$feedback_sql = "
SELECT gf.*, 
       u.username as member_name,
       u.email as member_email,
       u.profile_picture as member_picture
FROM gym_feedback gf
LEFT JOIN users u ON gf.user_id = u.id
ORDER BY gf.created_at DESC";

$feedback_result = $conn->query($feedback_sql);
$all_feedback = [];

if ($feedback_result) {
    while ($row = $feedback_result->fetch_assoc()) {
        $all_feedback[] = $row;
    }
}

// Get category icons and colors
$category_icons = [
    'facilities' => 'fa-dumbbell',
    'services' => 'fa-concierge-bell',
    'system' => 'fa-laptop',
    'general' => 'fa-comment'
];

$category_colors = [
    'facilities' => 'text-blue-500',
    'services' => 'text-green-500',
    'system' => 'text-purple-500',
    'general' => 'text-gray-500'
];

$category_bg_colors = [
    'facilities' => 'bg-blue-100',
    'services' => 'bg-green-100',
    'system' => 'bg-purple-100',
    'general' => 'bg-gray-100'
];

$profile_picture = 'https://i.pravatar.cc/40?img=1';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Feedback - Admin Dashboard</title>
    <meta name="version" content="<?php echo time(); ?>">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <meta http-equiv="Cache-Control" content="max-age=0">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        .feedback-card { transition: all 0.3s ease; }
        .feedback-card:hover { transform: translateY(-2px); box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1); }
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
                    Feedback Management
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

            <!-- Feedback Statistics -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-blue-100 text-blue-600">
                            <i class="fas fa-comments text-2xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-600">Total Feedback</p>
                            <p class="text-2xl font-semibold text-gray-900"><?php echo $stats['total_feedback']; ?></p>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-green-100 text-green-600">
                            <i class="fas fa-calendar-day text-2xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-600">Today's Feedback</p>
                            <p class="text-2xl font-semibold text-gray-900"><?php echo $stats['today_feedback']; ?></p>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-purple-100 text-purple-600">
                            <i class="fas fa-calendar-week text-2xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-600">This Week</p>
                            <p class="text-2xl font-semibold text-gray-900"><?php echo $stats['this_week_feedback']; ?></p>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-yellow-100 text-yellow-600">
                            <i class="fas fa-chart-pie text-2xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-600">Categories</p>
                            <p class="text-2xl font-semibold text-gray-900">4</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Category Breakdown -->
            <div class="bg-white rounded-lg shadow p-6 mb-8">
                <h2 class="text-xl font-semibold text-gray-800 mb-4">Feedback by Category</h2>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div class="bg-blue-50 rounded-lg p-4">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-blue-600">Facilities</p>
                                <p class="text-2xl font-bold text-blue-900"><?php echo $stats['facilities_feedback']; ?></p>
                            </div>
                            <i class="fas fa-dumbbell text-2xl text-blue-500"></i>
                        </div>
                    </div>
                    <div class="bg-green-50 rounded-lg p-4">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-green-600">Services</p>
                                <p class="text-2xl font-bold text-green-900"><?php echo $stats['services_feedback']; ?></p>
                            </div>
                            <i class="fas fa-concierge-bell text-2xl text-green-500"></i>
                        </div>
                    </div>
                    <div class="bg-purple-50 rounded-lg p-4">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-purple-600">System</p>
                                <p class="text-2xl font-bold text-purple-900"><?php echo $stats['system_feedback']; ?></p>
                            </div>
                            <i class="fas fa-laptop text-2xl text-purple-500"></i>
                        </div>
                    </div>
                    <div class="bg-gray-50 rounded-lg p-4">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">General</p>
                                <p class="text-2xl font-bold text-gray-900"><?php echo $stats['general_feedback']; ?></p>
                            </div>
                            <i class="fas fa-comment text-2xl text-gray-500"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- All Feedback List -->
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-semibold text-gray-800 flex items-center">
                        <i class="fas fa-comments text-red-500 mr-2"></i>All Member Feedback
                    </h2>
                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                        <?php echo count($all_feedback); ?> Feedback Items
                    </span>
                </div>
                
                <div class="space-y-6">
                    <?php if (!empty($all_feedback)): ?>
                        <?php foreach($all_feedback as $feedback): ?>
                            <div class="feedback-card bg-gray-50 rounded-lg p-6 hover:bg-gray-100 transition-colors duration-200">
                                <div class="flex items-start space-x-4">
                                    <!-- Member Avatar -->
                                    <div class="flex-shrink-0">
                                        <img src="<?php echo !empty($feedback['member_picture']) ? 
                                            '../uploads/profile_pictures/' . htmlspecialchars($feedback['member_picture']) : 
                                            'https://ui-avatars.com/api/?name=' . urlencode($feedback['member_name']) . '&background=random'; ?>" 
                                             alt="Member" 
                                             class="w-12 h-12 rounded-full object-cover border-2 border-white shadow-sm">
                                    </div>
                                    
                                    <!-- Feedback Content -->
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center justify-between mb-3">
                                            <div>
                                                <h3 class="text-lg font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($feedback['member_name']); ?>
                                                </h3>
                                                <p class="text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($feedback['member_email']); ?>
                                                </p>
                                            </div>
                                            <div class="flex items-center space-x-2">
                                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?php echo $category_bg_colors[$feedback['category']] ?? 'bg-gray-100'; ?> <?php echo $category_colors[$feedback['category']] ?? 'text-gray-600'; ?>">
                                                    <i class="fas <?php echo $category_icons[$feedback['category']] ?? $category_icons['general']; ?> mr-1"></i>
                                                    <?php echo ucfirst(htmlspecialchars($feedback['category'])); ?>
                                                </span>
                                                <span class="text-xs text-gray-500">
                                                    <?php echo date('M d, Y g:i A', strtotime($feedback['created_at'])); ?>
                                                </span>
                                            </div>
                                        </div>
                                        
                                        <!-- Feedback Message -->
                                        <div class="bg-white rounded-lg p-4 border border-gray-200">
                                            <p class="text-gray-700"><?php echo nl2br(htmlspecialchars($feedback['message'])); ?></p>
                                        </div>
                                        
                                        <!-- Update Indicator -->
                                        <?php if ($feedback['updated_at'] && $feedback['updated_at'] != $feedback['created_at']): ?>
                                            <p class="text-xs text-gray-500 mt-2">
                                                <i class="far fa-edit mr-1"></i> Updated <?php echo date('M d, Y g:i A', strtotime($feedback['updated_at'])); ?>
                                            </p>
                                        <?php endif; ?>
                                        
                                        <!-- Action Buttons -->
                                        <div class="flex justify-end mt-4 space-x-2">
                                            <button onclick="deleteFeedback(<?php echo $feedback['id']; ?>)" 
                                                    class="text-red-600 hover:text-red-800 p-2 rounded hover:bg-red-100 transition-colors">
                                                <i class="fas fa-trash text-sm"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-12">
                            <div class="text-gray-400 mb-4">
                                <i class="fas fa-comments text-6xl"></i>
                            </div>
                            <h3 class="text-lg font-medium text-gray-900 mb-2">No Feedback Yet</h3>
                            <p class="text-gray-500">Members haven't submitted any feedback yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
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

        function deleteFeedback(feedbackId) {
            if (confirm('Are you sure you want to delete this feedback? This action cannot be undone.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_feedback">
                    <input type="hidden" name="feedback_id" value="${feedbackId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html> 