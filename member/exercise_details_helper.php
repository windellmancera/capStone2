<?php
/**
 * Exercise Details Helper
 * Provides comprehensive exercise information database
 */

class ExerciseDetailsHelper {
    
    public static function getExerciseDetails($exerciseName) {
        $exercises = self::getExerciseDatabase();
        
        // Try exact match first
        if (isset($exercises[$exerciseName])) {
            return $exercises[$exerciseName];
        }
        
        // Try case-insensitive match
        $lowerName = strtolower($exerciseName);
        foreach ($exercises as $key => $details) {
            if (strtolower($key) === $lowerName) {
                return $details;
            }
        }
        
        // Try partial match
        foreach ($exercises as $key => $details) {
            if (stripos($key, $exerciseName) !== false || stripos($exerciseName, $key) !== false) {
                return $details;
            }
        }
        
        // Default if not found
        return [
            'name' => $exerciseName,
            'category' => 'General',
            'description' => 'A beneficial exercise for overall fitness.',
            'instructions' => [
                'Warm up properly before starting',
                'Focus on proper form and technique',
                'Start with lighter resistance and progress gradually',
                'Breathe properly throughout the movement'
            ],
            'benefits' => [
                'Improves overall fitness',
                'Builds strength and endurance',
                'Enhances muscle coordination'
            ],
            'tips' => [
                'Always prioritize form over weight',
                'Listen to your body and rest when needed',
                'Consult a trainer if you\'re unsure about technique'
            ],
            'difficulty' => 'Beginner to Intermediate',
            'muscle_groups' => ['Multiple muscle groups'],
            'equipment' => 'Varies'
        ];
    }
    
