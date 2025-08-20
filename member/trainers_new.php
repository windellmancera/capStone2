<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: member_login.php");
    exit();
}

// Database connection
require_once '../db.php';
require_once 'trainer_recommendation_helper.php';

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
$page_title = 'Trainers';

// Check if feedback table exists
$result = $conn->query("SHOW TABLES LIKE 'feedback'");
$has_feedback_table = ($result && $result->num_rows > 0);

// Get trainer recommendations
$recommendation = new TrainerRecommendation($conn, $user_id);
$recommended_trainers = $recommendation->getRecommendedTrainers();

// Check if trainer_specialties table exists
$result = $conn->query("SHOW TABLES LIKE 'trainer_specialties'");
$has_specialties_table = ($result && $result->num_rows > 0);

// Check if trainer_schedules table exists
$result = $conn->query("SHOW TABLES LIKE 'trainer_schedules'");
$has_schedules_table = ($result && $result->num_rows > 0);

// Check if status column exists in trainers table
$result = $conn->query("SHOW COLUMNS FROM trainers LIKE 'status'");
$has_status_column = ($result && $result->num_rows > 0);

// Fetch all trainers with their class information for the complete list
$trainers_sql = "SELECT t.*, 
                        COUNT(DISTINCT c.id) as class_count,
                        GROUP_CONCAT(DISTINCT c.name SEPARATOR ', ') as class_names" .
                        ($has_specialties_table ? 
                        ", GROUP_CONCAT(DISTINCT ts.specialty) as specialties" : 
                        ", t.specialization as specialties") .
                        ($has_schedules_table ? 
                        ", GROUP_CONCAT(DISTINCT CONCAT(tsch.day_of_week, ': ', 
                            TIME_FORMAT(tsch.start_time, '%h:%i %p'), ' - ', 
                            TIME_FORMAT(tsch.end_time, '%h:%i %p'))
                        ORDER BY FIELD(tsch.day_of_week, 
                            'Monday', 'Tuesday', 'Wednesday', 'Thursday', 
                            'Friday', 'Saturday', 'Sunday')
                        SEPARATOR '\n') as schedule_details" : 
                        ", NULL as schedule_details") .
                        ($has_feedback_table ? 
                        ", AVG(CASE WHEN f.rating IS NOT NULL THEN f.rating ELSE NULL END) as avg_rating,
                         COUNT(DISTINCT f.id) as feedback_count" : 
                        ", NULL as avg_rating,
                         0 as feedback_count") . "
                 FROM trainers t
                 LEFT JOIN classes c ON t.id = c.trainer_id" .
                 ($has_specialties_table ? 
                 " LEFT JOIN trainer_specialties ts ON t.id = ts.trainer_id" : "") .
                 ($has_schedules_table ? 
                 " LEFT JOIN trainer_schedules tsch ON t.id = tsch.trainer_id" : "") .
                 ($has_feedback_table ? 
                 " LEFT JOIN feedback f ON t.id = f.trainer_id" : "") .
                 ($has_status_column ? 
                 " WHERE t.status = 'active' OR t.status IS NULL" : "") . "
                 GROUP BY t.id
                 ORDER BY t.name";

$all_trainers = $conn->query($trainers_sql);

if (!$all_trainers) {
    die("Error fetching trainers: " . $conn->error);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trainers - Almo Fitness</title>
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
        .match-score-ring {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.1rem;
            position: relative;
        }
        
        .match-score-ring::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            border-radius: 50%;
            border: 4px solid #f3f4f6;
        }
        
        .match-score-ring::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            border-radius: 50%;
            border: 4px solid;
            border-color: currentColor;
            clip-path: polygon(0 0, 100% 0, 100% 100%, 0 100%);
            transform: rotate(calc(var(--percentage) * 3.6deg));
            transform-origin: center;
            transition: transform 1s ease-out;
        }
    </style>
