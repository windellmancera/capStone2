<!-- Chatbot Component (Shared) -->
<div id="chatbot" class="fixed bottom-6 right-6 z-50">
    <!-- Chat Button -->
    <button id="chatbotToggle" class="bg-red-600 hover:bg-red-700 text-white w-14 h-14 rounded-full shadow-lg flex items-center justify-center transition-all duration-300 hover:scale-110">
        <i id="chatbotIcon" class="fas fa-comments text-xl"></i>
    </button>
    <!-- Chat Window -->
    <div id="chatbotWindow" class="absolute bottom-16 right-0 w-96 bg-white rounded-xl shadow-2xl border border-gray-200 hidden transform transition-all duration-300">
        <!-- Chat Header -->
        <div class="bg-red-600 text-white px-6 py-4 rounded-t-xl flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-white rounded-full flex items-center justify-center">
                    <i class="fas fa-dumbbell text-red-600 text-lg"></i>
                </div>
                <div>
                    <h3 class="font-semibold text-lg">FitTracker Assistant</h3>
                    <p class="text-sm text-red-100">Online</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button id="clearHistory" class="text-white hover:text-red-100 transition-colors p-1" title="Clear chat history">
                    <i class="fas fa-trash-alt text-sm"></i>
                </button>
                <button id="chatbotClose" class="text-white hover:text-red-100 transition-colors p-2">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
        </div>
        <!-- Chat Messages -->
        <div id="chatMessages" class="h-96 overflow-y-auto p-6 space-y-4">
            <!-- Welcome Message -->
            <div class="flex items-start gap-3">
                <div class="w-8 h-8 bg-red-100 rounded-full flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-dumbbell text-red-600 text-sm"></i>
                </div>
                <div class="bg-gray-100 rounded-lg px-4 py-3 max-w-[85%]">
                    <p class="text-gray-800">Hi! I'm your FitTracker assistant. How can I help you today?</p>
                    <div class="mt-3 space-y-2">
                        <button class="quick-reply-btn bg-red-100 hover:bg-red-200 text-red-700 text-xs px-3 py-1 rounded-full transition-colors">Membership</button>
                        <button class="quick-reply-btn bg-red-100 hover:bg-red-200 text-red-700 text-xs px-3 py-1 rounded-full transition-colors">Equipment</button>
                        <button class="quick-reply-btn bg-red-100 hover:bg-red-200 text-red-700 text-xs px-3 py-1 rounded-full transition-colors">Trainers</button>
                        <button class="quick-reply-btn bg-red-100 hover:bg-red-200 text-red-700 text-xs px-3 py-1 rounded-full transition-colors">Schedule</button>
                        <button class="quick-reply-btn bg-red-100 hover:bg-red-200 text-red-700 text-xs px-3 py-1 rounded-full transition-colors">Recommendations</button>
                    </div>
                </div>
            </div>
        </div>
        <!-- Chat Input -->
        <div class="border-t border-gray-200 p-4">
            <div class="flex gap-2">
                <input type="text" id="chatInput" placeholder="Type your message..." 
                       class="flex-1 px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500 text-sm shadow-sm">
                <button id="chatSend" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors flex items-center justify-center">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </div>
        </div>
    </div>
