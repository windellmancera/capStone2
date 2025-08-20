<?php
session_start();
require_once '../db.php';
require_once 'predictive_analysis_helper.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'member') {
    header('Location: member_login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$predictive_analysis = new MemberPredictiveAnalysis($conn, $user_id);

// Check if recommendations should be refreshed
$show_refresh_message = false;
if (isset($_SESSION['recommendations_refreshed']) && $_SESSION['recommendations_refreshed'] === true) {
    $show_refresh_message = true;
    unset($_SESSION['recommendations_refreshed']); // Clear the flag
}

// Get all recommendations
$workout_recommendations = $predictive_analysis->getWorkoutRecommendations();
$plan_recommendations = $predictive_analysis->getPlanRecommendations();
$fitness_goals = $predictive_analysis->getFitnessGoalsRecommendations();

// Handle recommendation actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'save_workout_plan':
                // Save workout plan to user preferences
                $sql = "UPDATE users SET 
                        fitness_goal = ?, 
                        experience_level = ?, 
                        preferred_workout_type = ? 
                        WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssi", 
                    $_POST['fitness_goal'],
                    $_POST['experience_level'],
                    $_POST['workout_type'],
                    $user_id
                );
                if ($stmt->execute()) {
                    $success_message = "Workout plan saved successfully!";
                }
                break;
                
            case 'schedule_session':
                // Redirect to schedule page with pre-filled data
                $_SESSION['recommended_schedule'] = $_POST['schedule_data'];
                header('Location: schedule.php?from_recommendations=1');
                exit();
                break;
                
            case 'book_trainer':
                // Redirect to trainers page with recommendation
                $_SESSION['recommended_trainer_type'] = $_POST['trainer_type'];
                header('Location: trainers.php?from_recommendations=1');
                exit();
                break;
        }
    }
}
?>

