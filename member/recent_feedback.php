<?php
// Check if gym_feedback table exists, if not create it
$check_table_sql = "SHOW TABLES LIKE 'gym_feedback'";
$table_exists = $conn->query($check_table_sql)->num_rows > 0;

if (!$table_exists) {
    // Create gym_feedback table
    $create_table_sql = "
    CREATE TABLE IF NOT EXISTS gym_feedback (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        message TEXT NOT NULL,
        category ENUM('facilities', 'services', 'system', 'general') DEFAULT 'general',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    ) ENGINE=InnoDB";
    
    $conn->query($create_table_sql);

    // Add indexes
    $conn->query("CREATE INDEX idx_gym_feedback_user ON gym_feedback(user_id)");
    $conn->query("CREATE INDEX idx_gym_feedback_category ON gym_feedback(category)");

    // Add sample data
    $sample_data_sql = "
    INSERT INTO gym_feedback (user_id, message, category) 
    SELECT 
        u.id,
        ELT(FLOOR(1 + RAND() * 8),
            'The gym equipment is well-maintained and modern. Great facility!',
            'The new mobile app makes it very convenient to book sessions.',
            'Staff is always helpful and professional.',
            'Love the cleanliness and organization of the gym.',
            'The trainers here are amazing and really know their stuff!',
            'Great variety of equipment and classes available.',
            'The membership pricing is very reasonable for the quality.',
            'The gym hours are perfect for my schedule.'
        ) as message,
        ELT(FLOOR(1 + RAND() * 4),
            'facilities',
            'system',
            'services',
            'general'
        ) as category
    FROM users u 
    WHERE u.role = 'member'
    LIMIT 12";

    $conn->query($sample_data_sql);
}

// Get recent gym feedback with user details
$feedback_sql = "SELECT gf.*, 
                      u.username as member_name,
                      u.profile_picture as member_picture
               FROM gym_feedback gf
               LEFT JOIN users u ON gf.user_id = u.id
               ORDER BY gf.created_at DESC
               LIMIT 15";

$feedback_result = $conn->query($feedback_sql);
$recent_feedback = [];

if ($feedback_result) {
    while ($row = $feedback_result->fetch_assoc()) {
        $recent_feedback[] = $row;
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
?>

<!-- Recent Feedback Section -->
<div class="bg-white rounded-xl shadow-sm p-6 hover:shadow-md transition-shadow duration-200">
    <div class="flex items-center justify-between mb-6">
        <h2 class="text-xl font-semibold text-gray-800 flex items-center">
            <i class="fas fa-comments text-purple-500 mr-3"></i>Recent Feedback
        </h2>
        <button onclick="openFeedbackModal()" 
                class="text-purple-600 hover:text-purple-700 text-sm font-medium flex items-center">
            <i class="fas fa-plus mr-1"></i>Submit Feedback
        </button>
    </div>
    
    <!-- Scrollable Feedback Container -->
    <div class="feedback-container overflow-y-auto" style="max-height: 600px;">
        <div class="space-y-6 pr-4">
            <?php if (!empty($recent_feedback)): ?>
                <?php foreach($recent_feedback as $feedback): ?>
                    <div class="p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors duration-200">
                        <div class="flex items-start space-x-4">
                            <!-- Member Avatar -->
                            <div class="flex-shrink-0">
                                <img src="<?php echo !empty($feedback['member_picture']) ? 
                                    '../uploads/profile_pictures/' . htmlspecialchars($feedback['member_picture']) : 
                                    'https://ui-avatars.com/api/?name=' . urlencode($feedback['member_name']) . '&background=random'; ?>" 
                                     alt="Member" 
                                     class="w-10 h-10 rounded-full object-cover border-2 border-white shadow-sm">
                            </div>
                            
                            <!-- Feedback Content -->
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between mb-2">
                                    <div>
                                        <h3 class="text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($feedback['member_name']); ?>
                                        </h3>
                                        <div class="flex items-center mt-1">
                                            <i class="fas <?php 
                                                echo $category_icons[$feedback['category']] ?? $category_icons['general']; 
                                                ?> <?php 
                                                echo $category_colors[$feedback['category']] ?? $category_colors['general']; 
                                                ?> text-sm mr-1"></i>
                                            <span class="text-xs text-gray-500 capitalize">
                                                <?php echo htmlspecialchars($feedback['category']); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <span class="text-xs text-gray-500">
                                        <?php echo date('M d, Y', strtotime($feedback['created_at'])); ?>
                                    </span>
                                </div>
                                
                                <!-- Feedback Message -->
                                <p class="text-gray-600 text-sm"><?php echo nl2br(htmlspecialchars($feedback['message'])); ?></p>
                                
                                <!-- Update Indicator -->
                                <?php if ($feedback['updated_at'] && $feedback['updated_at'] != $feedback['created_at']): ?>
                                    <p class="text-xs text-gray-500 mt-2">
                                        <i class="far fa-edit mr-1"></i> Updated <?php echo date('M d, Y', strtotime($feedback['updated_at'])); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center py-8">
                    <div class="text-gray-400 mb-2">
                        <i class="fas fa-comments text-4xl"></i>
                    </div>
                    <p class="text-gray-500">No feedback submitted yet.</p>
                    <button onclick="openFeedbackModal()" 
                            class="mt-4 text-purple-600 hover:text-purple-700 text-sm font-medium">
                        <i class="fas fa-plus mr-1"></i>Be the first to submit feedback
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Feedback Modal -->
<div id="feedbackModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl p-8 max-w-md w-full mx-4 relative">
        <h3 class="text-xl font-semibold text-gray-800 mb-6">Submit Feedback</h3>
        <form id="feedbackForm" class="space-y-6">
            <!-- Category Selection -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Category</label>
                <select name="category" required class="w-full rounded-lg border-gray-300 focus:border-purple-500 focus:ring-purple-500 shadow-sm">
                    <option value="general">General Feedback</option>
                    <option value="facilities">Facilities</option>
                    <option value="services">Services</option>
                    <option value="system">System/App</option>
                </select>
            </div>
            
            <!-- Feedback Message -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Your Feedback</label>
                <textarea name="message" rows="4" required 
                    class="w-full rounded-lg border-gray-300 focus:border-purple-500 focus:ring-purple-500 shadow-sm"
                    placeholder="Share your experience with our gym..."></textarea>
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
    }

    // Handle Form Submission
    document.getElementById('feedbackForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        try {
            const response = await fetch('submit_gym_feedback.php', {
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