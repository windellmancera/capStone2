<?php
// Check if session is already active before starting
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../db.php';
require_once 'predictive_analysis_helper.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'member') {
    header('Location: member_login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$predictive_analysis = new MemberPredictiveAnalysis($conn, $user_id);

// Get all recommendations
$workout_recommendations = $predictive_analysis->getWorkoutRecommendations();
$plan_recommendations = $predictive_analysis->getPlanRecommendations();
$fitness_goals = $predictive_analysis->getFitnessGoalsRecommendations();

// Check for success/error messages from session
$success_message = '';
$error_message = '';
if (isset($_SESSION['recommendations_success_message'])) {
    $success_message = $_SESSION['recommendations_success_message'];
    unset($_SESSION['recommendations_success_message']);
}
if (isset($_SESSION['recommendations_error_message'])) {
    $error_message = $_SESSION['recommendations_error_message'];
    unset($_SESSION['recommendations_error_message']);
}
?>

<!-- Recommendations Modal -->
<div id="recommendationsModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
                 <div class="bg-white rounded-lg shadow-xl max-w-7xl w-full max-h-[70vh] overflow-y-auto">
            <!-- Modal Header -->
            <div class="flex justify-between items-center p-6 border-b border-gray-200 sticky top-0 bg-white z-10">
                <h2 class="text-2xl font-bold text-gray-800 flex items-center">
                    <i class="fas fa-dumbbell text-purple-500 mr-3"></i>Your Personalized Recommendations
                </h2>
                <button onclick="closeRecommendationsModal()" class="text-gray-400 hover:text-gray-600 text-2xl">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <!-- Modal Content -->
            <div class="p-6">
                <!-- Success Message -->
                <?php if (!empty($success_message)): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6" role="alert">
                    <i class="fas fa-check-circle mr-2"></i><?php echo $success_message; ?>
                </div>
                <?php endif; ?>
                
                <!-- Error Message -->
                <?php if (!empty($error_message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6" role="alert">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo $error_message; ?>
                </div>
                <?php endif; ?>

                                 <!-- Recommendations Grid -->
                 <div class="grid grid-cols-1 xl:grid-cols-4 gap-6 mb-6">
                    
                                                              <!-- Workout Plan Recommendations -->
                     <div class="bg-gradient-to-br from-white to-purple-50 rounded-xl shadow-lg border border-purple-200 p-5 hover:shadow-xl transition-all duration-300 transform hover:scale-105">
                         <h2 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
                             <i class="fas fa-calendar-alt text-purple-500 mr-2 text-xl"></i>Schedule
                         </h2>
                         
                         <div class="grid grid-cols-2 gap-4 mb-4">
                             <div class="bg-gradient-to-br from-purple-100 to-purple-200 rounded-xl p-4 border border-purple-300 shadow-md">
                                 <div class="flex items-center mb-2">
                                     <i class="fas fa-clock text-purple-600 mr-2 text-lg"></i>
                                     <span class="text-sm font-bold text-gray-700">Frequency</span>
                                 </div>
                                 <p class="text-lg font-bold text-purple-700"><?php echo $workout_recommendations['workout_plan']['frequency']; ?></p>
                             </div>
                             <div class="bg-gradient-to-br from-purple-100 to-purple-200 rounded-xl p-4 border border-purple-300 shadow-md">
                                 <div class="flex items-center mb-2">
                                     <i class="fas fa-stopwatch text-purple-600 mr-2 text-lg"></i>
                                     <span class="text-sm font-bold text-gray-700">Duration</span>
                                 </div>
                                 <p class="text-lg font-bold text-purple-700"><?php echo $workout_recommendations['workout_plan']['duration']; ?></p>
                             </div>
                         </div>

                         
                     </div>

                                         <!-- Weekly Split -->
                     <div class="bg-gradient-to-br from-white to-indigo-50 rounded-xl shadow-lg border border-indigo-200 p-5 hover:shadow-xl transition-all duration-300 transform hover:scale-105">
                         <h2 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
                             <i class="fas fa-list-ul text-indigo-500 mr-2 text-xl"></i>Weekly Split
                         </h2>
                         
                         <div class="space-y-3 mb-4">
                             <?php foreach ($workout_recommendations['workout_plan']['split']['schedule'] as $day => $workout): ?>
                             <div class="flex justify-between items-center bg-gradient-to-r from-indigo-100 to-indigo-200 rounded-lg p-3 border border-indigo-300 hover:shadow-lg transition-all duration-300 cursor-pointer transform hover:scale-105" onclick="showWorkoutDetails('<?php echo $day; ?>', '<?php echo $workout; ?>')">
                                 <div class="flex items-center">
                                     <i class="fas fa-calendar-day text-indigo-600 mr-3 text-lg"></i>
                                     <span class="font-bold text-gray-700 text-base"><?php echo $day; ?></span>
                                 </div>
                                 <div class="flex items-center">
                                     <span class="font-bold text-indigo-700 text-base"><?php echo $workout; ?></span>
                                     <i class="fas fa-info-circle text-indigo-500 ml-2 text-lg"></i>
                                 </div>
                             </div>
                             <?php endforeach; ?>
                         </div>

                         <button onclick="showFullWorkoutPlan()" class="w-full bg-gradient-to-r from-indigo-500 to-indigo-600 text-white py-3 px-4 rounded-xl hover:from-indigo-600 hover:to-indigo-700 transition-all duration-300 font-medium shadow-md hover:shadow-lg transform hover:scale-105">
                             <i class="fas fa-info-circle mr-2"></i>View Plan
                         </button>
                     </div>

                                         <!-- Exercise Types -->
                     <div class="bg-gradient-to-br from-white to-teal-50 rounded-xl shadow-lg border border-teal-200 p-5 hover:shadow-xl transition-all duration-300 transform hover:scale-105">
                         <h2 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
                             <i class="fas fa-dumbbell text-teal-500 mr-2 text-xl"></i>Exercises
                         </h2>
                         
                         <div class="space-y-4 mb-4">
                             <div>
                                 <h3 class="text-sm font-bold text-gray-700 mb-2 flex items-center">
                                     <i class="fas fa-star text-blue-500 mr-2 text-lg"></i>Primary Focus
                                 </h3>
                                 <div class="flex flex-wrap gap-3">
                                     <?php foreach ($workout_recommendations['exercise_types']['primary'] as $exercise): ?>
                                     <span class="inline-block bg-gradient-to-r from-blue-100 to-blue-200 text-blue-800 text-base px-4 py-3 rounded-full font-bold shadow-md hover:shadow-lg transition-all duration-300 cursor-pointer transform hover:scale-105" onclick="showExerciseDetails('<?php echo htmlspecialchars($exercise); ?>')">
                                         <?php echo htmlspecialchars($exercise); ?>
                                     </span>
                                     <?php endforeach; ?>
                                 </div>
                             </div>
                             
                             <div>
                                 <h3 class="text-sm font-bold text-gray-700 mb-2 flex items-center">
                                     <i class="fas fa-star-half-alt text-green-500 mr-2 text-lg"></i>Secondary Focus
                                 </h3>
                                 <div class="flex flex-wrap gap-3">
                                     <?php foreach ($workout_recommendations['exercise_types']['secondary'] as $exercise): ?>
                                     <span class="inline-block bg-gradient-to-r from-green-100 to-green-200 text-green-800 text-base px-4 py-3 rounded-full font-bold shadow-md hover:shadow-lg transition-all duration-300 cursor-pointer transform hover:scale-105" onclick="showExerciseDetails('<?php echo htmlspecialchars($exercise); ?>')">
                                         <?php echo htmlspecialchars($exercise); ?>
                                     </span>
                                     <?php endforeach; ?>
                                 </div>
                             </div>
                         </div>

                         <button onclick="bookPersonalTraining()" class="w-full bg-gradient-to-r from-teal-500 to-teal-600 text-white py-3 px-4 rounded-xl hover:from-teal-600 hover:to-teal-700 transition-all duration-300 font-medium shadow-md hover:shadow-lg transform hover:scale-105">
                             <i class="fas fa-user-tie mr-2"></i>Book Training
                         </button>
                     </div>

                                         <!-- Intensity & Progress -->
                     <div class="bg-gradient-to-br from-white to-orange-50 rounded-xl shadow-lg border border-orange-200 p-5 hover:shadow-xl transition-all duration-300 transform hover:scale-105">
                         <h2 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
                             <i class="fas fa-chart-line text-orange-500 mr-2 text-xl"></i>Intensity
                         </h2>
                         
                         <div class="space-y-4 mb-4">
                             <div class="bg-gradient-to-r from-orange-100 to-orange-200 rounded-xl p-4 border border-orange-300 shadow-md">
                                 <div class="flex items-center mb-2">
                                     <i class="fas fa-fire text-orange-600 mr-2 text-lg"></i>
                                     <span class="text-sm font-bold text-gray-700">Intensity</span>
                                 </div>
                                 <p class="text-lg font-bold text-orange-700"><?php echo $workout_recommendations['intensity_level']['intensity']; ?></p>
                                 <p class="text-sm text-gray-600 mt-1">HR: <?php echo $workout_recommendations['intensity_level']['heart_rate_zone']; ?></p>
                             </div>
                             
                             <div class="bg-gradient-to-r from-orange-100 to-orange-200 rounded-xl p-4 border border-orange-300 shadow-md">
                                 <div class="flex items-center mb-2">
                                     <i class="fas fa-pause-circle text-orange-600 mr-2 text-lg"></i>
                                     <span class="text-sm font-bold text-gray-700">Rest</span>
                                 </div>
                                 <p class="text-lg font-bold text-orange-700"><?php echo $workout_recommendations['intensity_level']['rest_periods']; ?></p>
                             </div>
                         </div>

                         <button onclick="trackProgress()" class="w-full bg-gradient-to-r from-orange-500 to-orange-600 text-white py-3 px-4 rounded-xl hover:from-orange-600 hover:to-orange-700 transition-all duration-300 font-medium shadow-md hover:shadow-lg transform hover:scale-105">
                             <i class="fas fa-chart-bar mr-2"></i>Track Progress
                         </button>
                     </div>
                </div>

                                                  <!-- Action Buttons -->
                 <div class="mt-4 flex justify-center gap-3">
                     <button onclick="window.location.href='trainers.php'" class="bg-gradient-to-r from-green-500 to-green-600 text-white py-2 px-4 rounded-lg hover:from-green-600 hover:to-green-700 transition-all duration-300 font-medium text-sm shadow-md hover:shadow-lg transform hover:scale-105">
                         <i class="fas fa-user-tie mr-2"></i>
                         Find Trainers
                     </button>
                     
                     <button onclick="window.location.href='equipment.php'" class="bg-gradient-to-r from-purple-500 to-purple-600 text-white py-2 px-4 rounded-lg hover:from-purple-600 hover:to-purple-700 transition-all duration-300 font-medium text-sm shadow-md hover:shadow-lg transform hover:scale-105">
                         <i class="fas fa-dumbbell mr-2"></i>
                         Equipment Guide
                     </button>
                 </div>
            </div>
        </div>
    </div>
</div>

 <!-- Exercise Details Modal -->
 <div id="exerciseModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
     <div class="flex items-center justify-center min-h-screen p-4">
                          <div class="bg-white rounded-lg shadow-xl max-w-sm w-full max-h-[60vh] overflow-y-auto">
                     <div class="p-3 max-h-[50vh] overflow-y-auto">
            <div class="flex justify-between items-center p-6 border-b">
                <h3 class="text-lg font-bold text-gray-800">Exercise Details</h3>
                <button onclick="closeExerciseModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-6">
                <div id="exerciseDetails"></div>
            </div>
            <div class="flex justify-end p-6 border-t">
                <button onclick="closeExerciseModal()" class="bg-gray-300 text-gray-700 px-4 py-2 rounded hover:bg-gray-400 transition-colors">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function openRecommendationsModal() {
    document.getElementById('recommendationsModal').classList.remove('hidden');
}

function closeRecommendationsModal() {
    document.getElementById('recommendationsModal').classList.add('hidden');
}

function saveWorkoutPlan() {
    // Show confirmation dialog
    if (confirm('Save this workout plan to your profile?')) {
        // Create form and submit
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'save_workout_plan.php';
        
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'workout_plan';
        input.value = JSON.stringify(<?php echo json_encode($workout_recommendations); ?>);
        
        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
    }
}

function showWorkoutDetails(day, workout) {
    const modal = document.getElementById('exerciseModal');
    const details = document.getElementById('exerciseDetails');
    
    // Detailed workout information
    const workoutInfo = {
        'Upper Body': {
            title: `${day}: Upper Body Workout`,
            description: 'Focus on strengthening your chest, back, shoulders, and arms for balanced upper body development.',
            muscleGroups: ['Chest', 'Back', 'Shoulders', 'Biceps', 'Triceps'],
            benefits: [
                'Builds upper body strength',
                'Improves posture and shoulder stability',
                'Enhances functional movements',
                'Increases muscle definition'
            ],
            exercises: [
                'Push-ups (3 sets x 10-15 reps)',
                'Pull-ups or Assisted Pull-ups (3 sets x 5-10 reps)',
                'Dumbbell Rows (3 sets x 12 reps each arm)',
                'Overhead Press (3 sets x 8-12 reps)',
                'Bicep Curls (3 sets x 12 reps)',
                'Tricep Dips (3 sets x 10-15 reps)'
            ],
            tips: 'Focus on proper form. If you can\'t do pull-ups, use resistance bands or assisted pull-up machine.'
        },
        'Lower Body': {
            title: `${day}: Lower Body Workout`,
            description: 'Target your legs, glutes, and core for powerful lower body strength and stability.',
            muscleGroups: ['Quadriceps', 'Hamstrings', 'Glutes', 'Calves', 'Core'],
            benefits: [
                'Builds leg strength and power',
                'Improves balance and stability',
                'Enhances athletic performance',
                'Increases metabolism'
            ],
            exercises: [
                'Squats (3 sets x 12-15 reps)',
                'Lunges (3 sets x 10 reps each leg)',
                'Deadlifts (3 sets x 8-12 reps)',
                'Calf Raises (3 sets x 15-20 reps)',
                'Glute Bridges (3 sets x 15 reps)',
                'Planks (3 sets x 30-60 seconds)'
            ],
            tips: 'Keep your back straight and knees aligned with toes. Start with bodyweight exercises if you\'re new to lifting.'
        },
        'Rest': {
            title: `${day}: Rest Day`,
            description: 'Active recovery day to allow your muscles to repair and grow stronger.',
            muscleGroups: ['Recovery', 'Flexibility', 'Mobility'],
            benefits: [
                'Allows muscle recovery and growth',
                'Reduces risk of overtraining',
                'Improves flexibility and mobility',
                'Prevents burnout and injury'
            ],
            activities: [
                'Light stretching (10-15 minutes)',
                'Foam rolling (5-10 minutes)',
                'Light walking (20-30 minutes)',
                'Yoga or gentle mobility work',
                'Stay hydrated and get adequate sleep'
            ],
            tips: 'Listen to your body. If you feel sore, focus on gentle stretching and mobility work.'
        }
    };
    
    const info = workoutInfo[workout] || {
        title: `${day}: ${workout}`,
        description: 'A comprehensive workout for your fitness goals.',
        muscleGroups: ['Multiple muscle groups'],
        benefits: ['Improves strength', 'Builds muscle', 'Enhances fitness'],
        exercises: ['Standard exercises', 'Modified variations', 'Progressive overload'],
        tips: 'Focus on proper form and controlled movements.'
    };
    
                                 details.innerHTML = `
                        <h4 class="text-lg font-bold text-gray-800 mb-2">${info.title}</h4>
                        <p class="text-gray-600 mb-2 text-sm">${info.description}</p>
                        
                        <div class="mb-2">
                            <h5 class="font-bold text-gray-700 mb-1 flex items-center text-sm">
                                <i class="fas fa-dumbbell text-blue-500 mr-1"></i>Muscle Groups:
                            </h5>
                            <div class="flex flex-wrap gap-1 mb-1">
                                ${info.muscleGroups.map(muscle => 
                                    `<span class="inline-block bg-blue-100 text-blue-800 text-xs px-1 py-0.5 rounded-full font-medium">${muscle}</span>`
                                ).join('')}
                            </div>
                        </div>
                        
                        <div class="mb-2">
                            <h5 class="font-bold text-gray-700 mb-1 flex items-center text-sm">
                                <i class="fas fa-star text-green-500 mr-1"></i>Benefits:
                            </h5>
                            <ul class="list-disc list-inside text-gray-600 space-y-0.5 text-xs">
                                ${info.benefits.map(benefit => `<li>${benefit}</li>`).join('')}
                            </ul>
                        </div>
                        
                        <div class="mb-2">
                            <h5 class="font-bold text-gray-700 mb-1 flex items-center text-sm">
                                <i class="fas fa-list text-purple-500 mr-1"></i>${workout === 'Rest' ? 'Activities:' : 'Exercises:'}
                            </h5>
                            <ul class="list-disc list-inside text-gray-600 space-y-0.5 text-xs">
                                ${(workout === 'Rest' ? info.activities : info.exercises).map(item => `<li>${item}</li>`).join('')}
                            </ul>
                        </div>
                        
                        <div class="bg-yellow-50 border border-yellow-200 rounded p-2">
                            <h5 class="font-bold text-gray-700 mb-1 flex items-center text-sm">
                                <i class="fas fa-lightbulb text-yellow-600 mr-1"></i>Tips:
                            </h5>
                            <p class="text-gray-700 text-xs">${info.tips}</p>
                        </div>
                        `;
    
    modal.classList.remove('hidden');
}

function showFullWorkoutPlan() {
    const modal = document.getElementById('exerciseModal');
    const details = document.getElementById('exerciseDetails');
    
         details.innerHTML = `
         <h4 class="text-lg font-bold text-gray-800 mb-2">Complete Weekly Workout Plan</h4>
         <p class="text-gray-600 mb-2 text-sm">A balanced 6-day workout plan designed for optimal results and recovery.</p>
         
         <div class="space-y-2">
             <div class="bg-blue-50 border border-blue-200 rounded p-2">
                 <h5 class="font-bold text-blue-800 mb-1 text-sm">Day 1 & 4: Upper Body</h5>
                 <p class="text-blue-700 text-xs">Focus on chest, back, shoulders, and arms. Build strength and definition.</p>
             </div>
             
             <div class="bg-green-50 border border-green-200 rounded p-2">
                 <h5 class="font-bold text-green-800 mb-1 text-sm">Day 2 & 5: Lower Body</h5>
                 <p class="text-green-700 text-xs">Target legs, glutes, and core. Build power and stability.</p>
             </div>
             
             <div class="bg-purple-50 border border-purple-200 rounded p-2">
                 <h5 class="font-bold text-purple-800 mb-1 text-sm">Day 3 & 6: Rest</h5>
                 <p class="text-purple-700 text-xs">Active recovery with stretching and mobility work.</p>
             </div>
         </div>
         
         <div class="mt-3 bg-gray-50 border border-gray-200 rounded p-2">
             <h5 class="font-bold text-gray-700 mb-1 text-sm">Weekly Schedule:</h5>
             <ul class="text-gray-600 space-y-0.5 text-xs">
                 <li>• Monday: Upper Body</li>
                 <li>• Tuesday: Lower Body</li>
                 <li>• Wednesday: Rest</li>
                 <li>• Thursday: Upper Body</li>
                 <li>• Friday: Lower Body</li>
                 <li>• Saturday: Rest</li>
                 <li>• Sunday: Complete Rest</li>
             </ul>
         </div>
         
         <div class="mt-3 bg-yellow-50 border border-yellow-200 rounded p-2">
             <h5 class="font-bold text-gray-700 mb-1 text-sm">Progression Tips:</h5>
             <ul class="text-gray-700 space-y-0.5 text-xs">
                 <li>• Start with bodyweight exercises if you're new</li>
                 <li>• Gradually increase weight and reps</li>
                 <li>• Focus on proper form over heavy weights</li>
                 <li>• Listen to your body and rest when needed</li>
             </ul>
         </div>
     `;
    
    modal.classList.remove('hidden');
}

function bookPersonalTraining() {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'trainers.php';
    
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'book_training';
    input.value = '1';
    
    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
}

function trackProgress() {
    window.location.href = 'profile.php#fitness-analytics';
}

function showExerciseDetails(exercise) {
    const modal = document.getElementById('exerciseModal');
    const details = document.getElementById('exerciseDetails');
    
    // Detailed exercise information with muscle groups and instructions
    const exerciseInfo = {
        'Balanced strength training': {
            title: 'Balanced Strength Training',
            description: 'Comprehensive strength training that targets all major muscle groups for balanced development.',
            muscleGroups: ['Chest', 'Back', 'Shoulders', 'Arms', 'Legs', 'Core'],
            benefits: [
                'Builds overall muscle mass',
                'Improves functional strength',
                'Enhances posture and balance',
                'Increases metabolism'
            ],
            examples: [
                'Push-ups (Chest, Triceps, Shoulders)',
                'Pull-ups (Back, Biceps)',
                'Squats (Legs, Glutes)',
                'Planks (Core, Shoulders)'
            ],
            instructions: 'Perform 3-4 sets of 8-12 reps for each exercise. Rest 60-90 seconds between sets.'
        },
        'Moderate cardio': {
            title: 'Moderate Cardiovascular Training',
            description: 'Heart-pumping exercises that improve cardiovascular health and endurance.',
            muscleGroups: ['Heart', 'Lungs', 'Legs'],
            benefits: [
                'Strengthens heart muscle',
                'Improves lung capacity',
                'Burns calories efficiently',
                'Reduces stress and anxiety'
            ],
            examples: [
                'Brisk Walking (30-45 minutes)',
                'Cycling (20-30 minutes)',
                'Swimming (20-30 minutes)',
                'Elliptical Training (25-35 minutes)'
            ],
            instructions: 'Maintain 60-70% of your maximum heart rate. You should be able to talk but not sing.'
        },
        'Functional training': {
            title: 'Functional Training',
            description: 'Exercises that mimic real-life movements to improve daily activities and sports performance.',
            muscleGroups: ['Full Body', 'Core', 'Stabilizers'],
            benefits: [
                'Improves daily movement patterns',
                'Enhances balance and coordination',
                'Reduces injury risk',
                'Builds practical strength'
            ],
            examples: [
                'Deadlifts (Hip hinge movement)',
                'Turkish Get-ups (Full body coordination)',
                'Farmer\'s Walks (Grip and core)',
                'Medicine Ball Throws (Power and coordination)'
            ],
            instructions: 'Focus on proper form and controlled movements. 3-4 sets of 8-15 reps.'
        },
        'Core work': {
            title: 'Core Strengthening',
            description: 'Targeted exercises to strengthen your abdominal and back muscles for better stability.',
            muscleGroups: ['Rectus Abdominis', 'Obliques', 'Lower Back', 'Pelvic Floor'],
            benefits: [
                'Improves posture and stability',
                'Reduces back pain',
                'Enhances athletic performance',
                'Supports spine health'
            ],
            examples: [
                'Planks (30-60 seconds)',
                'Russian Twists (15-20 reps each side)',
                'Dead Bugs (10-15 reps each side)',
                'Bird Dogs (10-15 reps each side)'
            ],
            instructions: 'Focus on quality over quantity. Hold positions for 30-60 seconds or perform 10-20 reps.'
        }
    };
    
    const info = exerciseInfo[exercise] || {
        title: exercise,
        description: 'A comprehensive exercise for your fitness goals.',
        muscleGroups: ['Multiple muscle groups'],
        benefits: ['Improves strength', 'Builds muscle', 'Enhances fitness'],
        examples: ['Standard variation', 'Modified version', 'Advanced progression'],
        instructions: 'Perform with proper form and controlled movements.'
    };
    
    details.innerHTML = `
        <h4 class="text-lg font-bold text-gray-800 mb-3">${info.title}</h4>
        <p class="text-gray-600 mb-4">${info.description}</p>
        
        <div class="mb-4">
            <h5 class="font-bold text-gray-700 mb-2 flex items-center">
                <i class="fas fa-dumbbell text-blue-500 mr-2"></i>Muscle Groups Targeted:
            </h5>
            <div class="flex flex-wrap gap-2 mb-3">
                ${info.muscleGroups.map(muscle => 
                    `<span class="inline-block bg-blue-100 text-blue-800 text-sm px-3 py-1 rounded-full font-medium">${muscle}</span>`
                ).join('')}
            </div>
        </div>
        
        <div class="mb-4">
            <h5 class="font-bold text-gray-700 mb-2 flex items-center">
                <i class="fas fa-star text-green-500 mr-2"></i>Benefits:
            </h5>
            <ul class="list-disc list-inside text-gray-600 space-y-1">
                ${info.benefits.map(benefit => `<li>${benefit}</li>`).join('')}
            </ul>
        </div>
        
        <div class="mb-4">
            <h5 class="font-bold text-gray-700 mb-2 flex items-center">
                <i class="fas fa-list text-purple-500 mr-2"></i>Examples:
            </h5>
            <ul class="list-disc list-inside text-gray-600 space-y-1">
                ${info.examples.map(example => `<li>${example}</li>`).join('')}
            </ul>
        </div>
        
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
            <h5 class="font-bold text-gray-700 mb-2 flex items-center">
                <i class="fas fa-info-circle text-yellow-600 mr-2"></i>How to Perform:
            </h5>
            <p class="text-gray-700">${info.instructions}</p>
        </div>
    `;
    
    modal.classList.remove('hidden');
}

function closeExerciseModal() {
    document.getElementById('exerciseModal').classList.add('hidden');
}

// Close modals when clicking outside
document.getElementById('recommendationsModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeRecommendationsModal();
    }
});

document.getElementById('exerciseModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeExerciseModal();
    }
});
</script> 