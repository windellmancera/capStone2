<?php
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

require '../db.php';

// Function to clean up all equipment-related foreign key references
function cleanupEquipmentReferences($conn, $equipment_id) {
    $tables_to_check = [
        'equipment_views',
        'equipment_usage',
        'equipment_maintenance',
        'equipment_reservations',
        'equipment_feedback',
        'equipment_schedules',
        'equipment_bookings',
        'equipment_repairs',
        'equipment_assignments'
    ];
    
    $cleaned_tables = [];
    
    foreach ($tables_to_check as $table_name) {
        $check_table = $conn->query("SHOW TABLES LIKE '$table_name'");
        if ($check_table && $check_table->num_rows > 0) {
            // Check if the table has an equipment_id column
            $check_column = $conn->query("SHOW COLUMNS FROM $table_name LIKE 'equipment_id'");
            if ($check_column && $check_column->num_rows > 0) {
                $delete_related_sql = "DELETE FROM $table_name WHERE equipment_id = ?";
                $delete_related_stmt = $conn->prepare($delete_related_sql);
                $delete_related_stmt->bind_param("i", $equipment_id);
                if ($delete_related_stmt->execute()) {
                    $cleaned_tables[] = $table_name;
                }
            }
        }
    }
    
    // Log cleanup for debugging purposes
    if (!empty($cleaned_tables)) {
        error_log("Cleaned up equipment references from tables: " . implode(', ', $cleaned_tables) . " for equipment ID: $equipment_id");
    }
    
    return $cleaned_tables;
}

// Check if quantity column exists and add it if missing (MUST BE FIRST)
$check_quantity = $conn->query("SHOW COLUMNS FROM equipment LIKE 'quantity'");
if ($check_quantity === false) {
    // Query failed, assume no quantity column
    $has_quantity = false;
} else {
    $has_quantity = $check_quantity->num_rows > 0;
}

if (!$has_quantity) {
    // Add quantity column to equipment table
    $add_quantity_sql = "ALTER TABLE equipment ADD COLUMN quantity INT DEFAULT 1";
    if ($conn->query($add_quantity_sql)) {
        $message = "Quantity column added successfully! All equipment now shows available quantities.";
        $messageClass = 'success';
        
        // Update existing equipment with default quantity of 1
        $update_existing = "UPDATE equipment SET quantity = 1 WHERE quantity IS NULL OR quantity = 0";
        $conn->query($update_existing);
        
        // Also ensure all records have a valid quantity value
        $fix_null_quantities = "UPDATE equipment SET quantity = 1 WHERE quantity IS NULL";
        $conn->query($fix_null_quantities);
        
        // Refresh the check
        $check_quantity = $conn->query("SHOW COLUMNS FROM equipment LIKE 'quantity'");
        if ($check_quantity === false) {
            $has_quantity = false;
        } else {
            $has_quantity = $check_quantity->num_rows > 0;
        }
    } else {
        $message = "Error adding quantity column: " . $conn->error;
        $messageClass = 'error';
        // Ensure has_quantity is set even if column creation fails
        $has_quantity = false;
    }
}

// Ensure has_quantity is always defined
if (!isset($has_quantity)) {
    $has_quantity = false;
}

