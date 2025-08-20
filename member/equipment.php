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
$sql = "SELECT u.*, mp.name as membership_type 
        FROM users u 
        LEFT JOIN membership_plans mp ON u.membership_plan_id = mp.id 
        WHERE u.id = ?";
$stmt = $conn->prepare($sql);

if ($stmt === false) {
    die("Error preparing statement: " . $conn->error);
}

$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

// Update profile picture variable for display
$profile_picture = $user['profile_picture'] 
    ? "../uploads/profile_pictures/" . $user['profile_picture']
    : 'https://i.pravatar.cc/40?img=1';

$display_name = $user['username'] ?? $user['email'] ?? 'User';
$page_title = 'Equipment';

// Get current user data for the top bar
$current_user = null;
if (isset($_SESSION['user_id'])) {
    $user_sql = "SELECT username, email FROM users WHERE id = ?";
    $user_stmt = $conn->prepare($user_sql);
    
    if ($user_stmt === false) {
        die("Error preparing user statement: " . $conn->error);
    }
    
    $user_stmt->bind_param("i", $_SESSION['user_id']);
    $user_stmt->execute();
    $result = $user_stmt->get_result();
    $current_user = $result->fetch_assoc();
}

// Function to track equipment view
function trackEquipmentView($conn, $equipment_id, $user_id) {
    $sql = "INSERT INTO equipment_views (equipment_id, user_id) VALUES (?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $equipment_id, $user_id);
    $stmt->execute();
}

// Get equipment data
$equipment_sql = "SELECT *, 
                        CASE 
                            WHEN quantity = 0 THEN 'Out of Stock'
                            WHEN status = 'Maintenance' THEN 'Under Maintenance'
                            WHEN status = 'Available' AND quantity > 0 THEN 'Available'
                            ELSE 'Unavailable'
                        END as availability_status,
                        CASE 
                            WHEN quantity = 0 THEN 'gray'
                            WHEN status = 'Maintenance' THEN 'yellow'
                            WHEN status = 'Available' AND quantity > 0 THEN 'green'
                            ELSE 'red'
                        END as status_color
                 FROM equipment 
                 ORDER BY category, name";
$equipment = $conn->query($equipment_sql);

// Track equipment views for analytics
if ($equipment && $equipment->num_rows > 0) {
    while ($equipment_item = $equipment->fetch_assoc()) {
        trackEquipmentView($conn, $equipment_item['id'], $user_id);
    }
    // Reset the result set for display
    $equipment->data_seek(0);
}