<!-- Recommendations Content -->
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Success Message -->
    <?php if (isset($success_message)): ?>
    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6" role="alert">
        <i class="fas fa-check-circle mr-2"></i><?php echo $success_message; ?>
    </div>
    <?php endif; ?>
    
    <!-- Refresh Message -->
    <?php if ($show_refresh_message): ?>
    <div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded mb-6" role="alert">
        <div class="flex items-center justify-between">
            <div class="flex items-center">
                <i class="fas fa-sync-alt mr-2"></i>
                <span>Your recommendations have been updated based on your new fitness goals!</span>
            </div>
            <button onclick="location.reload()" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded text-sm">
                <i class="fas fa-refresh mr-1"></i>Refresh
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-900 mb-2">
            <i class="fas fa-dumbbell text-purple-500 mr-3"></i>Your Personalized Recommendations
        </h1>
        <p class="text-gray-600">Based on your fitness profile and activity patterns</p>
    </div>

    <!-- Fitness Goal Summary -->
    <?php 
    // Get current user's fitness goal
    $user_sql = "SELECT fitness_goal, experience_level, preferred_workout_type FROM users WHERE id = ?";
    $user_stmt = $conn->prepare($user_sql);
    $user_stmt->bind_param("i", $user_id);
    $user_stmt->execute();
    $user_data = $user_stmt->get_result()->fetch_assoc();
    
    if ($user_data && $user_data['fitness_goal']): 
    ?>
    <div class="bg-gradient-to-r from-purple-50 to-blue-50 border border-purple-200 rounded-lg p-6 mb-8">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-xl font-bold text-gray-800 mb-2">
                    <i class="fas fa-bullseye text-purple-500 mr-2"></i>Your Fitness Goal
                </h2>
                <div class="flex items-center space-x-4 text-sm">
                    <span class="bg-purple-100 text-purple-800 px-3 py-1 rounded-full font-medium">
                        <?php echo ucwords(str_replace('_', ' ', $user_data['fitness_goal'])); ?>
                    </span>
                    <span class="bg-blue-100 text-blue-800 px-3 py-1 rounded-full font-medium">
                        <?php echo ucwords($user_data['experience_level']); ?>
                    </span>
                    <span class="bg-green-100 text-green-800 px-3 py-1 rounded-full font-medium">
                        <?php echo ucwords($user_data['preferred_workout_type']); ?>
                    </span>
                </div>
            </div>
            <div class="text-right">
                <p class="text-sm text-gray-600">Recommendations tailored for:</p>
                <p class="font-bold text-purple-600">
                    <?php 
                    switch($user_data['fitness_goal']) {
                        case 'muscle_gain':
                            echo 'Building strength and muscle mass';
                            break;
                        case 'weight_loss':
                            echo 'Fat loss and toning';
                            break;
                        case 'endurance':
                            echo 'Improving cardiovascular fitness';
                            break;
                        case 'flexibility':
                            echo 'Increasing flexibility and mobility';
                            break;
                        default:
                            echo 'General fitness improvement';
                    }
                    ?>
                </p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Recommendations Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        
        <!-- Workout Plan Recommendations -->
        <div class="bg-white rounded-lg shadow-md border border-gray-100 p-6 hover:shadow-lg transition-all duration-300">
            <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-calendar-alt text-purple-500 mr-2"></i>Recommended Schedule
            </h2>
            
            <div class="grid grid-cols-2 gap-4 mb-6">
                <div class="bg-gradient-to-br from-purple-50 to-purple-100 rounded-lg p-4 border border-purple-200">
                    <div class="flex items-center mb-2">
                        <i class="fas fa-clock text-purple-500 mr-2"></i>
                        <span class="text-sm font-medium text-gray-700">Frequency</span>
                    </div>
                    <p class="text-lg font-bold text-purple-600"><?php echo $workout_recommendations['workout_plan']['frequency']; ?></p>
                </div>
                <div class="bg-gradient-to-br from-purple-50 to-purple-100 rounded-lg p-4 border border-purple-200">
                    <div class="flex items-center mb-2">
                        <i class="fas fa-stopwatch text-purple-500 mr-2"></i>
                        <span class="text-sm font-medium text-gray-700">Duration</span>
                    </div>
                    <p class="text-lg font-bold text-purple-600"><?php echo $workout_recommendations['workout_plan']['duration']; ?></p>
                </div>
            </div>

            <button onclick="saveWorkoutPlan()" class="w-full bg-purple-600 text-white py-3 px-4 rounded-lg hover:bg-purple-700 transition-colors font-medium">
                <i class="fas fa-save mr-2"></i>Save This Plan
            </button>
        </div>

        <!-- Weekly Split -->
        <div class="bg-white rounded-lg shadow-md border border-gray-100 p-6 hover:shadow-lg transition-all duration-300">
            <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-list-ul text-indigo-500 mr-2"></i>Weekly Split
            </h2>
            
            <div class="space-y-3 mb-6">
                <?php foreach ($workout_recommendations['workout_plan']['split']['schedule'] as $day => $workout): ?>
                <div class="flex justify-between items-center bg-gradient-to-r from-indigo-50 to-indigo-100 rounded-lg p-3 border border-indigo-200 hover:shadow-md transition-all duration-300 cursor-pointer" onclick="scheduleWorkout('<?php echo $day; ?>', '<?php echo $workout; ?>')">
                    <div class="flex items-center">
                        <i class="fas fa-calendar-day text-indigo-500 mr-3"></i>
                        <span class="font-medium text-gray-700"><?php echo $day; ?></span>
                    </div>
                    <div class="flex items-center">
                        <span class="font-bold text-indigo-600"><?php echo $workout; ?></span>
                        <i class="fas fa-arrow-right text-indigo-400 ml-2"></i>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <button onclick="scheduleAllWorkouts()" class="w-full bg-indigo-600 text-white py-3 px-4 rounded-lg hover:bg-indigo-700 transition-colors font-medium">
                <i class="fas fa-calendar-plus mr-2"></i>Schedule All Workouts
            </button>
        </div>

        <!-- Exercise Types -->
        <div class="bg-white rounded-lg shadow-md border border-gray-100 p-6 hover:shadow-lg transition-all duration-300">
            <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-dumbbell text-teal-500 mr-2"></i>Recommended Exercises
            </h2>
            
            <div class="space-y-4 mb-6">
                <div>
                    <h3 class="text-sm font-bold text-gray-700 mb-2 flex items-center">
                        <i class="fas fa-star text-blue-500 mr-2"></i>Primary Focus
                    </h3>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($workout_recommendations['exercise_types']['primary'] as $exercise): ?>
                        <span class="inline-block bg-blue-100 text-blue-800 text-sm px-3 py-1 rounded-full font-medium shadow-sm hover:bg-blue-200 transition-colors cursor-pointer" onclick="showExerciseDetails('<?php echo htmlspecialchars($exercise); ?>')">
                            <?php echo htmlspecialchars($exercise); ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div>
                    <h3 class="text-sm font-bold text-gray-700 mb-2 flex items-center">
                        <i class="fas fa-star-half-alt text-green-500 mr-2"></i>Secondary Focus
                    </h3>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($workout_recommendations['exercise_types']['secondary'] as $exercise): ?>
                        <span class="inline-block bg-green-100 text-green-800 text-sm px-3 py-1 rounded-full font-medium shadow-sm hover:bg-green-200 transition-colors cursor-pointer" onclick="showExerciseDetails('<?php echo htmlspecialchars($exercise); ?>')">
                            <?php echo htmlspecialchars($exercise); ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <button onclick="bookPersonalTraining()" class="w-full bg-teal-600 text-white py-3 px-4 rounded-lg hover:bg-teal-700 transition-colors font-medium">
                <i class="fas fa-user-tie mr-2"></i>Book Personal Training
            </button>
        </div>

        <!-- Intensity & Progress -->
        <div class="bg-white rounded-lg shadow-md border border-gray-100 p-6 hover:shadow-lg transition-all duration-300">
            <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-chart-line text-orange-500 mr-2"></i>Intensity & Progress
            </h2>
            
            <div class="space-y-4 mb-6">
                <div class="bg-gradient-to-r from-orange-50 to-orange-100 rounded-lg p-4 border border-orange-200">
                    <div class="flex items-center mb-2">
                        <i class="fas fa-fire text-orange-500 mr-2"></i>
                        <span class="font-medium text-gray-700">Recommended Intensity</span>
                    </div>
                    <p class="text-lg font-bold text-orange-600"><?php echo $workout_recommendations['intensity_level']['intensity']; ?></p>
                    <p class="text-sm text-gray-600 mt-1">Heart Rate Zone: <?php echo $workout_recommendations['intensity_level']['heart_rate_zone']; ?></p>
                </div>
                
                <div class="bg-gradient-to-r from-orange-50 to-orange-100 rounded-lg p-4 border border-orange-200">
                    <div class="flex items-center mb-2">
                        <i class="fas fa-pause-circle text-orange-500 mr-2"></i>
                        <span class="font-medium text-gray-700">Rest Periods</span>
                    </div>
                    <p class="text-lg font-bold text-orange-600"><?php echo $workout_recommendations['intensity_level']['rest_periods']; ?></p>
                </div>
            </div>

            <button onclick="trackProgress()" class="w-full bg-orange-600 text-white py-3 px-4 rounded-lg hover:bg-orange-700 transition-colors font-medium">
                <i class="fas fa-chart-bar mr-2"></i>Track Progress
            </button>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="mt-8 grid grid-cols-1 md:grid-cols-3 gap-4">
        <button onclick="window.location.href='schedule.php'" class="bg-blue-600 text-white py-4 px-6 rounded-lg hover:bg-blue-700 transition-colors font-medium text-center">
            <i class="fas fa-calendar-plus text-2xl mb-2 block"></i>
            Schedule Sessions
        </button>
        
        <button onclick="window.location.href='trainers.php'" class="bg-green-600 text-white py-4 px-6 rounded-lg hover:bg-green-700 transition-colors font-medium text-center">
            <i class="fas fa-user-tie text-2xl mb-2 block"></i>
            Find Trainers
        </button>
        
        <button onclick="window.location.href='equipment.php'" class="bg-purple-600 text-white py-4 px-6 rounded-lg hover:bg-purple-700 transition-colors font-medium text-center">
            <i class="fas fa-dumbbell text-2xl mb-2 block"></i>
            Equipment Guide
        </button>
    </div>

    <!-- Goal-Based Recommendations Comparison -->
    <?php if ($show_refresh_message): ?>
    <div class="mt-8 bg-white rounded-lg shadow-md border border-gray-100 p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
            <i class="fas fa-chart-line text-orange-500 mr-2"></i>How Your Goals Affect Recommendations
        </h2>
        
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <?php 
            $goals = [
                'muscle_gain' => ['name' => 'Muscle Gain', 'color' => 'blue', 'icon' => 'dumbbell'],
                'weight_loss' => ['name' => 'Weight Loss', 'color' => 'green', 'icon' => 'fire'],
                'endurance' => ['name' => 'Endurance', 'color' => 'purple', 'icon' => 'heart'],
                'flexibility' => ['name' => 'Flexibility', 'color' => 'yellow', 'icon' => 'yoga']
            ];
            
            foreach ($goals as $goal_key => $goal_info): 
                $is_current = $user_data['fitness_goal'] === $goal_key;
            ?>
            <div class="border-2 rounded-lg p-4 <?php echo $is_current ? 'border-' . $goal_info['color'] . '-500 bg-' . $goal_info['color'] . '-50' : 'border-gray-200'; ?>">
                <div class="flex items-center mb-2">
                    <i class="fas fa-<?php echo $goal_info['icon']; ?> text-<?php echo $goal_info['color']; ?>-500 mr-2"></i>
                    <span class="font-medium <?php echo $is_current ? 'text-' . $goal_info['color'] . '-700' : 'text-gray-700'; ?>">
                        <?php echo $goal_info['name']; ?>
                    </span>
                    <?php if ($is_current): ?>
                    <span class="ml-auto bg-<?php echo $goal_info['color']; ?>-500 text-white text-xs px-2 py-1 rounded-full">Current</span>
                    <?php endif; ?>
                </div>
                
                <div class="text-sm text-gray-600">
                    <?php 
                    switch($goal_key) {
                        case 'muscle_gain':
                            echo 'Focus on strength training, progressive overload, compound exercises';
                            break;
                        case 'weight_loss':
                            echo 'Mix cardio with strength, HIIT workouts, caloric deficit';
                            break;
                        case 'endurance':
                            echo 'Cardiovascular exercises, gradual duration increase, recovery focus';
                            break;
                        case 'flexibility':
                            echo 'Stretching routines, yoga/Pilates, mobility exercises';
                            break;
                    }
                    ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <div class="mt-4 text-center">
            <p class="text-sm text-gray-600">
                <i class="fas fa-info-circle mr-1"></i>
                Your recommendations automatically adjust based on your fitness goals. 
                Update your goals in your profile to see new recommendations!
            </p>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function saveWorkoutPlan() {
    // Show confirmation dialog
    if (confirm('Save this workout plan to your profile?')) {
        // Create form and submit
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="save_workout_plan">
            <input type="hidden" name="fitness_goal" value="general_fitness">
            <input type="hidden" name="experience_level" value="intermediate">
            <input type="hidden" name="workout_type" value="balanced">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function scheduleWorkout(day, workout) {
    // Store workout data in session and redirect to schedule
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="schedule_session">
        <input type="hidden" name="schedule_data" value='${JSON.stringify({day: day, workout: workout})}'>
    `;
    document.body.appendChild(form);
    form.submit();
}

function scheduleAllWorkouts() {
    if (confirm('Schedule all recommended workouts?')) {
        window.location.href = 'schedule.php?from_recommendations=1';
    }
}

function bookPersonalTraining() {
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="book_trainer">
        <input type="hidden" name="trainer_type" value="personal_trainer">
    `;
    document.body.appendChild(form);
    form.submit();
}

function trackProgress() {
    window.location.href = 'profile.php#fitness-analytics';
}

function showExerciseDetails(exercise) {
    const modal = document.getElementById('exerciseModal');
    const details = document.getElementById('exerciseDetails');
    
    // Exercise details based on type
    const exerciseInfo = {
        'Compound lifts': {
            title: 'Compound Lifts',
            description: 'Multi-joint exercises that work multiple muscle groups simultaneously.',
            benefits: ['Builds overall strength', 'Improves coordination', 'Efficient time usage'],
            examples: ['Squats', 'Deadlifts', 'Bench Press', 'Overhead Press']
        },
        'Progressive overload training': {
            title: 'Progressive Overload Training',
            description: 'Gradually increasing the weight, reps, or sets to continue making progress.',
            benefits: ['Continuous muscle growth', 'Strength improvements', 'Prevents plateaus'],
            examples: ['Increase weight weekly', 'Add reps gradually', 'Reduce rest periods']
        },
        'HIIT': {
            title: 'High-Intensity Interval Training',
            description: 'Short bursts of intense exercise followed by brief recovery periods.',
            benefits: ['Burns calories efficiently', 'Improves cardiovascular fitness', 'Time-effective'],
            examples: ['Sprint intervals', 'Burpees', 'Mountain climbers', 'Jump squats']
        },
        'Circuit training': {
            title: 'Circuit Training',
            description: 'Moving quickly between different exercises with minimal rest.',
            benefits: ['Full-body workout', 'Cardiovascular benefits', 'Muscle endurance'],
            examples: ['Push-ups', 'Squats', 'Planks', 'Jumping jacks']
        },
        'Balanced strength training': {
            title: 'Balanced Strength Training',
            description: 'Training all major muscle groups equally for overall fitness.',
            benefits: ['Prevents muscle imbalances', 'Improves posture', 'Reduces injury risk'],
            examples: ['Full-body workouts', 'Push/pull splits', 'Upper/lower splits']
        },
        'Moderate cardio': {
            title: 'Moderate Cardio',
            description: 'Sustained cardiovascular exercise at moderate intensity.',
            benefits: ['Heart health', 'Endurance building', 'Calorie burning'],
            examples: ['Jogging', 'Cycling', 'Swimming', 'Elliptical']
        },
        'Functional training': {
            title: 'Functional Training',
            description: 'Exercises that mimic real-life movements and improve daily activities.',
            benefits: ['Better movement patterns', 'Injury prevention', 'Improved balance'],
            examples: ['Squats', 'Lunges', 'Deadlifts', 'Planks']
        },
        'Core work': {
            title: 'Core Training',
            description: 'Exercises that strengthen the abdominal and back muscles.',
            benefits: ['Better posture', 'Reduced back pain', 'Improved stability'],
            examples: ['Planks', 'Crunches', 'Russian twists', 'Leg raises']
        }
    };

    const info = exerciseInfo[exercise] || {
        title: exercise,
        description: 'Exercise focused on improving your fitness goals.',
        benefits: ['Customized to your needs', 'Progressive improvement', 'Goal-oriented'],
        examples: ['Consult with trainer', 'Follow program', 'Track progress']
    };

    details.innerHTML = `
        <h4 class="text-lg font-bold text-gray-800 mb-3">${info.title}</h4>
        <p class="text-gray-600 mb-4">${info.description}</p>
        
        <div class="mb-4">
            <h5 class="font-bold text-gray-700 mb-2">Benefits:</h5>
            <ul class="list-disc list-inside text-gray-600 space-y-1">
                ${info.benefits.map(benefit => `<li>${benefit}</li>`).join('')}
            </ul>
        </div>
        
        <div>
            <h5 class="font-bold text-gray-700 mb-2">Examples:</h5>
            <ul class="list-disc list-inside text-gray-600 space-y-1">
                ${info.examples.map(example => `<li>${example}</li>`).join('')}
            </ul>
        </div>
    `;
    
    modal.classList.remove('hidden');
}

function closeExerciseModal() {
    document.getElementById('exerciseModal').classList.add('hidden');
}

// Close modal when clicking outside
document.getElementById('exerciseModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeExerciseModal();
    }
});
</script> 