// Initialize message variables (only if not already set by quantity column creation)
if (!isset($message)) {
    $message = '';
}
if (!isset($messageClass)) {
    $messageClass = '';
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add') {
            $name = $_POST['name'];
            $description = $_POST['description'];
            $quantity = isset($_POST['quantity']) ? $_POST['quantity'] : null;
            $status = $_POST['status'];
            $category = isset($_POST['category']) ? $_POST['category'] : 'Uncategorized';
            
            // Handle image upload
            $image_url = '';
            if (isset($_FILES['equipment_image']) && $_FILES['equipment_image']['error'] === UPLOAD_ERR_OK) {
                $file_extension = strtolower(pathinfo($_FILES['equipment_image']['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
                
                if (in_array($file_extension, $allowed_extensions)) {
                    $unique_filename = uniqid() . '.' . $file_extension;
                    $upload_path = '../uploads/equipment_images/' . $unique_filename;
                    
                    if (move_uploaded_file($_FILES['equipment_image']['tmp_name'], $upload_path)) {
                        $image_url = 'uploads/equipment_images/' . $unique_filename;
                    }
                }
            }
            
            // Build dynamic SQL based on whether quantity column exists
            if ($has_quantity) {
                $sql = "INSERT INTO equipment (name, description, quantity, status, category, image_url) VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssisss", $name, $description, $quantity, $status, $category, $image_url);
            } else {
                $sql = "INSERT INTO equipment (name, description, status, category, image_url) VALUES (?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssss", $name, $description, $status, $category, $image_url);
            }
            
            if ($stmt->execute()) {
                $message = "Equipment added successfully!";
                $messageClass = 'success';
            } else {
                $message = "Error adding equipment: " . $conn->error;
                $messageClass = 'error';
            }
        } elseif ($_POST['action'] === 'update') {
            $id = $_POST['equipment_id'];
            $name = $_POST['name'];
            $description = $_POST['description'];
            $quantity = isset($_POST['quantity']) ? $_POST['quantity'] : null;
            $status = $_POST['status'];
            $category = isset($_POST['category']) ? $_POST['category'] : 'Uncategorized';
            
            // Handle image upload for update
            $image_url = $_POST['existing_image'] ?? '';
            if (isset($_FILES['equipment_image']) && $_FILES['equipment_image']['error'] === UPLOAD_ERR_OK) {
                $file_extension = strtolower(pathinfo($_FILES['equipment_image']['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
                
                if (in_array($file_extension, $allowed_extensions)) {
                    $unique_filename = uniqid() . '.' . $file_extension;
                    $upload_path = '../uploads/equipment_images/' . $unique_filename;
                    
                    if (move_uploaded_file($_FILES['equipment_image']['tmp_name'], $upload_path)) {
                        // Delete old image if it exists
                        if (!empty($_POST['existing_image'])) {
                            $old_image_path = '../' . $_POST['existing_image'];
                            if (file_exists($old_image_path)) {
                                unlink($old_image_path);
                            }
                        }
                        $image_url = 'uploads/equipment_images/' . $unique_filename;
                    }
                }
            }
            
            // Build dynamic SQL based on whether quantity column exists
            if ($has_quantity) {
                $sql = "UPDATE equipment SET name = ?, description = ?, quantity = ?, status = ?, category = ?, image_url = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssisssi", $name, $description, $quantity, $status, $category, $image_url, $id);
            } else {
                $sql = "UPDATE equipment SET name = ?, description = ?, status = ?, category = ?, image_url = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssssi", $name, $description, $status, $category, $image_url, $id);
            }
            
            if ($stmt->execute()) {
                $message = "Equipment updated successfully!";
                $messageClass = 'success';
            } else {
                $message = "Error updating equipment: " . $conn->error;
                $messageClass = 'error';
            }
        } elseif ($_POST['action'] === 'delete') {
            $id = $_POST['equipment_id'];
            
            // Get the image URL before deleting the record
            $image_sql = "SELECT image_url FROM equipment WHERE id = ?";
            $image_stmt = $conn->prepare($image_sql);
            $image_stmt->bind_param("i", $id);
            $image_stmt->execute();
            $image_result = $image_stmt->get_result();
            $image_data = $image_result->fetch_assoc();
            
            // Start transaction to ensure data consistency
            $conn->begin_transaction();
            
            // Temporarily disable foreign key checks as a safety measure
            $conn->query('SET FOREIGN_KEY_CHECKS=0');
            
            try {
                // Clean up all equipment-related foreign key references
                $cleaned_tables = cleanupEquipmentReferences($conn, $id);
                
                // Now delete the equipment record
                $sql = "DELETE FROM equipment WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $id);
                
                if ($stmt->execute()) {
                    // Re-enable foreign key checks
                    $conn->query('SET FOREIGN_KEY_CHECKS=1');
                    
                    // Commit the transaction
                    $conn->commit();
                    
                    // Delete the image file if it exists
                    if (!empty($image_data['image_url'])) {
                        $image_path = '../' . $image_data['image_url'];
                        if (file_exists($image_path)) {
                            unlink($image_path);
                        }
                    }
                    
                    // Create success message with cleanup details
                    if (!empty($cleaned_tables)) {
                        $message = "Equipment deleted successfully! Cleaned up references from: " . implode(', ', $cleaned_tables);
                    } else {
                        $message = "Equipment deleted successfully!";
                    }
                    $messageClass = 'success';
                } else {
                    // Re-enable foreign key checks
                    $conn->query('SET FOREIGN_KEY_CHECKS=1');
                    
                    // Rollback if equipment deletion fails
                    $conn->rollback();
                    $message = "Error deleting equipment: " . $conn->error;
                    $messageClass = 'error';
                }
            } catch (Exception $e) {
                // Re-enable foreign key checks
                $conn->query('SET FOREIGN_KEY_CHECKS=1');
                
                // Rollback on any error
                $conn->rollback();
                $message = "Error deleting equipment: " . $e->getMessage();
                $messageClass = 'error';
            }
        }
    }
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

// Quantity column check already done at the top of the file

// Fetch all equipment AFTER ensuring quantity column exists
$equipment = $conn->query("
    SELECT * FROM equipment 
    ORDER BY name ASC
");

// Final safety check: ensure all equipment has valid quantity values
if ($has_quantity) {
    $fix_any_remaining_null = "UPDATE equipment SET quantity = 1 WHERE quantity IS NULL OR quantity <= 0";
    $conn->query($fix_any_remaining_null);
}

// Default profile picture and display name
$profile_picture = 'https://i.pravatar.cc/40?img=1';
$display_name = $current_user['username'] ?? $current_user['email'] ?? 'Admin';
$page_title = 'Manage Equipment';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Equipment - Admin Dashboard</title>
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
        
        /* Custom scrollbar for equipment */
        .equipment-scroll-container::-webkit-scrollbar {
            width: 6px;
        }
        
        .equipment-scroll-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }
        
        .equipment-scroll-container::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 3px;
        }
        
        .equipment-scroll-container::-webkit-scrollbar-thumb:hover {
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
        
        /* Sticky header for equipment table */
        .sticky {
            position: sticky;
            top: 0;
            z-index: 10;
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
                    <?php echo $page_title ?? 'Manage Equipment'; ?>
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
                <div class="mb-4 p-4 rounded-lg <?php 
                    if ($messageClass === 'success') {
                        echo 'bg-green-100 text-green-700';
                    } elseif ($messageClass === 'warning') {
                        echo 'bg-yellow-100 text-yellow-700';
                    } else {
                        echo 'bg-red-100 text-red-700';
                    }
                ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <!-- Equipment Statistics -->
            <div class="grid grid-cols-1 md:grid-cols-5 gap-6 mb-6">
                <div class="bg-white rounded-lg shadow-sm p-6 cursor-pointer hover:shadow-md transition-all duration-200 transform hover:scale-105" 
                     onclick="filterEquipmentList('all', 'All Equipment')">
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Total Equipment</h3>
                    <p class="text-3xl font-bold text-red-600">
                        <?php
                        $result = $conn->query("SELECT COUNT(*) as count FROM equipment");
                        echo $result->fetch_assoc()['count'];
                        ?>
                    </p>
                </div>
                <div class="bg-white rounded-lg shadow-sm p-6 cursor-pointer hover:shadow-md transition-all duration-200 transform hover:scale-105" 
                     onclick="filterEquipmentList('all', 'All Equipment')">
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Total Quantity</h3>
                    <p class="text-3xl font-bold text-blue-600">
                        <?php
                        if ($has_quantity) {
                            $result = $conn->query("SELECT SUM(quantity) as total FROM equipment");
                            $total_qty = $result->fetch_assoc()['total'];
                            echo $total_qty ? $total_qty : '0';
                        } else {
                            echo 'N/A';
                        }
                        ?>
                    </p>
                </div>
                <div class="bg-white rounded-lg shadow-sm p-6 cursor-pointer hover:shadow-md transition-all duration-200 transform hover:scale-105" 
                     onclick="filterEquipmentList('Available', 'Available Equipment')">
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Available</h3>
                    <p class="text-3xl font-bold text-green-600">
                        <?php
                        $result = $conn->query("SELECT COUNT(*) as count FROM equipment WHERE status = 'Available'");
                        echo $result->fetch_assoc()['count'];
                        ?>
                    </p>
                </div>
                <div class="bg-white rounded-lg shadow-sm p-6 cursor-pointer hover:shadow-md transition-all duration-200 transform hover:scale-105" 
                     onclick="filterEquipmentList('Maintenance', 'Equipment Under Maintenance')">
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Under Maintenance</h3>
                    <p class="text-3xl font-bold text-yellow-600">
                        <?php
                        $result = $conn->query("SELECT COUNT(*) as count FROM equipment WHERE status = 'Maintenance'");
                        echo $result->fetch_assoc()['count'];
                        ?>
                    </p>
                </div>
                <div class="bg-white rounded-lg shadow-sm p-6 cursor-pointer hover:shadow-md transition-all duration-200 transform hover:scale-105" 
                     onclick="filterEquipmentList('Out of Order', 'Out of Order Equipment')">
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Out of Order</h3>
                    <p class="text-2xl font-bold text-red-600">
                        <?php
                        $result = $conn->query("SELECT COUNT(*) as count FROM equipment WHERE status = 'Out of Order'");
                        echo $result->fetch_assoc()['count'];
                        ?>
                    </p>
                </div>
            </div>

            <!-- Quantity Breakdown Statistics -->
            <?php if ($has_quantity): ?>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <div class="bg-white rounded-lg shadow-sm p-6 cursor-pointer hover:shadow-md transition-all duration-200 transform hover:scale-105" 
                     onclick="filterEquipmentList('Available', 'Available Equipment Quantity')">
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Available Quantity</h3>
                    <p class="text-3xl font-bold text-green-600">
                        <?php
                        $result = $conn->query("SELECT SUM(quantity) as total FROM equipment WHERE status = 'Available'");
                        $available_qty = $result->fetch_assoc()['total'];
                        echo $available_qty ? $available_qty : '0';
                        ?>
                    </p>
                </div>
                <div class="bg-white rounded-lg shadow-sm p-6 cursor-pointer hover:shadow-md transition-all duration-200 transform hover:scale-105" 
                     onclick="filterEquipmentList('Maintenance', 'Maintenance Equipment Quantity')">
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Maintenance Quantity</h3>
                    <p class="text-3xl font-bold text-yellow-600">
                        <?php
                        $result = $conn->query("SELECT SUM(quantity) as total FROM equipment WHERE status = 'Maintenance'");
                        $maintenance_qty = $result->fetch_assoc()['total'];
                        echo $maintenance_qty ? $maintenance_qty : '0';
                        ?>
                    </p>
                </div>

                <div class="bg-white rounded-lg shadow-sm p-6 cursor-pointer hover:shadow-md transition-all duration-200 transform hover:scale-105" 
                     onclick="filterEquipmentList('low_stock', 'Low Stock Equipment')">
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Low Stock Items</h3>
                    <p class="text-3xl font-bold text-orange-600">
                        <?php
                        $result = $conn->query("SELECT COUNT(*) as count FROM equipment WHERE quantity <= 3");
                        echo $result->fetch_assoc()['count'];
                        ?>
                    </p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Equipment Management -->
            <div class="bg-white rounded-lg shadow-sm p-6">
                <div class="flex justify-between items-center mb-6">
                    <div class="flex items-center space-x-4">
                        <h2 class="text-2xl font-semibold text-gray-800">Equipment List</h2>
                        <div id="filterIndicator" class="hidden">
                            <span class="px-3 py-1 bg-blue-100 text-blue-800 text-sm rounded-full">
                                <i class="fas fa-filter mr-1"></i>
                                <span id="filterText">Filtered</span>
                                <button onclick="clearFilter()" class="ml-2 text-blue-600 hover:text-blue-800">
                                    <i class="fas fa-times"></i>
                                </button>
                            </span>
                        </div>
                    </div>
                    <div class="flex space-x-4">
                        <button id="addEquipmentBtn" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors duration-200">
                            <i class="fas fa-plus mr-2"></i> Add Equipment
                        </button>
                    </div>
                </div>

                <!-- Equipment List -->
                <div class="equipment-scroll-container" style="max-height: 600px; overflow-y: auto; overflow-x: hidden;">
                    <table id="equipmentTable" class="min-w-full bg-white rounded-lg overflow-hidden">
                        <thead class="sticky top-0 bg-gray-100 z-10">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Image</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                <?php if ($has_quantity): ?>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Quantity</th>
                                <?php endif; ?>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php while($item = $equipment->fetch_assoc()): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <img src="<?php echo !empty($item['image_url']) ? '../' . htmlspecialchars($item['image_url']) : '../image/almo.jpg'; ?>" 
                                         alt="<?php echo htmlspecialchars($item['name']); ?>"
                                         class="w-16 h-16 object-cover rounded-lg"
                                         onerror="this.src='../image/almo.jpg';">
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($item['name']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($item['category']); ?></td>
                                <td class="px-6 py-4"><?php echo htmlspecialchars($item['description']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php if ($has_quantity): ?>
                                        <?php 
                                        $quantity = $item['quantity'] ?? 0;
                                        $quantity = is_numeric($quantity) ? (int)$quantity : 0;
                                        ?>
                                        <div class="flex items-center space-x-2 group relative">
                                            <span class="font-semibold cursor-help <?php 
                                                if ($quantity <= 1) {
                                                    echo 'text-red-600';
                                                } elseif ($quantity <= 3) {
                                                    echo 'text-yellow-600';
                                                } else {
                                                    echo 'text-green-600';
                                                }
                                            ?>">
                                                <?php echo htmlspecialchars($quantity); ?>
                                            </span>
                                            <?php if ($quantity <= 1): ?>
                                                <span class="px-2 py-1 text-xs bg-red-100 text-red-800 rounded-full">Low Stock</span>
                                            <?php elseif ($quantity <= 3): ?>
                                                <span class="px-2 py-1 text-xs bg-yellow-100 text-yellow-800 rounded-full">Limited</span>
                                            <?php endif; ?>
                                            
                                            <!-- Tooltip -->
                                            <div class="absolute bottom-full left-1/2 transform -translate-x-1/2 mb-2 px-3 py-2 bg-gray-900 text-white text-xs rounded-lg opacity-0 group-hover:opacity-100 transition-opacity duration-200 pointer-events-none whitespace-nowrap z-10">
                                                <?php echo $quantity; ?> unit<?php echo $quantity != 1 ? 's' : ''; ?> available
                                                <div class="absolute top-full left-1/2 transform -translate-x-1/2 w-0 h-0 border-l-4 border-r-4 border-t-4 border-transparent border-t-gray-900"></div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-gray-400 italic">Not Available</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex flex-col space-y-1">
                                        <span class="px-2 py-1 text-xs font-medium rounded-full
                                            <?php echo $item['status'] === 'Available' ? 'bg-green-100 text-green-800' : 
                                                      ($item['status'] === 'Maintenance' ? 'bg-yellow-100 text-yellow-800' : 
                                                       'bg-red-100 text-red-800'); ?>">
                                            <?php echo htmlspecialchars($item['status']); ?>
                                        </span>
                                        <?php if ($has_quantity && $item['status'] !== 'Available'): ?>
                                            <?php 
                                            $quantity = $item['quantity'] ?? 0;
                                            $quantity = is_numeric($quantity) ? (int)$quantity : 0;
                                            ?>
                                            <span class="text-xs text-gray-500">
                                                <?php 
                                                if ($item['status'] === 'Maintenance') {
                                                    echo 'All ' . $quantity . ' units under maintenance';
                                                } elseif ($item['status'] === 'Out of Order') {
                                                    echo 'All ' . $quantity . ' units out of order';
                                                }
                                                ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <button onclick="editEquipment(<?php echo htmlspecialchars(json_encode($item)); ?>)" 
                                            class="text-blue-600 hover:text-blue-900 mr-3">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button onclick="deleteEquipment(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['name']); ?>')" 
                                            class="text-red-600 hover:text-red-900">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Add/Edit Equipment Modal -->
                <div id="equipmentModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full z-50">
                    <div class="relative top-10 mx-auto p-6 border w-full max-w-2xl shadow-2xl rounded-xl bg-white">
                        <div class="mb-6">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-2xl font-bold text-gray-900" id="modalTitle">
                                    <i class="fas fa-plus-circle text-red-600 mr-2"></i>Add New Equipment
                                </h3>
                                <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                                    <i class="fas fa-times text-xl"></i>
                                </button>
                            </div>
                            
                            <form id="equipmentForm" method="POST" enctype="multipart/form-data" class="space-y-6">
                                <input type="hidden" name="action" id="formAction" value="add">
                                <input type="hidden" name="equipment_id" id="equipmentId">
                                <input type="hidden" name="existing_image" id="existingImage">
                                
                                <!-- Basic Information Section -->
                                <div class="bg-gray-50 p-4 rounded-lg">
                                    <h4 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                                        <i class="fas fa-info-circle text-blue-600 mr-2"></i>Basic Information
                                    </h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-gray-700 text-sm font-semibold mb-2" for="name">
                                                <i class="fas fa-tag mr-1"></i>Equipment Name
                                            </label>
                                            <input type="text" id="name" name="name" required
                                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500 transition-all duration-200"
                                                   placeholder="Enter equipment name">
                                        </div>
                                        
                                        <div>
                                            <label for="category" class="block text-sm font-semibold text-gray-700 mb-2">
                                                <i class="fas fa-list mr-1"></i>Category
                                            </label>
                                            <select id="category" name="category" required
                                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500 transition-all duration-200">
                                                <option value="">Select Category</option>
                                                <option value="Cardio">Cardio</option>
                                                <option value="Strength">Strength</option>
                                                <option value="Free Weights">Free Weights</option>
                                                <option value="Machines">Machines</option>
                                                <option value="Accessories">Accessories</option>
                                                <option value="Other">Other</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-4">
                                        <label for="description" class="block text-sm font-semibold text-gray-700 mb-2">
                                            <i class="fas fa-align-left mr-1"></i>Description
                                        </label>
                                        <textarea id="description" name="description" rows="3" 
                                                  class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500 transition-all duration-200" 
                                                  placeholder="Describe the equipment features and specifications" required></textarea>
                                    </div>
                                </div>

                                <!-- Quantity and Status Section -->
                                <div class="bg-gray-50 p-4 rounded-lg">
                                    <h4 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                                        <i class="fas fa-cogs text-green-600 mr-2"></i>Equipment Details
                                    </h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <?php if ($has_quantity): ?>
                                        <div>
                                            <label for="quantity" class="block text-sm font-semibold text-gray-700 mb-2">
                                                <i class="fas fa-layer-group mr-1"></i>Quantity
                                            </label>
                                            <input type="number" id="quantity" name="quantity" required min="0"
                                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500 transition-all duration-200"
                                                   placeholder="Number of units">
                                            <p class="text-xs text-gray-500 mt-1">
                                                <i class="fas fa-info-circle mr-1"></i>Total available units
                                            </p>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <div>
                                            <label for="status" class="block text-sm font-semibold text-gray-700 mb-2">
                                                <i class="fas fa-toggle-on mr-1"></i>Status
                                            </label>
                                            <select id="status" name="status" required
                                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500 transition-all duration-200">
                                                <option value="">Select Status</option>
                                                <option value="Available">Available</option>
                                                <option value="Maintenance">Under Maintenance</option>
                                                <option value="Unavailable">Unavailable</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <!-- Image Section -->
                                <div class="bg-gray-50 p-4 rounded-lg">
                                    <h4 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                                        <i class="fas fa-image text-purple-600 mr-2"></i>Equipment Image
                                    </h4>
                                    <div class="flex items-center space-x-6">
                                        <div class="flex-shrink-0">
                                            <img id="imagePreview" src="../image/almo.jpg" 
                                                 alt="Equipment Preview" 
                                                 class="w-32 h-32 object-cover rounded-lg border-4 border-gray-200 shadow-lg">
                                        </div>
                                        <div class="flex-1">
                                            <label for="equipment_image" class="block text-sm font-semibold text-gray-700 mb-2">
                                                <i class="fas fa-upload mr-1"></i>Upload New Image
                                            </label>
                                            <input type="file" id="equipment_image" name="equipment_image" accept="image/*"
                                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500 transition-all duration-200">
                                            <p class="text-xs text-gray-500 mt-1">
                                                <i class="fas fa-info-circle mr-1"></i>Supports JPG, PNG, GIF (Max 5MB)
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Action Buttons -->
                                <div class="flex justify-end space-x-4 pt-4 border-t border-gray-200">
                                    <button type="button" onclick="closeModal()"
                                            class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 transition-all duration-200 font-medium">
                                        <i class="fas fa-times mr-2"></i>Cancel
                                    </button>
                                    <button type="submit" id="submitBtn"
                                            class="px-6 py-3 bg-red-600 text-white rounded-lg hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 transition-all duration-200 font-medium">
                                        <i class="fas fa-save mr-2"></i>Save Equipment
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Delete Confirmation Modal -->
                <div id="deleteModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full z-50">
                    <div class="relative top-20 mx-auto p-6 border w-full max-w-md shadow-2xl rounded-xl bg-white">
                        <div class="text-center">
                            <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-red-100 mb-4">
                                <i class="fas fa-exclamation-triangle text-3xl text-red-600"></i>
                            </div>
                            <h3 class="text-xl font-bold text-gray-900 mb-2">Confirm Delete</h3>
                            <p class="text-gray-600 mb-6">Are you sure you want to delete this equipment? This action cannot be undone and will remove all associated data.</p>
                            
                            <form id="deleteForm" method="POST">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="equipment_id" id="deleteEquipmentId">
                                <div class="flex justify-center space-x-4">
                                    <button type="button" onclick="closeDeleteModal()"
                                            class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 transition-all duration-200 font-medium">
                                        <i class="fas fa-times mr-2"></i>Cancel
                                    </button>
                                    <button type="submit"
                                            class="px-6 py-3 bg-red-600 text-white rounded-lg hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 transition-all duration-200 font-medium">
                                        <i class="fas fa-trash mr-2"></i>Delete Equipment
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <script>
                    // Image preview functionality
                    document.getElementById('equipment_image').addEventListener('change', function(e) {
                        const file = e.target.files[0];
                        if (file) {
                            const reader = new FileReader();
                            reader.onload = function(e) {
                                document.getElementById('imagePreview').src = e.target.result;
                            }
                            reader.readAsDataURL(file);
                        }
                    });

                    function editEquipment(equipment) {
                        // Update modal title and icon
                        const modalTitle = document.getElementById('modalTitle');
                        modalTitle.innerHTML = '<i class="fas fa-edit text-blue-600 mr-2"></i>Edit Equipment';
                        
                        // Update form action and populate fields
                        document.getElementById('formAction').value = 'update';
                        document.getElementById('equipmentId').value = equipment.id;
                        document.getElementById('name').value = equipment.name;
                        document.getElementById('category').value = equipment.category;
                        document.getElementById('description').value = equipment.description;
                        
                        // Safely handle quantity field
                        if (document.getElementById('quantity')) {
                            document.getElementById('quantity').value = equipment.quantity || '';
                            // Show quantity field if it exists in database
                            const quantityField = document.getElementById('quantity').closest('.bg-gray-50');
                            if (quantityField) {
                                quantityField.style.display = '<?php echo $has_quantity ? "block" : "none"; ?>';
                            }
                        }
                        
                        document.getElementById('status').value = equipment.status;
                        document.getElementById('existingImage').value = equipment.image_url || '';
                        
                        // Update image preview
                        const imagePreview = document.getElementById('imagePreview');
                        if (equipment.image_url) {
                            imagePreview.src = '../' + equipment.image_url;
                        } else {
                            imagePreview.src = '../image/almo.jpg';
                        }
                        
                        // Update submit button text
                        const submitBtn = document.getElementById('submitBtn');
                        submitBtn.innerHTML = '<i class="fas fa-save mr-2"></i>Update Equipment';
                        
                        document.getElementById('equipmentModal').classList.remove('hidden');
                    }

                    function deleteEquipment(id, name) {
                        document.getElementById('deleteEquipmentId').value = id;
                        document.getElementById('deleteModal').classList.remove('hidden');
                    }

                    function closeModal() {
                        document.getElementById('equipmentModal').classList.add('hidden');
                        document.getElementById('equipmentForm').reset();
                        document.getElementById('imagePreview').src = '../image/almo.jpg';
                        
                        // Reset modal title and icon
                        const modalTitle = document.getElementById('modalTitle');
                        modalTitle.innerHTML = '<i class="fas fa-plus-circle text-red-600 mr-2"></i>Add New Equipment';
                        
                        // Reset submit button text
                        const submitBtn = document.getElementById('submitBtn');
                        submitBtn.innerHTML = '<i class="fas fa-save mr-2"></i>Save Equipment';
                        
                        // Reset quantity field visibility
                        const quantityField = document.getElementById('quantity').closest('.bg-gray-50');
                        if (quantityField) {
                            quantityField.style.display = '<?php echo $has_quantity ? "block" : "none"; ?>';
                        }
                    }

                    function closeDeleteModal() {
                        document.getElementById('deleteModal').classList.add('hidden');
                    }

                    // Add new equipment button functionality
                    document.getElementById('addEquipmentBtn').addEventListener('click', function() {
                        // Reset modal title and icon
                        const modalTitle = document.getElementById('modalTitle');
                        modalTitle.innerHTML = '<i class="fas fa-plus-circle text-red-600 mr-2"></i>Add New Equipment';
                        
                        // Reset form
                        document.getElementById('formAction').value = 'add';
                        document.getElementById('equipmentId').value = '';
                        document.getElementById('equipmentForm').reset();
                        document.getElementById('imagePreview').src = '../image/almo.jpg';
                        
                        // Show/hide quantity field based on database structure
                        const quantityField = document.getElementById('quantity').closest('.bg-gray-50');
                        if (quantityField) {
                            quantityField.style.display = '<?php echo $has_quantity ? "block" : "none"; ?>';
                        }
                        
                        // Reset submit button text
                        const submitBtn = document.getElementById('submitBtn');
                        submitBtn.innerHTML = '<i class="fas fa-save mr-2"></i>Save Equipment';
                        
                        document.getElementById('equipmentModal').classList.remove('hidden');
                    });

                    // Close modal when clicking outside
                    window.onclick = function(event) {
                        const modal = document.getElementById('equipmentModal');
                        const deleteModal = document.getElementById('deleteModal');
                        const statisticsModal = document.getElementById('statisticsModal');
                        if (event.target === modal) {
                            closeModal();
                        }
                        if (event.target === deleteModal) {
                            closeDeleteModal();
                        }

                    }
                </script>



                <script>
                    // Global variable to store current filter
                    let currentFilter = null;
                    let allEquipment = [];
                    
                    // Store all equipment data when page loads
                    document.addEventListener('DOMContentLoaded', function() {
                        // Get all equipment rows and store them
                        const equipmentRows = document.querySelectorAll('#equipmentTable tbody tr');
                        equipmentRows.forEach(row => {
                            // Try to find status and quantity elements
                            const statusElement = row.querySelector('td:nth-child(6) span');
                            const quantityElement = row.querySelector('td:nth-child(5) .font-semibold');
                            
                            console.log('Row elements:', {
                                status: statusElement?.textContent,
                                quantity: quantityElement?.textContent,
                                statusSelector: 'td:nth-child(6) span',
                                quantitySelector: 'td:nth-child(5) .font-semibold'
                            });
                            
                            if (statusElement && quantityElement) {
                                allEquipment.push({
                                    element: row,
                                    status: statusElement.textContent.trim(),
                                    quantity: parseInt(quantityElement.textContent || '0')
                                });
                            } else {
                                console.log('Missing elements for row:', row);
                            }
                        });
                        
                        console.log('Equipment data loaded:', allEquipment.length, 'items');
                    });
                    
                    function filterEquipmentList(category, title) {
                        console.log('Filtering equipment:', category, title);
                        console.log('All equipment data:', allEquipment);
                        
                        const filterIndicator = document.getElementById('filterIndicator');
                        const filterText = document.getElementById('filterText');
                        const equipmentRows = document.querySelectorAll('#equipmentTable tbody tr');
                        
                        // Update filter indicator
                        filterText.textContent = title;
                        filterIndicator.classList.remove('hidden');
                        
                        // Store current filter
                        currentFilter = { category, title };
                        
                        // Show/hide rows based on filter
                        equipmentRows.forEach((row, index) => {
                            if (index < allEquipment.length) {
                                const equipment = allEquipment[index];
                                let shouldShow = false;
                                
                                console.log('Checking row', index, 'Status:', equipment.status, 'Quantity:', equipment.quantity);
                                
                                switch (category) {
                                    case 'all':
                                        shouldShow = true;
                                        break;
                                    case 'Available':
                                    case 'Maintenance':
                                    case 'Out of Order':
                                        shouldShow = equipment.status === category;
                                        break;
                                    case 'low_stock':
                                        shouldShow = equipment.quantity <= 3;
                                        break;
                                    default:
                                        shouldShow = true;
                                }
                                
                                console.log('Row', index, 'should show:', shouldShow);
                                row.style.display = shouldShow ? '' : 'none';
                            }
                        });
                        
                        // Highlight the clicked card
                        highlightActiveCard(category);
                    }
                    
                    function highlightActiveCard(category) {
                        // Remove highlight from all cards
                        document.querySelectorAll('.bg-white.rounded-lg.shadow-sm.p-6').forEach(card => {
                            card.classList.remove('ring-2', 'ring-blue-500', 'ring-offset-2');
                        });
                        
                        // Add highlight to the clicked card
                        const clickedCard = event.currentTarget;
                        clickedCard.classList.add('ring-2', 'ring-blue-500', 'ring-offset-2');
                    }
                    
                    function clearFilter() {
                        const filterIndicator = document.getElementById('filterIndicator');
                        const equipmentRows = document.querySelectorAll('#equipmentTable tbody tr');
                        
                        // Hide filter indicator
                        filterIndicator.classList.add('hidden');
                        
                        // Show all rows
                        equipmentRows.forEach(row => {
                            row.style.display = '';
                        });
                        
                        // Remove highlight from all cards
                        document.querySelectorAll('.bg-white.rounded-lg.shadow-sm.p-6').forEach(card => {
                            card.classList.remove('ring-2', 'ring-blue-500', 'ring-offset-2');
                        });
                        
                        // Clear current filter
                        currentFilter = null;
                    }
                    

                </script>
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
        
        // Real-Time Notification System using Server-Sent Events (SSE)
        console.log('Initializing real-time SSE notification system for manage_equipment.php...');
        
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
            
            console.log('Real-time SSE notification system initialized successfully for manage_equipment.php!');
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