// Get unique categories for filter
$categories_sql = "SELECT DISTINCT category FROM equipment WHERE category IS NOT NULL ORDER BY category";
$categories = $conn->query($categories_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Equipment - Almo Fitness</title>
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
                    <?php echo $page_title ?? 'Equipment'; ?>
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
    <main class="ml-64 mt-16 p-6" id="mainContent">
        <div class="max-w-7xl mx-auto">
            <!-- Equipment Content -->
            <div class="bg-white rounded-lg shadow-sm p-6">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-semibold text-gray-800">Gym Equipment</h2>
                    <div class="flex space-x-4">
                        <select id="categoryFilter" class="rounded-lg border-gray-300 text-gray-700 text-sm focus:ring-red-500 focus:border-red-500">
                            <option value="">All Categories</option>
                            <?php while($category = $categories->fetch_assoc()): ?>
                                <option value="<?php echo htmlspecialchars(strtolower($category['category'])); ?>">
                                    <?php echo htmlspecialchars($category['category']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                        <select id="statusFilter" class="rounded-lg border-gray-300 text-gray-700 text-sm focus:ring-red-500 focus:border-red-500">
                            <option value="">All Status</option>
                            <option value="available">Available</option>
                            <option value="maintenance">Under Maintenance</option>
                            <option value="outofstock">Out of Stock</option>
                        </select>
                        <input type="text" id="searchEquipment" placeholder="Search equipment..." class="rounded-lg border-gray-300 text-sm focus:ring-red-500 focus:border-red-500">
                    </div>
                </div>

                <!-- Equipment Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php if ($equipment && $equipment->num_rows > 0): ?>
                        <?php while($item = $equipment->fetch_assoc()): ?>
                            <div class="bg-gray-50 rounded-lg p-4 equipment-card" 
                                 data-category="<?php echo htmlspecialchars(strtolower($item['category'])); ?>"
                                 data-status="<?php echo htmlspecialchars(strtolower(str_replace(' ', '', $item['availability_status']))); ?>"
                                 data-equipment-id="<?php echo htmlspecialchars($item['id']); ?>">
                                <div class="relative">
                                    <img src="<?php echo !empty($item['image_url']) ? '../' . htmlspecialchars($item['image_url']) : '../image/almo.jpg'; ?>" 
                                         alt="<?php echo htmlspecialchars($item['name']); ?>" 
                                         class="rounded-lg object-cover w-full h-48"
                                         onerror="this.src='../image/almo.jpg';">
                                    
                                    <!-- Status Badge -->
                                    <div class="absolute top-2 right-2">
                                        <span class="px-2 py-1 rounded-full text-xs font-medium
                                            <?php 
                                            switch($item['status_color']) {
                                                case 'green':
                                                    echo 'bg-green-100 text-green-800';
                                                    break;
                                                case 'yellow':
                                                    echo 'bg-yellow-100 text-yellow-800';
                                                    break;
                                                case 'red':
                                                    echo 'bg-red-100 text-red-800';
                                                    break;
                                                default:
                                                    echo 'bg-gray-100 text-gray-800';
                                            }
                                            ?>">
                                            <?php echo htmlspecialchars($item['availability_status']); ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="mt-4">
                                    <div class="flex justify-between items-start">
                                        <div>
                                            <h3 class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars($item['name']); ?></h3>
                                            <p class="text-sm text-red-600"><?php echo htmlspecialchars($item['category']); ?></p>
                                        </div>
                                        <?php if ($item['quantity'] > 0): ?>
                                        <span class="text-sm font-medium text-gray-600">
                                            <?php echo $item['quantity']; ?> available
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <p class="mt-2 text-gray-600 text-sm"><?php echo htmlspecialchars($item['description']); ?></p>
                                    
                                    <?php if (!empty($item['last_maintenance_date'])): ?>
                                    <p class="mt-2 text-xs text-gray-500">
                                        Last maintained: <?php echo date('M j, Y', strtotime($item['last_maintenance_date'])); ?>
                                    </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="col-span-3 text-center py-8">
                            <p class="text-gray-500">No equipment found.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Sidebar Toggle with Content Centering
        document.getElementById('sidebarToggle').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const sidebarToggleIcon = this.querySelector('i');
            
            sidebar.classList.toggle('w-20');
            sidebar.classList.toggle('w-64');
            sidebarToggleIcon.classList.toggle('rotate-180');
            
            // Toggle visibility of text elements
            document.querySelectorAll('.sidebar-logo-text, .sidebar-bottom-text, .nav-text').forEach(el => {
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

        // Profile dropdown functionality
        const profileDropdown = document.getElementById('profileDropdown');
        const profileMenu = document.getElementById('profileMenu');
        const dropdownArrow = document.getElementById('dropdownArrow');

        profileDropdown.addEventListener('click', function() {
            profileMenu.classList.toggle('opacity-0');
            profileMenu.classList.toggle('invisible');
            profileMenu.classList.toggle('scale-95');
            dropdownArrow.classList.toggle('rotate-180');
        });

        // Equipment filtering functionality
        const searchInput = document.getElementById('searchEquipment');
        const categoryFilter = document.getElementById('categoryFilter');
        const statusFilter = document.getElementById('statusFilter');
        const equipmentCards = document.querySelectorAll('.equipment-card');

        function filterEquipment() {
            const searchTerm = searchInput.value.toLowerCase();
            const selectedCategory = categoryFilter.value.toLowerCase();
            const selectedStatus = statusFilter.value.toLowerCase();

            equipmentCards.forEach(card => {
                const cardCategory = card.dataset.category;
                const cardStatus = card.dataset.status;
                const cardText = card.textContent.toLowerCase();
                
                const matchesSearch = cardText.includes(searchTerm);
                const matchesCategory = selectedCategory === '' || cardCategory === selectedCategory;
                const matchesStatus = selectedStatus === '' || cardStatus === selectedStatus;

                if (matchesSearch && matchesCategory && matchesStatus) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        searchInput.addEventListener('input', filterEquipment);
        categoryFilter.addEventListener('change', filterEquipment);
        statusFilter.addEventListener('change', filterEquipment);

        function trackEquipmentView(equipmentId) {
            fetch('track_equipment_view.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'equipment_id=' + equipmentId
            })
            .catch(error => console.error('Error:', error));
        }

        // Add click event listeners to equipment cards
        document.addEventListener('DOMContentLoaded', function() {
            const equipmentCards = document.querySelectorAll('[data-equipment-id]');
            equipmentCards.forEach(card => {
                card.addEventListener('click', function() {
                    const equipmentId = this.getAttribute('data-equipment-id');
                    trackEquipmentView(equipmentId);
                });
            });
        });
    </script>
    <?php include 'footer.php'; ?>
</body>
</html>