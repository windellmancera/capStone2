<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: member_login.php");
    exit();
}

require_once '../db.php';

// Get trainer ID from URL
$trainer_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($trainer_id <= 0) {
    header("Location: trainers.php");
    exit();
}

// Check if feedback table exists
$result = $conn->query("SHOW TABLES LIKE 'feedback'");
$has_feedback_table = ($result && $result->num_rows > 0);

// Fetch trainer details with additional information
$trainer_sql = "SELECT t.*, 
                       COUNT(DISTINCT c.id) as class_count,
                       GROUP_CONCAT(DISTINCT c.name SEPARATOR ', ') as class_names,
                       GROUP_CONCAT(DISTINCT ts.specialty) as specialties,
                       GROUP_CONCAT(DISTINCT CONCAT(tsch.day_of_week, ': ', 
                           TIME_FORMAT(tsch.start_time, '%h:%i %p'), ' - ', 
                           TIME_FORMAT(tsch.end_time, '%h:%i %p'))
                       ORDER BY FIELD(tsch.day_of_week, 
                           'Monday', 'Tuesday', 'Wednesday', 'Thursday', 
                           'Friday', 'Saturday', 'Sunday')
                       SEPARATOR '\n') as schedule_details" .
                       ($has_feedback_table ? 
                       ", AVG(CASE WHEN f.rating IS NOT NULL THEN f.rating ELSE NULL END) as avg_rating,
                        COUNT(DISTINCT f.id) as feedback_count" : 
                       ", NULL as avg_rating,
                        0 as feedback_count") . "
                FROM trainers t
                LEFT JOIN classes c ON t.id = c.trainer_id
                LEFT JOIN trainer_specialties ts ON t.id = ts.trainer_id
                LEFT JOIN trainer_schedules tsch ON t.id = tsch.trainer_id" .
                ($has_feedback_table ? 
                " LEFT JOIN feedback f ON t.id = f.trainer_id" : "") . "
                WHERE t.id = ? AND t.status = 'active'
                GROUP BY t.id";

$stmt = $conn->prepare($trainer_sql);
$stmt->bind_param("i", $trainer_id);
$stmt->execute();
$trainer = $stmt->get_result()->fetch_assoc();

if (!$trainer) {
    header("Location: trainers.php");
    exit();
}

// Get user information
$user_id = $_SESSION['user_id'];
$sql = "SELECT u.*, mp.name as membership_type 
        FROM users u 
        LEFT JOIN membership_plans mp ON u.membership_plan_id = mp.id 
        WHERE u.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// Update profile picture variable for display
$profile_picture = $user['profile_picture'] 
    ? "../uploads/profile_pictures/" . $user['profile_picture']
    : 'https://i.pravatar.cc/40?img=1';

$display_name = $user['username'] ?? $user['email'] ?? 'User';
$page_title = 'Trainer Profile';

// Get trainer's feedback if available
$feedback = [];
if ($has_feedback_table) {
    $feedback_sql = "SELECT f.*, u.username, u.profile_picture
                    FROM feedback f
                    JOIN users u ON f.user_id = u.id
                    WHERE f.trainer_id = ?
                    ORDER BY f.created_at DESC";
    $feedback_stmt = $conn->prepare($feedback_sql);
    $feedback_stmt->bind_param("i", $trainer_id);
    $feedback_stmt->execute();
    $feedback_result = $feedback_stmt->get_result();
    while ($row = $feedback_result->fetch_assoc()) {
        $feedback[] = $row;
    }
}

// Get upcoming sessions with this trainer
$sessions_sql = "SELECT ts.*, 
                        DATE_FORMAT(ts.session_date, '%W, %M %e, %Y') as formatted_date
                 FROM training_sessions ts
                 WHERE ts.member_id = ? AND ts.trainer_id = ?
                 AND ts.status IN ('pending', 'confirmed')
                 ORDER BY ts.session_date";
$sessions_stmt = $conn->prepare($sessions_sql);
$sessions_stmt->bind_param("ii", $user_id, $trainer_id);
$sessions_stmt->execute();
$sessions = $sessions_stmt->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($trainer['name']); ?> - Trainer Profile</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>
<body class="bg-gray-100">
    <!-- ... existing sidebar and header code ... -->

    <!-- Main Content -->
    <main class="ml-64 mt-16 p-6">
        <div class="max-w-7xl mx-auto">
            <!-- Trainer Profile Header -->
            <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
                <div class="flex items-start">
                    <img src="<?php echo htmlspecialchars($trainer['image_url'] ? '../' . $trainer['image_url'] : '../images/placeholder-trainer.jpg'); ?>" 
                         alt="<?php echo htmlspecialchars($trainer['name']); ?>" 
                         class="w-32 h-32 rounded-lg object-cover border-2 border-red-500">
                    <div class="ml-6 flex-1">
                        <div class="flex justify-between items-start">
                            <div>
                                <h1 class="text-3xl font-bold text-gray-900"><?php echo htmlspecialchars($trainer['name']); ?></h1>
                                <p class="text-xl text-red-600 font-medium mt-1"><?php echo htmlspecialchars($trainer['specialization']); ?></p>
                                <?php if (!empty($trainer['specialties'])): ?>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <?php foreach(explode(',', $trainer['specialties']) as $specialty): ?>
                                    <span class="px-3 py-1 text-sm font-medium rounded-full bg-red-100 text-red-800">
                                        <?php echo htmlspecialchars($specialty); ?>
                                    </span>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php if ($has_feedback_table && isset($trainer['avg_rating'])): ?>
                            <div class="text-center">
                                <div class="text-4xl font-bold text-yellow-500"><?php echo number_format($trainer['avg_rating'], 1); ?></div>
                                <div class="text-gray-500"><?php echo $trainer['feedback_count']; ?> reviews</div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="mt-4 grid grid-cols-2 gap-4">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900">Experience & Expertise</h3>
                                <div class="mt-2 space-y-2">
                                    <div class="flex items-center text-gray-600">
                                        <i class="fas fa-star w-6 text-yellow-400"></i>
                                        <span class="ml-2"><?php echo $trainer['experience_years']; ?> years experience</span>
                                    </div>
                                    <?php if ($trainer['certification']): ?>
                                    <div class="flex items-center text-gray-600">
                                        <i class="fas fa-certificate w-6 text-blue-400"></i>
                                        <span class="ml-2"><?php echo htmlspecialchars($trainer['certification']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($trainer['hourly_rate']): ?>
                                    <div class="flex items-center text-gray-600">
                                        <i class="fas fa-dollar-sign w-6 text-green-400"></i>
                                        <span class="ml-2">$<?php echo number_format($trainer['hourly_rate'], 2); ?> per hour</span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900">Contact Information</h3>
                                <div class="mt-2 space-y-2">
                                    <?php if ($trainer['email']): ?>
                                    <div class="flex items-center text-gray-600">
                                        <i class="fas fa-envelope w-6 text-purple-400"></i>
                                        <span class="ml-2"><?php echo htmlspecialchars($trainer['email']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($trainer['contact_number']): ?>
                                    <div class="flex items-center text-gray-600">
                                        <i class="fas fa-phone w-6 text-indigo-400"></i>
                                        <span class="ml-2"><?php echo htmlspecialchars($trainer['contact_number']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Upcoming Sessions -->
            <?php if ($sessions->num_rows > 0): ?>
            <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
                <h2 class="text-xl font-semibold text-gray-900 mb-4">Your Upcoming Sessions</h2>
                <div class="space-y-4">
                    <?php while ($session = $sessions->fetch_assoc()): ?>
                    <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                        <div>
                            <div class="font-medium text-gray-900"><?php echo $session['formatted_date']; ?></div>
                            <div class="text-sm text-gray-500">Status: <?php echo ucfirst($session['status']); ?></div>
                        </div>
                        <?php if ($session['status'] === 'pending'): ?>
                        <button onclick="cancelSession(<?php echo $session['id']; ?>)" 
                                class="px-4 py-2 text-sm text-red-600 hover:text-red-700 font-medium">
                            Cancel Session
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php endwhile; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Schedule and Classes -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                <!-- Availability Schedule -->
                <?php if (!empty($trainer['schedule_details'])): ?>
                <div class="bg-white rounded-lg shadow-sm p-6">
                    <h2 class="text-xl font-semibold text-gray-900 mb-4">Availability Schedule</h2>
                    <div class="space-y-3">
                        <?php foreach(explode("\n", $trainer['schedule_details']) as $schedule): ?>
                        <div class="flex items-center text-gray-600">
                            <i class="fas fa-clock w-6 text-blue-400"></i>
                            <span class="ml-2"><?php echo htmlspecialchars($schedule); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button onclick="scheduleSession(<?php echo $trainer_id; ?>)" 
                            class="mt-4 w-full bg-red-600 text-white py-2 px-4 rounded-lg hover:bg-red-700 transition-colors duration-200">
                        Schedule a Session
                    </button>
                </div>
                <?php endif; ?>

                <!-- Classes -->
                <?php if ($trainer['class_count'] > 0): ?>
                <div class="bg-white rounded-lg shadow-sm p-6">
                    <h2 class="text-xl font-semibold text-gray-900 mb-4">Classes (<?php echo $trainer['class_count']; ?>)</h2>
                    <div class="space-y-3">
                        <?php foreach(explode(', ', $trainer['class_names']) as $class): ?>
                        <div class="flex items-center text-gray-600">
                            <i class="fas fa-dumbbell w-6 text-purple-400"></i>
                            <span class="ml-2"><?php echo htmlspecialchars($class); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <a href="classes.php" class="mt-4 inline-block text-red-600 hover:text-red-700 font-medium">
                        View Class Schedule →
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <!-- Trainer Bio -->
            <?php if ($trainer['bio']): ?>
            <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
                <h2 class="text-xl font-semibold text-gray-900 mb-4">About <?php echo htmlspecialchars($trainer['name']); ?></h2>
                <p class="text-gray-600 leading-relaxed"><?php echo nl2br(htmlspecialchars($trainer['bio'])); ?></p>
            </div>
            <?php endif; ?>

            <!-- Reviews Section -->
            <?php if ($has_feedback_table): ?>
            <div class="bg-white rounded-lg shadow-sm p-6">
                <h2 class="text-xl font-semibold text-gray-900 mb-4">Reviews (<?php echo count($feedback); ?>)</h2>
                <?php if (empty($feedback)): ?>
                <p class="text-gray-500">No reviews yet.</p>
                <?php else: ?>
                <div class="space-y-6">
                    <?php foreach ($feedback as $review): ?>
                    <div class="flex space-x-4">
                        <img src="<?php echo $review['profile_picture'] ? '../uploads/profile_pictures/' . $review['profile_picture'] : 'https://i.pravatar.cc/40'; ?>" 
                             alt="<?php echo htmlspecialchars($review['username']); ?>"
                             class="w-12 h-12 rounded-full object-cover">
                        <div class="flex-1">
                            <div class="flex items-center mb-1">
                                <h3 class="font-medium text-gray-900"><?php echo htmlspecialchars($review['username']); ?></h3>
                                <span class="mx-2 text-gray-300">•</span>
                                <div class="flex items-center">
                                    <span class="text-yellow-400"><i class="fas fa-star"></i></span>
                                    <span class="ml-1 text-gray-600"><?php echo $review['rating']; ?></span>
                                </div>
                                <span class="mx-2 text-gray-300">•</span>
                                <span class="text-gray-500 text-sm">
                                    <?php echo date('F j, Y', strtotime($review['created_at'])); ?>
                                </span>
                            </div>
                            <p class="text-gray-600"><?php echo nl2br(htmlspecialchars($review['comment'])); ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
    function scheduleSession(trainerId) {
        // Get trainer details
        fetch(`get_trainer_schedule.php?trainer_id=${trainerId}`)
            .then(response => response.json())
            .then(data => {
                // Create modal with schedule selection
                const modal = document.createElement('div');
                modal.className = 'fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50';
                modal.innerHTML = `
                    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
                        <div class="mt-3 text-center">
                            <h3 class="text-lg leading-6 font-medium text-gray-900">Schedule a Session</h3>
                            <div class="mt-2 px-7 py-3">
                                <form id="scheduleForm">
                                    <input type="hidden" name="trainer_id" value="${trainerId}">
                                    <div class="mb-4">
                                        <label class="block text-gray-700 text-sm font-bold mb-2">Select Day</label>
                                        <select name="day" class="shadow border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                                            ${data.schedule.map(s => `
                                                <option value="${s.day_of_week}">${s.day_of_week}: ${s.start_time} - ${s.end_time}</option>
                                            `).join('')}
                                        </select>
                                    </div>
                                    <div class="flex items-center justify-between mt-4">
                                        <button type="submit" class="bg-red-600 text-white py-2 px-4 rounded hover:bg-red-700">Schedule</button>
                                        <button type="button" onclick="closeModal()" class="bg-gray-200 text-gray-700 py-2 px-4 rounded hover:bg-gray-300">Cancel</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                `;
                document.body.appendChild(modal);
                
                // Handle form submission
                document.getElementById('scheduleForm').addEventListener('submit', function(e) {
                    e.preventDefault();
                    const formData = new FormData(this);
                    fetch('process_schedule.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(result => {
                        alert(result.message);
                        closeModal();
                        location.reload(); // Refresh to show new session
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('An error occurred while scheduling the session.');
                    });
                });
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while fetching trainer schedule.');
            });
    }

    function closeModal() {
        const modal = document.querySelector('.fixed');
        if (modal) {
            modal.remove();
        }
    }

    function cancelSession(sessionId) {
        if (confirm('Are you sure you want to cancel this session?')) {
            fetch('cancel_session.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ session_id: sessionId })
            })
            .then(response => response.json())
            .then(result => {
                alert(result.message);
                location.reload(); // Refresh to update session list
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while cancelling the session.');
            });
        }
    }
    </script>
</body>
</html> 