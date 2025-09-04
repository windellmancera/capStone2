-- Sample Announcements
INSERT INTO announcements (title, content, priority) VALUES
('Urgent: Temporary Closure', 'Due to an emergency maintenance, the gym will be closed tomorrow from 10 AM to 2 PM. We apologize for any inconvenience.', 'Urgent'),
('New Year Fitness Challenge', 'Join our 30-day New Year fitness challenge starting January 1st. Sign up at the front desk for a chance to win exciting prizes!', 'High'),
('Holiday Schedule Changes', 'The gym will be operating on special hours during the upcoming holiday season. Please check the schedule board for details.', 'High'),
('New Equipment Arrival', 'We are excited to announce the arrival of new state-of-the-art cardio equipment! Come try them out starting next week.', 'Medium'),
('Monthly Member Spotlight', 'Congratulations to John Smith for achieving his fitness goals! Read his inspiring journey on our blog.', 'Low'),
('Weekend Yoga Workshop', 'Join our special yoga workshop this weekend. Perfect for all skill levels. Limited spots available!', 'Medium');

-- Sample Services
INSERT INTO services (name, description, price, image_url) VALUES
('Personal Training', 'One-on-one training sessions with our certified personal trainers. Customized workout plans and nutrition guidance included.', 1500.00, 'images/services/personal-training.jpg'),
('Group Classes', 'Join our energetic group classes including Zumba, Yoga, Spinning, and HIIT. Perfect for those who enjoy working out with others.', 800.00, 'images/services/group-classes.jpg'),
('Nutrition Consultation', 'Get personalized nutrition advice from our certified nutritionists. Includes meal planning and dietary recommendations.', 1000.00, 'images/services/nutrition.jpg'),
('Gym Membership', 'Full access to our state-of-the-art gym equipment and facilities. Includes locker room access and basic fitness assessment.', 2000.00, 'images/services/gym.jpg'),
('CrossFit Training', 'High-intensity functional training in a group setting. Led by certified CrossFit trainers.', 1200.00, 'images/services/crossfit.jpg');

-- Sample Trainers
INSERT INTO trainers (name, specialization, experience_years, bio, image_url, contact_number, email) VALUES
('John Smith', 'Weight Training', 8, 'Certified personal trainer specializing in strength training and muscle building. Helped numerous clients achieve their fitness goals.', 'images/trainers/john.jpg', '09123456789', 'john.smith@almofitness.com'),
('Maria Garcia', 'Yoga & Pilates', 6, 'Certified yoga instructor with expertise in various yoga styles. Passionate about helping clients improve flexibility and mindfulness.', 'images/trainers/maria.jpg', '09234567890', 'maria.garcia@almofitness.com'),
('Mike Johnson', 'CrossFit', 5, 'CrossFit Level 2 trainer with a background in competitive athletics. Specializes in high-intensity workouts and functional fitness.', 'images/trainers/mike.jpg', '09345678901', 'mike.johnson@almofitness.com'),
('Sarah Lee', 'Nutrition & Weight Loss', 7, 'Certified nutritionist and personal trainer. Expert in creating comprehensive weight loss programs combining exercise and diet.', 'images/trainers/sarah.jpg', '09456789012', 'sarah.lee@almofitness.com'),
('David Wilson', 'Sports Conditioning', 10, 'Former professional athlete with expertise in sports-specific training and rehabilitation. Helps athletes improve performance and prevent injuries.', 'images/trainers/david.jpg', '09567890123', 'david.wilson@almofitness.com');

-- Sample Equipment Data
INSERT INTO equipment (name, description, category, status, quantity) VALUES
('Treadmill Pro 2000', 'Commercial grade treadmill with incline and speed controls', 'cardio', 'Available', 5),
('Smith Machine', 'Multi-purpose weight training machine', 'strength', 'Available', 2),
('Adjustable Dumbbells', 'Set of adjustable dumbbells from 5-50 lbs', 'free-weights', 'Available', 10),
('Rowing Machine', 'Air resistance rowing machine for full body workout', 'cardio', 'Available', 3),
('Power Rack', 'Heavy duty power rack with safety bars', 'strength', 'Available', 2),
('Kettlebell Set', 'Set of kettlebells ranging from 5-40 kg', 'free-weights', 'Available', 4),
('Battle Ropes', '40ft battle ropes for functional training', 'functional', 'Available', 2),
('Spin Bike', 'Commercial grade spinning bike with digital display', 'cardio', 'Available', 8),
('TRX Suspension', 'Suspension training system', 'functional', 'Available', 6),
('Olympic Barbell Set', 'Olympic barbell with weight plates', 'free-weights', 'Available', 5),
('Leg Press Machine', 'Plate loaded leg press machine', 'strength', 'Available', 2),
('Medicine Ball Set', 'Set of medicine balls from 2-20 kg', 'functional', 'Available', 3);

-- Sample Classes Data
INSERT INTO classes (name, description, trainer_id, schedule_time, schedule_days, duration_minutes, max_capacity, difficulty_level, category, room_location) VALUES
('Morning Yoga Flow', 'Start your day with energizing yoga poses and breathing exercises', 1, '07:00:00', 'Mon,Wed,Fri', 60, 20, 'Beginner', 'Yoga', 'Studio 1'),
('HIIT Blast', 'High-intensity interval training for maximum calorie burn', 2, '08:30:00', 'Tue,Thu', 45, 15, 'Intermediate', 'HIIT', 'Main Floor'),
('Power Lifting', 'Strength training focusing on the three main lifts', 3, '10:00:00', 'Mon,Wed,Fri', 90, 12, 'Advanced', 'Strength', 'Weight Room'),
('Cardio Dance', 'Fun dance-based cardio workout for all levels', 4, '17:30:00', 'Tue,Thu', 60, 25, 'Beginner', 'Zumba', 'Studio 2'),
('CrossFit WOD', 'Workout of the day combining strength and conditioning', 2, '18:00:00', 'Mon,Wed,Fri', 60, 15, 'Intermediate', 'CrossFit', 'CrossFit Box'),
('Evening Pilates', 'Core-strengthening and flexibility work', 1, '19:00:00', 'Tue,Thu', 45, 20, 'Beginner', 'Pilates', 'Studio 1'),
('Spin & Burn', 'High-energy spinning class with interval training', 3, '06:30:00', 'Mon,Wed,Fri', 45, 20, 'Intermediate', 'Cardio', 'Spin Room'),
('Advanced HIIT', 'Challenging high-intensity workout for experienced athletes', 4, '19:30:00', 'Mon,Wed', 45, 12, 'Advanced', 'HIIT', 'Main Floor'); 