    private static function getExerciseDatabase() {
        return [
            // BMI-Specific Exercise Programs
            'Underweight Strength Program' => [
                'name' => 'Underweight Strength Program',
                'category' => 'Strength Training',
                'difficulty' => 'Beginner to Intermediate',
                'description' => 'A comprehensive strength training program designed specifically for individuals with BMI below 18.5, focusing on muscle building and weight gain through proper nutrition and progressive resistance training.',
                'muscle_groups' => ['Full Body', 'Core', 'Legs', 'Back', 'Chest', 'Shoulders', 'Arms'],
                'equipment' => 'Gym Equipment, Dumbbells, Barbells',
                'instructions' => [
                    'Start with 3-4 training sessions per week',
                    'Focus on compound movements: squats, deadlifts, bench press, rows',
                    'Perform 3-4 sets of 8-12 repetitions',
                    'Rest 2-3 minutes between sets for recovery',
                    'Gradually increase weight every 2-3 weeks',
                    'Include 1-2 isolation exercises per muscle group'
                ],
                'benefits' => [
                    'Builds lean muscle mass and strength',
                    'Improves bone density and joint health',
                    'Increases metabolic rate for weight gain',
                    'Enhances functional movement patterns',
                    'Boosts confidence and self-esteem'
                ],
                'tips' => [
                    'Eat 300-500 calories above maintenance daily',
                    'Consume 1.6-2.2g protein per kg body weight',
                    'Prioritize compound movements over isolation',
                    'Get adequate sleep (7-9 hours) for recovery',
                    'Stay consistent with your training schedule'
                ],
                'bmi_specific' => 'Underweight (BMI < 18.5)',
                'frequency' => '3-4 times per week',
                'duration' => '45-60 minutes per session'
            ],
            
            'Normal Weight Balanced Program' => [
                'name' => 'Normal Weight Balanced Program',
                'category' => 'General Fitness',
                'difficulty' => 'All Levels',
                'description' => 'A balanced fitness program for individuals with normal BMI (18.5-24.9), combining strength training, cardiovascular exercise, and flexibility work for overall health and fitness.',
                'muscle_groups' => ['Full Body', 'Core', 'Cardiovascular System'],
                'equipment' => 'Gym Equipment, Cardio Machines, Bodyweight',
                'instructions' => [
                    'Train 3-5 times per week with variety',
                    'Include 2-3 strength training sessions',
                    'Add 2-3 cardio sessions (20-30 minutes)',
                    'Include flexibility and mobility work',
                    'Vary intensity and exercise selection',
                    'Listen to your body and adjust accordingly'
                ],
                'benefits' => [
                    'Maintains healthy body composition',
                    'Improves cardiovascular health',
                    'Builds functional strength',
                    'Enhances flexibility and mobility',
                    'Reduces stress and improves mood'
                ],
                'tips' => [
                    'Balance different types of exercise',
                    'Set realistic fitness goals',
                    'Track your progress regularly',
                    'Stay hydrated and eat balanced meals',
                    'Include rest days for recovery'
                ],
                'bmi_specific' => 'Normal Weight (BMI 18.5-24.9)',
                'frequency' => '3-5 times per week',
                'duration' => '30-60 minutes per session'
            ],
            
            'Overweight Weight Loss Program' => [
                'name' => 'Overweight Weight Loss Program',
                'category' => 'Weight Loss & Fitness',
                'difficulty' => 'Beginner to Intermediate',
                'description' => 'A safe and effective weight loss program for individuals with BMI 25-29.9, focusing on calorie burn, muscle preservation, and sustainable lifestyle changes.',
                'muscle_groups' => ['Full Body', 'Core', 'Cardiovascular System'],
                'equipment' => 'Cardio Machines, Light Weights, Bodyweight',
                'instructions' => [
                    'Start with 4-5 sessions per week',
                    'Include 3-4 cardio sessions (30-45 minutes)',
                    'Add 2-3 strength training sessions',
                    'Focus on low-impact, joint-friendly exercises',
                    'Gradually increase intensity and duration',
                    'Monitor heart rate and perceived exertion'
                ],
                'benefits' => [
                    'Creates sustainable calorie deficit',
                    'Preserves lean muscle mass',
                    'Improves cardiovascular health',
                    'Builds strength and endurance',
                    'Boosts metabolism and energy levels'
                ],
                'tips' => [
                    'Create a 500-750 calorie daily deficit',
                    'Focus on whole, nutrient-dense foods',
                    'Start slowly and progress gradually',
                    'Stay consistent with your routine',
                    'Celebrate non-scale victories'
                ],
                'bmi_specific' => 'Overweight (BMI 25-29.9)',
                'frequency' => '4-5 times per week',
                'duration' => '30-45 minutes per session'
            ],
            
            'Obese Safe Fitness Program' => [
                'name' => 'Obese Safe Fitness Program',
                'category' => 'Low-Impact Fitness',
                'difficulty' => 'Beginner',
                'description' => 'A safe, low-impact fitness program designed specifically for individuals with BMI 30+, focusing on gentle movement, joint health, and building sustainable exercise habits.',
                'muscle_groups' => ['Core', 'Legs', 'Cardiovascular System'],
                'equipment' => 'Chair, Walking Shoes, Light Resistance Bands',
                'instructions' => [
                    'Start with 3-4 sessions per week',
                    'Begin with 10-15 minute sessions',
                    'Focus on seated and standing exercises',
                    'Include gentle walking and movement',
                    'Gradually increase duration and intensity',
                    'Always prioritize safety and comfort'
                ],
                'benefits' => [
                    'Improves joint mobility and flexibility',
                    'Builds basic strength and endurance',
                    'Enhances cardiovascular health',
                    'Reduces stress and improves mood',
                    'Creates foundation for future progress'
                ],
                'tips' => [
                    'Start with what feels comfortable',
                    'Focus on consistency over intensity',
                    'Listen to your body and rest when needed',
                    'Work with healthcare professionals',
                    'Celebrate every small achievement'
                ],
                'bmi_specific' => 'Obese (BMI 30+)',
                'frequency' => '3-4 times per week',
                'duration' => '10-30 minutes per session'
            ],
            
            // Specific Exercise Categories
            'Low-Impact Cardio' => [
                'name' => 'Low-Impact Cardio',
                'category' => 'Cardiovascular',
                'difficulty' => 'Beginner to Intermediate',
                'description' => 'Gentle cardiovascular exercises that minimize stress on joints while improving heart health and burning calories. Perfect for beginners, overweight individuals, or those with joint concerns.',
                'muscle_groups' => ['Cardiovascular System', 'Legs', 'Core'],
                'equipment' => 'Walking Shoes, Stationary Bike, Elliptical, Pool',
                'instructions' => [
                    'Start with 10-15 minutes of gentle movement',
                    'Choose activities like walking, cycling, or swimming',
                    'Maintain a pace where you can talk comfortably',
                    'Gradually increase duration by 2-3 minutes weekly',
                    'Aim for 150 minutes of moderate activity per week',
                    'Include warm-up and cool-down periods'
                ],
                'benefits' => [
                    'Improves cardiovascular health safely',
                    'Burns calories without joint stress',
                    'Builds endurance gradually',
                    'Reduces risk of heart disease',
                    'Improves mood and energy levels'
                ],
                'tips' => [
                    'Start with walking if you\'re new to exercise',
                    'Use the "talk test" to gauge intensity',
                    'Invest in supportive, comfortable shoes',
                    'Stay hydrated throughout your workout',
                    'Listen to your body and adjust pace'
                ],
                'bmi_specific' => 'Overweight/Obese, Joint Issues',
                'frequency' => '3-5 times per week',
                'duration' => '20-45 minutes per session'
            ],
            
            'Bodyweight Strength Training' => [
                'name' => 'Bodyweight Strength Training',
                'category' => 'Strength Training',
                'difficulty' => 'Beginner to Advanced',
                'description' => 'Strength training using only your body weight as resistance. Perfect for building functional strength, improving mobility, and can be done anywhere without equipment.',
                'muscle_groups' => ['Full Body', 'Core', 'Legs', 'Upper Body'],
                'equipment' => 'None (Bodyweight)',
                'instructions' => [
                    'Start with basic movements: squats, push-ups, planks',
                    'Focus on proper form and controlled movement',
                    'Perform 2-3 sets of 8-15 repetitions',
                    'Rest 1-2 minutes between sets',
                    'Progress by increasing reps or difficulty',
                    'Include both pushing and pulling movements'
                ],
                'benefits' => [
                    'Builds functional strength',
                    'Improves body awareness and control',
                    'Enhances mobility and flexibility',
                    'No equipment or gym membership required',
                    'Can be done anywhere, anytime'
                ],
                'tips' => [
                    'Master basic form before progressing',
                    'Use wall or knee push-ups if needed',
                    'Focus on quality over quantity',
                    'Breathe steadily throughout movements',
                    'Progress gradually to avoid injury'
                ],
                'bmi_specific' => 'All BMI Categories',
                'frequency' => '2-3 times per week',
                'duration' => '20-40 minutes per session'
            ],
            
            'Chair-Based Exercises' => [
                'name' => 'Chair-Based Exercises',
                'category' => 'Low-Impact Strength',
                'difficulty' => 'Beginner',
                'description' => 'Safe, seated exercises that can be performed while sitting in a chair. Ideal for individuals with mobility issues, obesity, or those recovering from injury.',
                'muscle_groups' => ['Upper Body', 'Core', 'Legs'],
                'equipment' => 'Sturdy Chair, Light Resistance Bands (Optional)',
                'instructions' => [
                    'Sit in a sturdy chair with feet flat on floor',
                    'Start with simple arm and leg movements',
                    'Perform 8-12 repetitions of each exercise',
                    'Rest briefly between exercises',
                    'Work up to 2-3 sets of each movement',
                    'Focus on controlled, smooth movements'
                ],
                'benefits' => [
                    'Improves strength and mobility safely',
                    'Reduces risk of falls and injuries',
                    'Builds confidence in movement',
                    'Can be done at home or work',
                    'Suitable for all fitness levels'
                ],
                'tips' => [
                    'Choose a chair without wheels',
                    'Keep your back straight and core engaged',
                    'Start with simple movements',
                    'Progress gradually as strength improves',
                    'Stop if you feel pain or discomfort'
                ],
                'bmi_specific' => 'Obese, Elderly, Mobility Issues',
                'frequency' => '3-5 times per week',
                'duration' => '15-30 minutes per session'
            ],
            
            'Gentle Walking Program' => [
                'name' => 'Gentle Walking Program',
                'category' => 'Cardiovascular',
                'difficulty' => 'Beginner',
                'description' => 'A progressive walking program designed to build endurance and improve health safely. Perfect for individuals starting their fitness journey or those with health concerns.',
                'muscle_groups' => ['Legs', 'Cardiovascular System', 'Core'],
                'equipment' => 'Comfortable Walking Shoes, Pedometer (Optional)',
                'instructions' => [
                    'Start with 5-10 minutes of walking',
                    'Walk at a comfortable, conversational pace',
                    'Gradually increase duration by 2-3 minutes weekly',
                    'Aim for 30 minutes of walking most days',
                    'Include warm-up and cool-down periods',
                    'Choose safe, well-lit walking routes'
                ],
                'benefits' => [
                    'Improves cardiovascular health',
                    'Strengthens leg muscles and bones',
                    'Reduces stress and improves mood',
                    'Burns calories and aids weight management',
                    'Low risk of injury or overuse'
                ],
                'tips' => [
                    'Invest in supportive, comfortable shoes',
                    'Start with shorter distances',
                    'Walk with a friend for motivation',
                    'Stay hydrated, especially in warm weather',
                    'Listen to your body and rest when needed'
                ],
                'bmi_specific' => 'Obese, Beginners, Health Concerns',
                'frequency' => '5-7 times per week',
                'duration' => '20-45 minutes per session'
            ],
            
            'Core Strengthening Program' => [
                'name' => 'Core Strengthening Program',
                'category' => 'Strength Training',
                'difficulty' => 'Beginner to Advanced',
                'description' => 'A comprehensive core training program that strengthens the abdominal, back, and pelvic muscles. Essential for good posture, balance, and overall functional fitness.',
                'muscle_groups' => ['Abdominals', 'Lower Back', 'Pelvic Floor', 'Obliques'],
                'equipment' => 'Exercise Mat, Light Weights (Optional)',
                'instructions' => [
                    'Start with basic exercises: planks, bridges, crunches',
                    'Focus on proper breathing and form',
                    'Perform 2-3 sets of 10-15 repetitions',
                    'Hold static positions for 20-60 seconds',
                    'Progress gradually to more challenging variations',
                    'Include both static and dynamic movements'
                ],
                'benefits' => [
                    'Improves posture and balance',
                    'Reduces risk of back pain and injury',
                    'Enhances athletic performance',
                    'Strengthens the body\'s foundation',
                    'Improves functional movement patterns'
                ],
                'tips' => [
                    'Engage your core throughout the day',
                    'Focus on quality over quantity',
                    'Breathe steadily during exercises',
                    'Don\'t hold your breath',
                    'Progress slowly to avoid injury'
                ],
                'bmi_specific' => 'All BMI Categories',
                'frequency' => '2-3 times per week',
                'duration' => '15-25 minutes per session'
            ],
            
            'Flexibility and Mobility Program' => [
                'name' => 'Flexibility and Mobility Program',
                'category' => 'Flexibility',
                'difficulty' => 'All Levels',
                'description' => 'A comprehensive program to improve flexibility, joint mobility, and range of motion. Essential for injury prevention, better movement, and overall fitness.',
                'muscle_groups' => ['All Major Muscle Groups', 'Joints'],
                'equipment' => 'Exercise Mat, Strap/Towel, Foam Roller (Optional)',
                'instructions' => [
                    'Warm up with light movement before stretching',
                    'Hold static stretches for 20-30 seconds',
                    'Perform dynamic stretches for 10-15 repetitions',
                    'Stretch to the point of mild tension, not pain',
                    'Include both static and dynamic flexibility work',
                    'Focus on major muscle groups and problem areas'
                ],
                'benefits' => [
                    'Improves range of motion',
                    'Reduces risk of injury',
                    'Enhances athletic performance',
                    'Relieves muscle tension and soreness',
                    'Improves posture and movement quality'
                ],
                'tips' => [
                    'Never stretch cold muscles',
                    'Breathe deeply and relax into stretches',
                    'Don\'t bounce or force movements',
                    'Be consistent with your routine',
                    'Listen to your body\'s signals'
                ],
                'bmi_specific' => 'All BMI Categories',
                'frequency' => '3-5 times per week',
                'duration' => '15-30 minutes per session'
            ]
        ];
    }
}
?>