</div>
<script>
(function() {
    const chatbotToggle = document.getElementById('chatbotToggle');
    const chatbotWindow = document.getElementById('chatbotWindow');
    const chatbotClose = document.getElementById('chatbotClose');
    const chatbotIcon = document.getElementById('chatbotIcon');
    const chatMessages = document.getElementById('chatMessages');
    const chatInput = document.getElementById('chatInput');
    const chatSend = document.getElementById('chatSend');
    let isChatOpen = false;
    let conversationContext = 'general';
    let messages = [
        { sender: 'bot', text: "Hi! I'm your FitTracker assistant. How can I help you today?", hasQuickReplies: true }
    ];

    // Enhanced response system with context awareness and detailed conversations
    const responses = {
        membership: {
            questions: [
                "What membership plan are you interested in?",
                "Need help with payment or renewal?",
                "Want to upgrade your current plan?"
            ],
            info: [
                "We offer monthly, quarterly, and annual plans!",
                "All plans include access to equipment and group classes.",
                "Premium plans include personal training sessions."
            ]
        },
        equipment: {
            questions: [
                "Looking for specific equipment?",
                "Need help with workout routines?",
                "Want to know equipment availability?"
            ],
            info: [
                "We have cardio, strength, and functional training equipment!",
                "All equipment is regularly maintained and sanitized.",
                "Staff can help you with proper form and technique."
            ]
        },
        trainers: {
            questions: [
                "Looking for a personal trainer?",
                "Want to know trainer specializations?",
                "Need help scheduling sessions?"
            ],
            info: [
                "Our trainers are certified and experienced!",
                "We offer one-on-one and group training sessions.",
                "Trainers can provide personalized recommendations."
            ]
        },
        schedule: {
            questions: [
                "Need class schedules?",
                "Want to book a training session?",
                "Looking for peak hours info?"
            ],
            info: [
                "Gym is open 6am-10pm daily!",
                "Group classes run throughout the day.",
                "Personal training available by appointment."
            ]
        }
    };

    // Conversation memory for context
    let conversationHistory = [];
    let currentTopic = 'general';
    let userPreferences = {};

    // Message history storage
    let messageHistory = [];
    const MAX_HISTORY = 50; // Store last 50 messages

    // Save message history to localStorage
    function saveMessageHistory() {
        try {
            localStorage.setItem('chatbot_history', JSON.stringify(messageHistory));
        } catch (e) {
            console.log('Could not save chat history');
        }
    }

    // Load message history from localStorage
    function loadMessageHistory() {
        try {
            const saved = localStorage.getItem('chatbot_history');
            if (saved) {
                messageHistory = JSON.parse(saved);
                // Limit history to last 50 messages
                if (messageHistory.length > MAX_HISTORY) {
                    messageHistory = messageHistory.slice(-MAX_HISTORY);
                }
            }
        } catch (e) {
            console.log('Could not load chat history');
            messageHistory = [];
        }
    }

    // Add message to history
    function addToHistory(message) {
        messageHistory.push({
            ...message,
            timestamp: Date.now()
        });
        
        // Keep only last 50 messages
        if (messageHistory.length > MAX_HISTORY) {
            messageHistory = messageHistory.slice(-MAX_HISTORY);
        }
        
        saveMessageHistory();
    }

    // Clear message history
    function clearMessageHistory() {
        messageHistory = [];
        localStorage.removeItem('chatbot_history');
        messages = [
            { sender: 'bot', text: "Hi! I'm your FitTracker assistant. How can I help you today?", hasQuickReplies: true }
        ];
        renderMessages();
    }

    function getDetailedBotResponse(userMsg) {
        const msg = userMsg.toLowerCase();
        
        // Track conversation context
        conversationHistory.push({ user: userMsg, timestamp: Date.now() });
        if (conversationHistory.length > 10) conversationHistory.shift();

        // Detailed response logic with comprehensive information
        if (msg.includes('muscle') || msg.includes('gain') || msg.includes('bulk')) {
            currentTopic = 'muscle_gain';
            const detailedResponses = [
                `Great goal! 💪 Building muscle requires a comprehensive approach:

**Training Strategy:**
• Focus on compound exercises: squats, deadlifts, bench press, overhead press
• Train 3-4 times per week with progressive overload
• Aim for 3-4 sets of 8-12 reps per exercise
• Include 1-2 rest days between muscle groups

**Nutrition Requirements:**
• Protein: 1.6-2.2g per kg body weight daily
• Slight calorie surplus (200-500 calories above maintenance)
• Complex carbs for energy, healthy fats for hormone production
• Stay hydrated with 8-10 glasses of water daily

**Recovery:**
• Get 7-9 hours of quality sleep
• Stretch and foam roll regularly
• Consider protein timing around workouts

Our trainers can create a personalized muscle-building program tailored to your schedule and experience level. Would you like to learn more about specific exercises or nutrition planning?`,

                `Building muscle is a fantastic goal! Here's a comprehensive guide:

**Progressive Overload:**
Start with bodyweight exercises, then gradually add weight. Track your progress and increase resistance every 2-4 weeks.

**Exercise Selection:**
Primary: Squats, Deadlifts, Bench Press, Overhead Press
Secondary: Rows, Pull-ups, Dips, Lunges
Accessory: Bicep curls, tricep extensions, calf raises

**Training Split Options:**
• Push/Pull/Legs (3-4x/week)
• Upper/Lower (4x/week)
• Full Body (3x/week)

**Nutrition Tips:**
• Eat every 3-4 hours
• Include protein with every meal
• Pre-workout: carbs + protein
• Post-workout: protein within 30 minutes

Our equipment includes power racks, barbells, dumbbells, and cable machines perfect for muscle building. Need help with form or want to see our strength training area?`
            ];
            return {
                text: detailedResponses[Math.floor(Math.random() * detailedResponses.length)],
                quickReplies: ['Recommendations', 'Nutrition Guide', 'Equipment Tour', 'Trainer Consultation']
            };
        }
        else if (msg.includes('weight loss') || msg.includes('lose weight') || msg.includes('fat loss')) {
            currentTopic = 'weight_loss';
            const detailedResponses = [
                `Weight loss is about creating sustainable habits! 🏃‍♂️ Here's a comprehensive approach:

**Exercise Strategy:**
• Cardio: 150-300 minutes moderate or 75-150 minutes vigorous weekly
• Strength training: 2-3x/week to preserve muscle mass
• HIIT workouts: 2-3x/week for maximum fat burn
• Active recovery: walking, yoga, stretching

**Nutrition Fundamentals:**
• Create a 500-750 calorie daily deficit
• Focus on whole foods: lean proteins, vegetables, fruits
• Limit processed foods and added sugars
• Stay hydrated with 8-10 glasses of water daily

**Lifestyle Factors:**
• Sleep 7-9 hours nightly
• Manage stress through meditation or yoga
• Track progress with measurements and photos
• Be patient - sustainable weight loss is 1-2 lbs/week

Our trainers can design a program combining cardio, strength training, and nutrition guidance. Want to learn about our HIIT classes or meal planning services?`,

                `Effective weight loss requires a balanced approach! Here's your roadmap:

**Cardio Options:**
• Treadmill: Walking, jogging, interval training
• Elliptical: Low-impact cardio
• Rowing machine: Full-body workout
• Stationary bike: Indoor cycling

**Strength Training Benefits:**
• Burns calories during and after workout
• Preserves muscle mass during weight loss
• Improves metabolism
• Enhances body composition

**Nutrition Strategy:**
• Calculate your TDEE (Total Daily Energy Expenditure)
• Eat 500 calories below maintenance
• Protein: 1.2-1.6g per kg body weight
• Fill half your plate with vegetables

**Progress Tracking:**
• Weekly weigh-ins
• Body measurements
• Progress photos
• Energy levels and mood

We offer personal training sessions focused on weight loss, plus nutrition consultations. Ready to start your transformation journey?`
            ];
            return {
                text: detailedResponses[Math.floor(Math.random() * detailedResponses.length)],
                quickReplies: ['Cardio Program', 'Nutrition Plan', 'Personal Training', 'Progress Tracking']
            };
        }
        else if (msg.includes('recommendations') || msg.includes('personalized plan')) {
            currentTopic = 'recommendations';
            return {
                text: "I can help you get personalized recommendations! Our system analyzes your fitness profile, goals, and activity patterns to create custom workout plans. Would you like me to guide you to our recommendations page where you can see your personalized workout schedule, exercise types, and intensity recommendations?",
                quickReplies: ['Yes, Show Recommendations', 'Nutrition Guide', 'Equipment Tour', 'Trainer Consultation']
            };
        }
        else if (msg.includes('yes, show recommendations') || msg.includes('show recommendations')) {
            return {
                text: "Perfect! I'll redirect you to your personalized recommendations page. You'll find your custom workout schedule, exercise recommendations, and intensity guidelines based on your fitness profile.",
                action: 'redirect',
                url: 'recommendations.php'
            };
        }
        else if (msg.includes('workout') || msg.includes('routine') || msg.includes('program')) {
            currentTopic = 'workout_routine';
            const detailedResponses = [
                `Creating an effective workout routine depends on your goals! Here's a comprehensive guide:

**For Beginners (2-3x/week):**
• Day 1: Full body strength training
• Day 2: Cardio (20-30 minutes)
• Day 3: Full body strength training
• Focus on learning proper form first

**For Intermediate (3-4x/week):**
• Day 1: Upper body (push)
• Day 2: Lower body + cardio
• Day 3: Upper body (pull)
• Day 4: Full body + cardio

**For Advanced (4-5x/week):**
• Day 1: Chest/Triceps
• Day 2: Back/Biceps
• Day 3: Legs
• Day 4: Shoulders/Arms
• Day 5: Full body or cardio

**Essential Components:**
• Warm-up: 5-10 minutes cardio + dynamic stretching
• Main workout: 45-60 minutes
• Cool-down: 5-10 minutes stretching
• Progressive overload: gradually increase weight/reps

Our trainers can create a personalized program based on your experience, goals, and schedule. Want to discuss your specific needs?`,

                `Let me help you design the perfect workout routine! Here's what to consider:

**Workout Structure:**
• Warm-up: 5-10 minutes (cardio + mobility)
• Main sets: 3-4 sets per exercise
• Rest periods: 1-3 minutes between sets
• Cool-down: 5-10 minutes stretching

**Exercise Selection:**
• Compound movements first (squats, deadlifts, bench press)
• Isolation exercises second (curls, extensions)
• Core work: planks, crunches, Russian twists
• Cardio: 20-30 minutes post-workout

**Progression Strategy:**
• Week 1-2: Learn form and establish baseline
• Week 3-4: Increase weight by 5-10%
• Week 5-6: Add volume or intensity
• Week 7-8: Deload week (reduce intensity)

**Recovery Tips:**
• Sleep 7-9 hours nightly
• Stay hydrated throughout the day
• Eat protein within 30 minutes post-workout
• Consider foam rolling and stretching

Our equipment includes everything you need for a complete workout. Want to see our facility or meet with a trainer?`
            ];
            return {
                text: detailedResponses[Math.floor(Math.random() * detailedResponses.length)],
                quickReplies: ['Recommendations', 'Equipment Guide', 'Trainer Meeting', 'Class Schedule']
            };
        }
        else if (msg.includes('nutrition') || msg.includes('diet') || msg.includes('food')) {
            currentTopic = 'nutrition';
            const detailedResponses = [
                `Nutrition is the foundation of your fitness journey! Here's a comprehensive guide:

**Macronutrients Breakdown:**
• Protein: 1.2-2.2g per kg body weight (muscle building/repair)
• Carbohydrates: 3-7g per kg body weight (energy)
• Fats: 0.8-1.2g per kg body weight (hormone production)

**Meal Timing:**
• Breakfast: Protein + complex carbs (oatmeal + eggs)
• Pre-workout: Carbs + protein 2-3 hours before
• Post-workout: Protein + carbs within 30 minutes
• Dinner: Protein + vegetables

**Hydration Guidelines:**
• Drink 8-10 glasses of water daily
• Add 16-20 oz during workouts
• Monitor urine color (light yellow = well hydrated)

**Supplements to Consider:**
• Protein powder: post-workout recovery
• Creatine: strength and power
• Multivitamin: fill nutritional gaps
• Omega-3: heart health and recovery

**Meal Planning Tips:**
• Prep meals on weekends
• Use food scale for accurate portions
• Keep healthy snacks available
• Plan for 80% whole foods, 20% flexibility

Our nutritionist can create a personalized meal plan based on your goals and preferences. Ready to optimize your nutrition?`,

                `Let's talk about fueling your fitness journey! Here's your nutrition roadmap:

**For Muscle Building:**
• Calorie surplus: 200-500 calories above maintenance
• Protein: 1.6-2.2g per kg body weight
• Carbs: 4-7g per kg body weight
• Fats: 0.8-1.2g per kg body weight

**For Weight Loss:**
• Calorie deficit: 500-750 calories below maintenance
• Protein: 1.2-1.6g per kg body weight
• Carbs: 2-4g per kg body weight
• Fats: 0.8-1.2g per kg body weight

**Meal Frequency:**
• 3-4 meals per day
• Protein with every meal
• Vegetables with 2-3 meals
• Healthy fats with 2-3 meals

**Pre/Post Workout Nutrition:**
• Pre: Carbs + protein 2-3 hours before
• During: Electrolytes for workouts >60 minutes
• Post: Protein + carbs within 30 minutes

**Smart Food Choices:**
• Proteins: chicken, fish, eggs, Greek yogurt
• Carbs: oats, rice, sweet potatoes, fruits
• Fats: nuts, avocado, olive oil, fatty fish
• Vegetables: spinach, broccoli, carrots, peppers

Want to learn about meal prep strategies or get a personalized nutrition plan?`
            ];
            return {
                text: detailedResponses[Math.floor(Math.random() * detailedResponses.length)],
                quickReplies: ['Meal Plans', 'Supplement Guide', 'Nutrition Consultation', 'Recipe Ideas']
            };
        }
        else if (msg.includes('cardio') || msg.includes('endurance') || msg.includes('stamina')) {
            currentTopic = 'cardio';
            const detailedResponses = [
                `Cardio is essential for heart health and overall fitness! ❤️ Here's your comprehensive guide:

**Cardio Types and Benefits:**
• Steady-state: Builds endurance, burns fat (30-60 minutes)
• HIIT: Maximum calorie burn, improves fitness (20-30 minutes)
• LISS: Low impact, recovery-friendly (45-60 minutes)
• Circuit training: Combines cardio + strength

**Recommended Frequency:**
• Beginners: 3-4x/week, 20-30 minutes
• Intermediate: 4-5x/week, 30-45 minutes
• Advanced: 5-6x/week, 45-60 minutes

**Our Cardio Equipment:**
• Treadmills: Walking, jogging, interval training
• Ellipticals: Low-impact full-body workout
• Rowing machines: Full-body cardio + strength
• Stationary bikes: Indoor cycling experience
• Stair climbers: Lower body focus

**Heart Rate Zones:**
• Zone 1 (50-60%): Recovery, warm-up
• Zone 2 (60-70%): Fat burning, endurance
• Zone 3 (70-80%): Aerobic fitness
• Zone 4 (80-90%): Anaerobic threshold
• Zone 5 (90-100%): Maximum effort

**Progression Strategy:**
• Start with 10-15 minutes, gradually increase
• Mix different cardio types throughout the week
• Include rest days for recovery
• Monitor heart rate and perceived exertion

Our trainers can design a cardio program that fits your fitness level and goals. Want to try our HIIT classes or get a cardio assessment?`,

                `Let's boost your cardiovascular fitness! Here's everything you need to know:

**Cardio Benefits:**
• Strengthens heart and lungs
• Burns calories and fat
• Improves mood and energy
• Reduces stress and anxiety
• Enhances sleep quality

**Workout Structure:**
• Warm-up: 5-10 minutes light cardio
• Main session: 20-60 minutes based on goals
• Cool-down: 5-10 minutes light cardio + stretching

**Intensity Guidelines:**
• Low intensity: Can hold conversation easily
• Moderate intensity: Can talk but not sing
• High intensity: Can only say a few words

**Sample Weekly Plan:**
• Monday: HIIT (20 minutes)
• Wednesday: Steady-state (30 minutes)
• Friday: LISS (45 minutes)
• Weekend: Active recovery (walking, yoga)

**Equipment Recommendations:**
• Treadmill: Great for walking/jogging progression
• Elliptical: Low-impact, full-body movement
• Rowing: Excellent for back and core strength
• Bike: Good for recovery and longer sessions

**Tracking Progress:**
• Heart rate during workouts
• Distance/time improvements
• Perceived exertion levels
• Recovery time between intervals

Ready to elevate your cardio game? Our trainers can create a personalized program!`
            ];
            return {
                text: detailedResponses[Math.floor(Math.random() * detailedResponses.length)],
                quickReplies: ['Cardio Program', 'Equipment Demo', 'HIIT Classes', 'Heart Rate Training']
            };
        }
        else if (msg.includes('trainer') || msg.includes('coach') || msg.includes('personal')) {
            currentTopic = 'trainers';
            const detailedResponses = [
                `Our certified trainers are here to help you reach your goals! Here's what we offer:

**Training Services:**
• One-on-one sessions: ₱500/hour (personalized attention)
• Group training: ₱300/session (motivation + camaraderie)
• Semi-private: ₱400/session (2-3 people)
• Online coaching: ₱300/month (remote guidance)

**Trainer Specializations:**
• Strength training: Powerlifting, bodybuilding, functional fitness
• Weight loss: HIIT, cardio, nutrition guidance
• Sports performance: Sport-specific training, agility, speed
• Rehabilitation: Post-injury recovery, mobility work
• Senior fitness: Low-impact, balance, flexibility

**What's Included:**
• Initial fitness assessment
• Personalized workout program
• Form correction and safety guidance
• Progress tracking and adjustments
• Nutrition advice and meal planning
• Motivation and accountability

**Session Structure:**
• Warm-up and mobility work
• Main workout (strength/cardio/flexibility)
• Cool-down and stretching
• Progress review and next steps

**Booking Process:**
• Free consultation to discuss goals
• Fitness assessment and baseline testing
• Program design based on your needs
• Regular check-ins and program updates

Want to meet our trainers or schedule a free consultation? We can match you with the perfect trainer for your goals!`,

                `Let me introduce you to our amazing training team! Here's what makes us special:

**Our Trainers:**
• All certified by accredited organizations
• Minimum 3 years of experience
• Specialized in various fitness disciplines
• Passionate about helping clients succeed

**Training Approaches:**
• Evidence-based programming
• Individualized attention
• Progressive overload principles
• Injury prevention focus
• Sustainable habit building

**Available Programs:**
• Beginner to advanced levels
• Sport-specific training
• Pre/post-natal fitness
• Senior fitness programs
• Youth fitness (ages 12+)

**Success Stories:**
• Average client sees results in 4-6 weeks
• Improved strength, endurance, and confidence
• Better body composition and health markers
• Long-term lifestyle changes

**Getting Started:**
• Free 30-minute consultation
• Fitness assessment and goal setting
• Trial session to experience our style
• Flexible scheduling options

**Investment in Your Health:**
• Personal training: ₱500/hour
• Group sessions: ₱300/session
• Package discounts available
• Monthly memberships with training included

Ready to transform your fitness journey? Let's find your perfect trainer match!`
            ];
            return {
                text: detailedResponses[Math.floor(Math.random() * detailedResponses.length)],
                quickReplies: ['Trainer Profiles', 'Free Consultation', 'Session Booking', 'Program Options']
            };
        }
        else if (msg.includes('equipment') || msg.includes('machine') || msg.includes('gym')) {
            currentTopic = 'equipment';
            const detailedResponses = [
                `Our gym is equipped with everything you need for a complete workout! Here's our comprehensive facility:

**Cardio Section:**
• 10 Treadmills with TV screens and heart rate monitors
• 8 Elliptical machines with adjustable resistance
• 6 Rowing machines for full-body cardio
• 4 Stationary bikes (upright and recumbent)
• 2 Stair climbers for lower body focus

**Strength Training:**
• Power racks with safety bars for squats and deadlifts
• Smith machines for guided movements
• Cable machines for functional training
• Free weights: dumbbells (5-100 lbs), barbells, weight plates
• Benches: flat, incline, decline, and adjustable

**Functional Training Area:**
• TRX suspension trainers
• Kettlebells (10-80 lbs)
• Medicine balls and resistance bands
• Plyometric boxes and agility ladders
• Battle ropes and sleds

**Group Exercise Studio:**
• Yoga and pilates equipment
• Spin bikes for cycling classes
• Open space for HIIT and circuit training
• Mirrors for form checking

**Safety and Maintenance:**
• All equipment sanitized daily
• Regular maintenance and inspections
• Safety instructions posted on each machine
• Staff available for assistance and form guidance

Want a guided tour of our facility or help learning specific equipment?`,

                `Let me show you around our state-of-the-art gym! Here's what we have:

**Equipment Organization:**
• Cardio zone: Front of gym, near windows
• Strength area: Middle section with racks and machines
• Functional zone: Back area with open space
• Stretching area: Dedicated space with mats and foam rollers

**Popular Equipment Guide:**
• Treadmills: Great for walking, jogging, interval training
• Ellipticals: Low-impact cardio, full-body movement
• Squat racks: Compound movements, progressive overload
• Cable machines: Versatile, adjustable resistance
• Dumbbells: Free weight training, functional movements

**Equipment Etiquette:**
• Wipe down equipment after use
• Return weights to proper racks
• Allow others to work in between sets
• Keep personal items in designated areas
• Follow posted time limits during peak hours

**Getting Started:**
• Orientation session with staff
• Equipment demonstration videos
• Form check sessions with trainers
• Progressive introduction to new equipment

**Safety Features:**
• Emergency stop buttons on all cardio equipment
• Safety bars and spotters available
• First aid kit and AED on premises
• Trained staff for emergency response

**Peak Hours:**
• Busiest: 6-8am and 6-8pm weekdays
• Quieter: 9am-4pm weekdays, all day weekends
• 24/7 access for premium members

Ready to explore our equipment or need help with specific machines?`
            ];
            return {
                text: detailedResponses[Math.floor(Math.random() * detailedResponses.length)],
                quickReplies: ['Equipment Tour', 'Form Guidance', 'Safety Tips', 'Peak Hours']
            };
        }
        else if (msg.includes('schedule') || msg.includes('hours') || msg.includes('time') || msg.includes('class')) {
            currentTopic = 'schedule';
            const detailedResponses = [
                `Here's our comprehensive schedule to help you plan your workouts! 📅

**Gym Hours:**
• Monday-Friday: 6:00 AM - 10:00 PM
• Saturday-Sunday: 7:00 AM - 9:00 PM
• Holidays: 8:00 AM - 8:00 PM

**Peak Hours (Busiest):**
• Weekday mornings: 6:00-8:00 AM
• Weekday evenings: 6:00-8:00 PM
• Weekend mornings: 8:00-10:00 AM

**Quiet Hours (Less Crowded):**
• Weekday afternoons: 9:00 AM - 4:00 PM
• Weekend afternoons: 2:00-6:00 PM

**Group Class Schedule:**
• Monday: HIIT (7AM, 6PM), Yoga (5PM), Zumba (7PM)
• Tuesday: Strength Training (7AM, 6PM), Pilates (5PM)
• Wednesday: Cardio Blast (7AM, 6PM), Yoga (5PM)
• Thursday: HIIT (7AM, 6PM), Dance Fitness (7PM)
• Friday: Strength Training (7AM, 6PM), Stretching (5PM)
• Saturday: HIIT (9AM), Yoga (10AM), Zumba (11AM)
• Sunday: Recovery Yoga (9AM), Light Cardio (10AM)

**Personal Training Sessions:**
• Available by appointment
• Most trainers work 7:00 AM - 9:00 PM
• Weekend sessions available
• 30-minute and 60-minute options

**Class Capacity:**
• Maximum 15 participants per class
• Pre-registration recommended
• Drop-ins welcome if space available

Need help finding the perfect time for your workout or want to book a class?`,

                `Let me help you find the perfect workout time! Here's our detailed schedule:

**Early Bird Special (6-8AM):**
• Less crowded, great for focused workouts
• High energy group classes
• Personal training available
• Perfect for morning people

**Mid-Morning (9AM-12PM):**
• Quietest time of day
• Great for learning new exercises
• Personal training sessions
• Ideal for beginners

**Lunch Break (12-2PM):**
• Quick 30-45 minute workouts
• Express classes available
• Less crowded than peak hours
• Good for cardio sessions

**Afternoon (2-5PM):**
• Moderate crowd levels
• Personal training available
• Good for strength training
• Flexible class options

**Evening Peak (6-8PM):**
• Busiest time, high energy
• Full class schedule
• Social atmosphere
• All equipment available

**Late Evening (8-10PM):**
• Crowd starts to thin
• Focused workout time
• Personal training available
• Good for longer sessions

**Weekend Schedule:**
• Saturday: Classes 9AM-12PM, open gym all day
• Sunday: Relaxed atmosphere, recovery focus
• Personal training available both days

**Class Types:**
• HIIT: High-intensity interval training
• Strength: Weight training and bodyweight
• Yoga: Flexibility and mindfulness
• Zumba: Dance fitness and cardio
• Pilates: Core strength and stability

Want to check class availability or book a session?`
            ];
            return {
                text: detailedResponses[Math.floor(Math.random() * detailedResponses.length)],
                quickReplies: ['Class Booking', 'Peak Hours', 'Personal Training', 'Weekend Schedule']
            };
        }
        else if (msg.includes('health') || msg.includes('wellness') || msg.includes('wellbeing')) {
            currentTopic = 'health_wellness';
            const detailedResponses = [
                `Health and wellness go beyond just exercise! Here's a comprehensive approach to overall wellbeing:

**Physical Health:**
• Regular exercise: 150 minutes moderate or 75 minutes vigorous weekly
• Quality sleep: 7-9 hours nightly for recovery and hormone regulation
• Hydration: 8-10 glasses of water daily, more during exercise
• Regular check-ups: Annual physical exams and health screenings

**Mental Health:**
• Stress management: Meditation, deep breathing, yoga
• Social connections: Build supportive relationships
• Work-life balance: Set boundaries and take breaks
• Hobbies and interests: Engage in activities you enjoy

**Preventive Care:**
• Annual physical examinations
• Regular dental check-ups
• Vision screenings
• Vaccinations and immunizations
• Health screenings based on age and risk factors

**Lifestyle Factors:**
• Avoid smoking and excessive alcohol
• Maintain healthy weight
• Practice good hygiene
• Regular hand washing
• Adequate sun protection

**Recovery and Rest:**
• Active recovery days
• Stretching and mobility work
• Foam rolling and massage
• Quality sleep hygiene practices
• Stress reduction techniques

Our trainers can help create a holistic wellness program that addresses all aspects of health. Want to learn more about stress management or sleep optimization?`,

                `Let's talk about comprehensive health and wellness! Here's your complete guide:

**Holistic Health Approach:**
• Physical fitness: Strength, cardio, flexibility
• Mental wellness: Stress management, mindfulness
• Nutritional health: Balanced diet, proper hydration
• Social health: Community, relationships, support
• Environmental health: Clean surroundings, nature exposure

**Mental Wellness Strategies:**
• Meditation: 10-20 minutes daily for stress reduction
• Deep breathing: 4-7-8 technique for relaxation
• Journaling: Express thoughts and track progress
• Gratitude practice: Focus on positive aspects
• Social activities: Connect with friends and family

**Stress Management:**
• Exercise: Natural stress reliever and mood booster
• Meditation: 10-20 minutes daily for stress reduction
• Deep Breathing: 4-7-8 technique for immediate relief
• Progressive Relaxation: Tense and release muscle groups
• Mindfulness: Present-moment awareness throughout the day

**Mental Health and Exercise:**
• Endorphin Release: Exercise naturally boosts mood
• Social Connection: Group classes and workout buddies
• Achievement: Meeting fitness goals builds confidence
• Routine: Structure and consistency reduce anxiety
• Self-care: Exercise as a form of self-care

**Mindfulness Practices:**
• Body Scans: Progressive attention to body sensations
• Breathing Exercises: Focus on breath patterns
• Walking Meditation: Mindful movement and awareness
• Gratitude Practice: Daily appreciation for positive aspects
• Present Moment: Focus on current experience, not worries

**Anxiety Management:**
• Regular Exercise: Consistent physical activity reduces anxiety
• Sleep Hygiene: Quality sleep supports mental health
• Social Support: Connect with friends, family, community
• Professional Help: Don't hesitate to seek counseling
• Stress Reduction: Identify and address stress sources

**Building Mental Resilience:**
• Goal Setting: Clear, achievable fitness and life goals
• Progress Tracking: Celebrate small victories and improvements
• Self-compassion: Be kind to yourself during setbacks
• Flexibility: Adapt to changing circumstances
• Support Systems: Build networks of supportive people

**Exercise for Mental Health:**
• Cardio: 30 minutes daily for mood improvement
• Strength Training: Builds confidence and self-efficacy
• Yoga: Combines physical and mental wellness
• Outdoor Activities: Nature exposure reduces stress
• Group Exercise: Social connection and motivation

Our trainers can help create workouts that support both physical and mental health. Want to learn more about stress management or mindfulness practices?`
            ];
            return {
                text: detailedResponses[Math.floor(Math.random() * detailedResponses.length)],
                quickReplies: ['Stress Management', 'Sleep Optimization', 'Mental Wellness', 'Preventive Care']
            };
        }
        else if (msg.includes('lifestyle') || msg.includes('daily routine') || msg.includes('habits')) {
            currentTopic = 'lifestyle';
            const detailedResponses = [
                `Creating a healthy lifestyle is about building sustainable habits! Here's how to transform your daily routine:

**Morning Routine (6-8AM):**
• Wake up at consistent time (even weekends)
• Hydrate: Drink 16-20 oz water immediately
• Light stretching or yoga (10-15 minutes)
• Healthy breakfast: Protein + complex carbs
• Plan your day and set priorities

**Workday Habits:**
• Take regular breaks every 60-90 minutes
• Stand up and move every 30 minutes
• Stay hydrated throughout the day
• Pack healthy snacks and lunch
• Practice stress management techniques

**Evening Routine (6-9PM):**
• Exercise: 30-60 minutes of activity
• Healthy dinner: Protein + vegetables
• Relaxation time: Reading, hobbies, family
• Prepare for next day: Plan meals, lay out clothes
• Digital detox: Limit screen time 1 hour before bed

**Sleep Hygiene:**
• Consistent bedtime (10-11 PM)
• Cool, dark, quiet bedroom
• No screens 1 hour before bed
• Relaxing bedtime routine
• Avoid caffeine after 2 PM

**Weekly Planning:**
• Meal prep on weekends
• Schedule workouts in advance
• Plan social activities
• Set aside time for hobbies
• Review and adjust goals

**Habit Building Tips:**
• Start small: One new habit at a time
• Stack habits: Link new habits to existing ones
• Track progress: Use apps or journals
• Celebrate small wins
• Be patient: Habits take 21-66 days to form

Want help creating a personalized daily routine or building specific healthy habits?`
            ];
            return {
                text: detailedResponses[Math.floor(Math.random() * detailedResponses.length)],
                quickReplies: ['Daily Routine', 'Habit Building', 'Time Management', 'Work-Life Balance']
            };
        }
        else if (msg.includes('supplement') || msg.includes('vitamin') || msg.includes('protein') || msg.includes('creatine')) {
            currentTopic = 'supplements';
            const detailedResponses = [
                `Supplements can support your fitness goals when used properly! Here's a comprehensive guide:

**Essential Supplements:**
• **Protein Powder**: 20-30g post-workout for muscle recovery
• **Creatine**: 3-5g daily for strength and power gains
• **Multivitamin**: Fill nutritional gaps, especially for active individuals
• **Omega-3**: Heart health, recovery, and inflammation reduction

**Timing and Dosage:**
• **Pre-workout**: Caffeine (200-400mg), BCAAs (5-10g)
• **During workout**: Electrolytes for sessions >60 minutes
• **Post-workout**: Protein (20-30g) within 30 minutes
• **Daily**: Multivitamin, omega-3, creatine

**Quality and Safety:**
• Choose third-party tested brands
• Look for NSF, USP, or Informed Sport certifications
• Start with lower doses and gradually increase
• Consult healthcare provider before starting new supplements
• More isn't always better - follow recommended dosages

**When to Consider Supplements:**
• Protein: If struggling to meet daily protein needs
• Creatine: For strength and power goals
• Multivitamin: If diet is limited or restricted
• Omega-3: If not eating fatty fish regularly
• Vitamin D: If limited sun exposure

**Natural Alternatives:**
• Protein: Greek yogurt, eggs, lean meats
• Creatine: Found in red meat and fish
• Omega-3: Fatty fish, walnuts, flaxseeds
• Vitamins: Colorful fruits and vegetables

**Safety Guidelines:**
• Don't exceed recommended dosages
• Cycle off stimulants periodically
• Stay hydrated, especially with protein
• Monitor for any adverse reactions
• Consult professionals for medical conditions

Our nutritionist can help you determine which supplements might benefit your specific goals and health status. Want to learn more about specific supplements or get a personalized recommendation?`
            ];
            return {
                text: detailedResponses[Math.floor(Math.random() * detailedResponses.length)],
                quickReplies: ['Supplement Guide', 'Personalized Plan', 'Quality Brands', 'Safety Tips']
            };
        }
        else if (msg.includes('injury') || msg.includes('pain') || msg.includes('recovery') || msg.includes('rehab')) {
            currentTopic = 'injury_recovery';
            const detailedResponses = [
                `Injury prevention and recovery are crucial for long-term fitness success! Here's your comprehensive guide:

**Injury Prevention Strategies:**
• **Proper Warm-up**: 10-15 minutes dynamic stretching
• **Progressive Overload**: Gradually increase intensity
• **Good Form**: Focus on technique over weight
• **Rest Days**: Allow adequate recovery between sessions
• **Balanced Training**: Don't neglect any muscle groups

**Common Injuries and Prevention:**
• **Lower Back**: Strengthen core, maintain proper form
• **Knees**: Strengthen quads/hamstrings, avoid overuse
• **Shoulders**: Rotator cuff exercises, proper pressing form
• **Ankles**: Balance exercises, proper footwear
• **Wrists**: Grip strength, proper hand positioning

**Recovery Techniques:**
• **RICE Method**: Rest, Ice, Compression, Elevation
• **Foam Rolling**: Self-myofascial release
• **Stretching**: Dynamic pre-workout, static post-workout
• **Massage**: Professional or self-massage
• **Active Recovery**: Light movement on rest days

**When to Seek Professional Help:**
• Persistent pain lasting more than 1-2 weeks
• Sharp, shooting pain during movement
• Swelling, bruising, or visible deformity
• Pain that interferes with daily activities
• Numbness, tingling, or loss of function

**Return to Exercise Protocol:**
• Start with low-intensity, pain-free movements
• Gradually increase intensity and volume
• Listen to your body - pain is a warning sign
• Work with professionals for proper rehabilitation
• Don't rush back to previous intensity

**Recovery Nutrition:**
• Adequate protein for tissue repair
• Anti-inflammatory foods (omega-3, turmeric)
• Proper hydration for healing
• Vitamin C and zinc for immune support
• Consider collagen supplements for connective tissue

Our trainers can help modify workouts around injuries and create safe return-to-exercise programs. Need help with specific injury management?`
            ];
            return {
                text: detailedResponses[Math.floor(Math.random() * detailedResponses.length)],
                quickReplies: ['Injury Prevention', 'Recovery Techniques', 'Modified Workouts', 'Professional Help']
            };
        }
        else if (msg.includes('sleep') || msg.includes('rest') || msg.includes('recovery')) {
            currentTopic = 'sleep_recovery';
            const detailedResponses = [
                `Quality sleep is the foundation of fitness success! Here's your complete sleep optimization guide:

**Sleep and Fitness Connection:**
• **Muscle Growth**: 70% of growth hormone released during deep sleep
• **Recovery**: Tissues repair and rebuild during sleep
• **Performance**: Poor sleep reduces strength and endurance
• **Hormones**: Sleep regulates cortisol, testosterone, and insulin
• **Mental Health**: Sleep affects motivation and mood

**Sleep Hygiene Best Practices:**
• **Consistent Schedule**: Same bedtime and wake time daily
• **Sleep Environment**: Cool (65-68°F), dark, quiet room
• **Digital Detox**: No screens 1 hour before bed
• **Relaxation Routine**: Reading, meditation, light stretching
• **Avoid Stimulants**: No caffeine after 2 PM

**Pre-Sleep Routine:**
• **Evening Wind-down**: Start 1-2 hours before bed
• **Light Activity**: Gentle stretching or yoga
• **Relaxation**: Deep breathing, meditation, reading
• **Environment Prep**: Dim lights, cool temperature
• **Mindfulness**: Let go of daily stresses

**Sleep Cycle Optimization:**
• **Circadian Rhythm**: Align with natural light cycles
• **Morning Light**: 10-30 minutes sunlight exposure
• **Evening Darkness**: Reduce artificial light exposure
• **Temperature**: Cooler room promotes better sleep
• **Consistency**: Even on weekends, maintain schedule

**Recovery Sleep Strategies:**
• **Post-Workout**: Extra sleep needed after intense training
• **Stress Management**: High stress requires more sleep
• **Nutrition**: Avoid heavy meals 2-3 hours before bed
• **Hydration**: Balance hydration without night interruptions
• **Supplements**: Magnesium, melatonin (consult doctor)

**Sleep Tracking and Improvement:**
• **Monitor Sleep**: Use apps or devices to track patterns
• **Identify Issues**: Note what affects sleep quality
• **Gradual Changes**: Make one change at a time
• **Professional Help**: Consult sleep specialist if needed
• **Patience**: Sleep improvements take time

**Recovery Days and Sleep:**
• **Active Recovery**: Light activity promotes better sleep
• **Stretching**: Gentle evening stretching improves sleep
• **Nutrition Timing**: Protein before bed supports recovery
• **Stress Reduction**: Lower stress improves sleep quality
• **Consistency**: Regular sleep schedule supports recovery

Want to create a personalized sleep optimization plan or learn more about recovery strategies?`
            ];
            return {
                text: detailedResponses[Math.floor(Math.random() * detailedResponses.length)],
                quickReplies: ['Sleep Optimization', 'Recovery Strategies', 'Stress Management', 'Sleep Tracking']
            };
        }
        else if (msg.includes('stress') || msg.includes('anxiety') || msg.includes('mental health') || msg.includes('mindfulness')) {
            currentTopic = 'mental_health';
            const detailedResponses = [
                `Mental health is just as important as physical health for your fitness journey! Here's a comprehensive guide:

**Stress and Fitness Connection:**
• **Cortisol Impact**: High stress increases cortisol, affecting muscle growth
• **Recovery**: Stress impairs sleep and recovery processes
• **Motivation**: Mental health affects workout consistency
• **Performance**: Stress reduces focus and physical performance
• **Long-term Success**: Mental wellness supports sustainable habits

**Stress Management Techniques:**
• **Exercise**: Natural stress reliever and mood booster
• **Meditation**: 10-20 minutes daily for stress reduction
• **Deep Breathing**: 4-7-8 technique for immediate relief
• **Progressive Relaxation**: Tense and release muscle groups
• **Mindfulness**: Present-moment awareness throughout the day

**Mental Health and Exercise:**
• **Endorphin Release**: Exercise naturally boosts mood
• **Social Connection**: Group classes and workout buddies
• **Achievement**: Meeting fitness goals builds confidence
• **Routine**: Structure and consistency reduce anxiety
• **Self-care**: Exercise as a form of self-care

**Mindfulness Practices:**
• **Body Scans**: Progressive attention to body sensations
• **Breathing Exercises**: Focus on breath patterns
• **Walking Meditation**: Mindful movement and awareness
• **Gratitude Practice**: Daily appreciation for positive aspects
• **Present Moment**: Focus on current experience, not worries

**Anxiety Management:**
• **Regular Exercise**: Consistent physical activity reduces anxiety
• **Sleep Hygiene**: Quality sleep supports mental health
• **Social Support**: Connect with friends, family, community
• **Professional Help**: Don't hesitate to seek counseling
• **Stress Reduction**: Identify and address stress sources

**Building Mental Resilience:**
• **Goal Setting**: Clear, achievable fitness and life goals
• **Progress Tracking**: Celebrate small victories and improvements
• **Self-compassion**: Be kind to yourself during setbacks
• **Flexibility**: Adapt to changing circumstances
• **Support Systems**: Build networks of supportive people

**Exercise for Mental Health:**
• **Cardio**: 30 minutes daily for mood improvement
• **Strength Training**: Builds confidence and self-efficacy
• **Yoga**: Combines physical and mental wellness
• **Outdoor Activities**: Nature exposure reduces stress
• **Group Exercise**: Social connection and motivation

Our trainers can help create workouts that support both physical and mental health. Want to learn more about stress management or mindfulness practices?`
            ];
            return {
                text: detailedResponses[Math.floor(Math.random() * detailedResponses.length)],
                quickReplies: ['Stress Management', 'Mindfulness Practices', 'Mental Wellness', 'Professional Support']
            };
        }
        else if (msg.includes('hello') || msg.includes('hi') || msg.includes('hey')) {
            const greetings = [
                "Hello! 👋 How's your fitness journey going today? I'm here to help with any questions about workouts, nutrition, equipment, trainers, or anything fitness-related. What would you like to know?",
                "Hi there! 💪 Ready for an amazing workout session? I can help you with workout plans, nutrition advice, equipment guidance, or connect you with our trainers. What's on your mind today?",
                "Hey! 🏋️‍♂️ What fitness goals are you working on today? Whether it's muscle building, weight loss, improving strength, or just getting started, I'm here to provide detailed guidance and support!"
            ];
            return {
                text: greetings[Math.floor(Math.random() * greetings.length)],
                quickReplies: ['Workout Plans', 'Nutrition Guide', 'Equipment Help', 'Trainer Info', 'Fitness Goals']
            };
        }
        else if (msg.includes('help') || msg.includes('support')) {
            return {
                text: "I'm here to help with all your fitness and wellness needs! I can provide detailed information about:\n\n• **Recommendations**: Personalized workout plans, exercise guidance, progression strategies\n• **Nutrition**: Meal planning, macronutrient breakdown, supplement advice\n• **Equipment**: Machine guides, safety tips, proper form instruction\n• **Trainers**: Specializations, booking sessions, consultation services\n• **Schedule**: Class times, peak hours, personal training availability\n• **Fitness Goals**: Muscle building, weight loss, strength training, cardio programs\n• **Health & Wellness**: Mental health, stress management, preventive care\n• **Lifestyle**: Daily routines, habit building, work-life balance\n• **Supplements**: Protein, vitamins, safety guidelines, quality brands\n• **Injury Prevention**: Recovery techniques, modified workouts, rehabilitation\n• **Sleep & Recovery**: Sleep optimization, rest strategies, recovery protocols\n• **Mental Health**: Stress management, mindfulness, anxiety reduction\n\nWhat specific area would you like to explore in detail?",
                quickReplies: ['Workout Programs', 'Nutrition Plans', 'Health & Wellness', 'Lifestyle Tips', 'Mental Health']
            };
        }
        else if (msg.includes('thank') || msg.includes('thanks')) {
            return {
                text: "You're welcome! 😊 I'm here to support your fitness journey every step of the way. Is there anything else you'd like to know about workouts, nutrition, equipment, or our services?",
                quickReplies: ['More Help', 'Workout Tips', 'Nutrition Advice', 'Equipment Guide']
            };
        }
        else if (msg.includes('bye') || msg.includes('goodbye')) {
            return {
                text: "Have a fantastic workout! 💪 Remember to stay hydrated, listen to your body, and keep pushing toward your goals. I'll be here when you need more guidance. See you next time!",
                quickReplies: []
            };
        }
        else {
            const fallbackResponses = [
                "I'm here to help with comprehensive fitness and wellness guidance! I can provide detailed information about recommendations, nutrition plans, equipment usage, trainer services, class schedules, health & wellness, lifestyle tips, supplements, injury prevention, sleep optimization, mental health, and specific fitness goals. What would you like to learn more about?",
                "Let me help you with detailed fitness and wellness information! I can guide you through recommendations, nutrition strategies, equipment training, personal training services, health optimization, lifestyle improvements, supplement guidance, injury prevention, sleep strategies, mental wellness, or help you achieve specific fitness goals. What area would you like to explore?",
                "I'm your comprehensive fitness and wellness assistant! I can provide detailed guidance on recommendations, nutrition advice, equipment tutorials, trainer consultations, class schedules, health & wellness strategies, lifestyle optimization, supplement recommendations, injury prevention, sleep optimization, mental health support, and help you reach your specific fitness goals. What would you like to know more about?"
            ];
            return {
                text: fallbackResponses[Math.floor(Math.random() * fallbackResponses.length)],
                quickReplies: ['Workout Programs', 'Nutrition Guide', 'Health & Wellness', 'Lifestyle Tips', 'Mental Health']
            };
        }
    }

    function renderMessages() {
        chatMessages.innerHTML = '';
        messages.forEach(msg => {
            if (msg.sender === 'bot') {
                let quickRepliesHtml = '';
                if (msg.hasQuickReplies && msg.quickReplies) {
                    quickRepliesHtml = '<div class="mt-3 space-y-2">';
                    msg.quickReplies.forEach(reply => {
                        quickRepliesHtml += `<button class="quick-reply-btn bg-red-100 hover:bg-red-200 text-red-700 text-xs px-3 py-1 rounded-full transition-colors">${reply}</button>`;
                    });
                    quickRepliesHtml += '</div>';
                }
                
                chatMessages.innerHTML += `
                    <div class="flex items-start gap-3">
                        <div class="w-8 h-8 bg-red-100 rounded-full flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-dumbbell text-red-600 text-sm"></i>
                        </div>
                        <div class="bg-gray-100 rounded-lg px-4 py-3 max-w-[85%]">
                            <p class="text-gray-800">${msg.text}</p>
                            ${quickRepliesHtml}
                        </div>
                    </div>
                `;
            } else {
                chatMessages.innerHTML += `
                    <div class="flex items-end gap-3 justify-end">
                        <div class="bg-red-600 text-white rounded-lg px-4 py-3 max-w-[85%]">
                            <p>${msg.text}</p>
                        </div>
                        <div class="w-8 h-8 bg-red-100 rounded-full flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-user text-red-600 text-sm"></i>
                        </div>
                    </div>
                `;
            }
        });
        chatMessages.scrollTop = chatMessages.scrollHeight;
        
            // Add event listeners to quick reply buttons
    document.querySelectorAll('.quick-reply-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const replyText = this.textContent;
            sendQuickReply(replyText);
        });
    });
    
    // Also attach event listeners to initial welcome message buttons
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.quick-reply-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const replyText = this.textContent;
                sendQuickReply(replyText);
            });
        });
    });
    
    // Use event delegation for all quick reply buttons (including dynamically added ones)
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('quick-reply-btn')) {
            const replyText = e.target.textContent;
            sendQuickReply(replyText);
        }
    });
    }

    function sendQuickReply(replyText) {
        messages.push({ sender: 'user', text: replyText });
        addToHistory({ sender: 'user', text: replyText });
        renderMessages();
        chatInput.value = '';
        
        setTimeout(() => {
            const botReply = getDetailedBotResponse(replyText);
            
            // Handle redirect actions
            if (botReply.action === 'redirect' && botReply.url) {
                messages.push({ 
                    sender: 'bot', 
                    text: botReply.text
                });
                addToHistory({ 
                    sender: 'bot', 
                    text: botReply.text
                });
                renderMessages();
                
                // Redirect after a short delay
                setTimeout(() => {
                    window.location.href = botReply.url;
                }, 2000);
            } else {
                messages.push({ 
                    sender: 'bot', 
                    text: botReply.text, 
                    quickReplies: botReply.quickReplies 
                });
                addToHistory({ 
                    sender: 'bot', 
                    text: botReply.text, 
                    quickReplies: botReply.quickReplies 
                });
                renderMessages();
            }
        }, 300);
    }

    function toggleChat() {
        isChatOpen = !isChatOpen;
        if (isChatOpen) {
            chatbotWindow.classList.remove('hidden');
            chatbotIcon.className = 'fas fa-times text-xl';
            chatInput.focus();
            
            // Load message history if this is the first time opening
            if (messages.length === 1) {
                loadMessageHistory();
                if (messageHistory.length > 0) {
                    // Restore recent messages (last 10)
                    const recentMessages = messageHistory.slice(-10);
                    messages = [
                        { sender: 'bot', text: "Hi! I'm your FitTracker assistant. How can I help you today?", hasQuickReplies: true }
                    ];
                    recentMessages.forEach(msg => {
                        if (msg.sender === 'user' || msg.sender === 'bot') {
                            messages.push({
                                sender: msg.sender,
                                text: msg.text,
                                quickReplies: msg.quickReplies
                            });
                        }
                    });
                }
            }
            
            renderMessages();
        } else {
            chatbotWindow.classList.add('hidden');
            chatbotIcon.className = 'fas fa-comments text-xl';
        }
    }

    function sendMessage() {
        const userMsg = chatInput.value.trim();
        if (!userMsg) return;
        
        messages.push({ sender: 'user', text: userMsg });
        addToHistory({ sender: 'user', text: userMsg });
        renderMessages();
        chatInput.value = '';
        
        // Show typing indicator
        const typingIndicator = document.createElement('div');
        typingIndicator.className = 'flex items-start gap-3 typing-indicator';
        typingIndicator.innerHTML = `
            <div class="w-8 h-8 bg-red-100 rounded-full flex items-center justify-center flex-shrink-0">
                <i class="fas fa-dumbbell text-red-600 text-sm"></i>
            </div>
            <div class="bg-gray-100 rounded-lg px-4 py-3">
                <div class="flex space-x-1">
                    <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce"></div>
                    <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0.1s"></div>
                    <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0.2s"></div>
                </div>
            </div>
        `;
        chatMessages.appendChild(typingIndicator);
        chatMessages.scrollTop = chatMessages.scrollHeight;
        
        setTimeout(() => {
            // Remove typing indicator
            const typingIndicator = document.querySelector('.typing-indicator');
            if (typingIndicator) {
                typingIndicator.remove();
            }
            
            const botReply = getDetailedBotResponse(userMsg);
            
            // Handle redirect actions
            if (botReply.action === 'redirect' && botReply.url) {
                messages.push({ 
                    sender: 'bot', 
                    text: botReply.text
                });
                addToHistory({ 
                    sender: 'bot', 
                    text: botReply.text
                });
                renderMessages();
                
                // Redirect after a short delay
                setTimeout(() => {
                    window.location.href = botReply.url;
                }, 2000);
            } else {
                messages.push({ 
                    sender: 'bot', 
                    text: botReply.text, 
                    quickReplies: botReply.quickReplies 
                });
                addToHistory({ 
                    sender: 'bot', 
                    text: botReply.text, 
                    quickReplies: botReply.quickReplies 
                });
                renderMessages();
            }
        }, 1000 + Math.random() * 1000); // Random delay between 1-2 seconds
    }

    chatbotToggle.addEventListener('click', toggleChat);
    chatbotClose.addEventListener('click', toggleChat);
    document.getElementById('clearHistory').addEventListener('click', clearMessageHistory);
    chatSend.addEventListener('click', sendMessage);
    chatInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            sendMessage();
        }
    });

    // Add click outside to close functionality
    document.addEventListener('click', function(e) {
        if (isChatOpen && !chatbotWindow.contains(e.target) && !chatbotToggle.contains(e.target)) {
            toggleChat();
        }
    });
})();
</script> 