</head>
<body class="bg-gray-100">
    <!-- Keep the existing sidebar and header HTML -->

    <!-- Main Content -->
    <main class="ml-64 p-8">
        <div class="max-w-7xl mx-auto space-y-8">
            <!-- Keep the existing recommended trainers section if present -->

            <!-- All Trainers Section -->
            <div class="bg-white rounded-lg shadow-sm p-6">
                <!-- Header Section with Better Alignment -->
                <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center mb-8 space-y-4 lg:space-y-0">
                    <h2 class="text-2xl font-semibold text-gray-800">Our Trainers</h2>
                    <div class="flex flex-col sm:flex-row space-y-3 sm:space-y-0 sm:space-x-4 w-full lg:w-auto">
                        <div class="relative">
                            <input type="text" 
                                   id="searchTrainer" 
                                   placeholder="Search by name, specialization, or experience..." 
                                   class="rounded-lg border-gray-300 text-sm focus:ring-red-500 focus:border-red-500 pl-10 pr-4 py-2 min-w-[400px]">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-search text-gray-400"></i>
                            </div>
                            <button id="clearSearch" 
                                    class="absolute inset-y-0 right-0 pr-3 flex items-center cursor-pointer text-gray-400 hover:text-gray-600 hidden">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Trainers Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php if ($all_trainers && $all_trainers->num_rows > 0): ?>
                        <?php while($trainer = $all_trainers->fetch_assoc()): ?>
                            <div class="bg-gray-50 rounded-lg p-6 trainer-card h-full flex flex-col" 
                                 data-name="<?php echo htmlspecialchars($trainer['name']); ?>"
                                 data-specialization="<?php echo htmlspecialchars($trainer['specialization']); ?>"
                                 data-bio="<?php echo htmlspecialchars($trainer['bio'] ?? ''); ?>"
                                 data-experience="<?php echo htmlspecialchars($trainer['experience_years']); ?> years experience"
                                 data-classes="<?php echo htmlspecialchars($trainer['class_names'] ?? ''); ?>"
                                 data-specialties="<?php echo htmlspecialchars($trainer['specialties'] ?? ''); ?>">
                                <!-- Keep the existing trainer card HTML structure -->
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="col-span-full text-center py-12">
                            <div class="max-w-md mx-auto">
                                <i class="fas fa-users text-4xl text-gray-300 mb-4"></i>
                                <p class="text-gray-500 text-lg">No trainers available at the moment.</p>
                                <p class="text-gray-400 mt-2">Please check back later or contact the front desk.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Enhanced Trainer filtering
        const searchInput = document.getElementById('searchTrainer');
        const clearSearchBtn = document.getElementById('clearSearch');
        const trainerCards = document.querySelectorAll('.trainer-card');
        let searchTimeout;

        function getSearchableContent(card) {
            return [
                card.dataset.name,
                card.dataset.bio,
                card.dataset.specialization,
                card.dataset.specialties,
                card.dataset.experience,
                card.dataset.classes
            ].join(' ').toLowerCase();
        }

        function filterTrainers() {
            const searchTerm = searchInput.value.toLowerCase().trim();
            clearSearchBtn.style.display = searchTerm ? 'flex' : 'none';

            // Clear previous timeout
            if (searchTimeout) {
                clearTimeout(searchTimeout);
            }

            // Add slight delay to prevent excessive filtering on fast typing
            searchTimeout = setTimeout(() => {
                let visibleCount = 0;

                trainerCards.forEach(card => {
                    const searchableContent = card._searchableContent || getSearchableContent(card);
                    
                    // Split search term into words and check if all words are found
                    const searchWords = searchTerm.split(/\s+/).filter(word => word.length > 0);
                    const matchesAllWords = searchWords.length === 0 || 
                        searchWords.every(word => searchableContent.includes(word));

                    if (matchesAllWords) {
                        card.style.display = 'block';
                        card.style.opacity = '1';
                        visibleCount++;
                    } else {
                        card.style.opacity = '0';
                        setTimeout(() => {
                            card.style.display = 'none';
                        }, 200);
                    }
                });

                // Update or show/hide no results message
                updateNoResultsMessage(visibleCount === 0 && searchTerm);
            }, 200);
        }

        function updateNoResultsMessage(show) {
            let noResultsMessage = document.querySelector('.no-results-message');
            
            if (show) {
                if (!noResultsMessage) {
                    const message = document.createElement('div');
                    message.className = 'no-results-message col-span-full text-center py-12 fade-in';
                    message.innerHTML = `
                        <div class="max-w-md mx-auto">
                            <i class="fas fa-search text-4xl text-gray-300 mb-4"></i>
                            <p class="text-gray-500 text-lg">No trainers found matching "${searchInput.value}"</p>
                            <p class="text-gray-400 mt-2">Try different search terms or clear the search</p>
                        </div>
                    `;
                    document.querySelector('.grid').appendChild(message);
                }
            } else if (noResultsMessage) {
                noResultsMessage.remove();
            }
        }

        function clearSearch() {
            searchInput.value = '';
            filterTrainers();
            searchInput.focus();
        }

        // Event listeners
        searchInput.addEventListener('input', filterTrainers);
        clearSearchBtn.addEventListener('click', clearSearch);

        // Add keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + K to focus search
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                searchInput.focus();
            }
            // Escape to clear search when focused
            if (e.key === 'Escape' && document.activeElement === searchInput) {
                clearSearch();
            }
        });

        // Add styles for animations
        const style = document.createElement('style');
        style.textContent = `
            .trainer-card {
                transition: opacity 0.2s ease-in-out;
            }
            .fade-in {
                animation: fadeIn 0.3s ease-in-out;
            }
            @keyframes fadeIn {
                from { opacity: 0; transform: translateY(-10px); }
                to { opacity: 1; transform: translateY(0); }
            }
        `;
        document.head.appendChild(style);

        // Initialize search functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Initial setup
            clearSearchBtn.style.display = 'none';
            
            // Pre-calculate searchable content for better performance
            trainerCards.forEach(card => {
                card._searchableContent = getSearchableContent(card);
            });
        });

        // Keep the existing modal and other functionality
    </script>
</body>
</html> 