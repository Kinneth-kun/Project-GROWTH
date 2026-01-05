DROP TABLE IF EXISTS `applications`;
CREATE TABLE `applications` (
  `app_id` int(11) NOT NULL AUTO_INCREMENT,
  `app_job_id` int(11) NOT NULL,
  `app_grad_usr_id` int(11) NOT NULL,
  `app_cover_letter` text DEFAULT NULL,
  `app_status` enum('pending','reviewed','shortlisted','rejected','hired') DEFAULT 'pending',
  `app_applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`app_id`),
  KEY `app_job_id` (`app_job_id`),
  KEY `app_grad_usr_id` (`app_grad_usr_id`),
  CONSTRAINT `applications_ibfk_1` FOREIGN KEY (`app_job_id`) REFERENCES `jobs` (`job_id`) ON DELETE CASCADE,
  CONSTRAINT `applications_ibfk_2` FOREIGN KEY (`app_grad_usr_id`) REFERENCES `users` (`usr_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `applications` VALUES ('1','1','2','I am excited to apply for the Junior Web Developer position. As a recent IT graduate with strong foundational skills in web technologies, I believe I would be a great fit for your team.','pending','2025-09-14 11:35:28');

DROP TABLE IF EXISTS `employer_analytics`;
CREATE TABLE `employer_analytics` (
  `analytics_id` int(11) NOT NULL AUTO_INCREMENT,
  `analytics_emp_usr_id` int(11) NOT NULL,
  `analytics_type` enum('view','application','performance') NOT NULL,
  `analytics_grad_usr_id` int(11) DEFAULT NULL,
  `analytics_job_id` int(11) DEFAULT NULL,
  `analytics_date` date NOT NULL,
  `analytics_value` int(11) DEFAULT 0,
  `analytics_metadata` text DEFAULT NULL,
  `analytics_created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`analytics_id`),
  KEY `analytics_emp_usr_id` (`analytics_emp_usr_id`),
  KEY `analytics_grad_usr_id` (`analytics_grad_usr_id`),
  KEY `analytics_job_id` (`analytics_job_id`),
  CONSTRAINT `employer_analytics_ibfk_1` FOREIGN KEY (`analytics_emp_usr_id`) REFERENCES `users` (`usr_id`) ON DELETE CASCADE,
  CONSTRAINT `employer_analytics_ibfk_2` FOREIGN KEY (`analytics_grad_usr_id`) REFERENCES `users` (`usr_id`) ON DELETE SET NULL,
  CONSTRAINT `employer_analytics_ibfk_3` FOREIGN KEY (`analytics_job_id`) REFERENCES `jobs` (`job_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `employer_profile_views`;
CREATE TABLE `employer_profile_views` (
  `view_id` int(11) NOT NULL AUTO_INCREMENT,
  `view_emp_usr_id` int(11) NOT NULL,
  `view_grad_usr_id` int(11) NOT NULL,
  `view_viewed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`view_id`),
  KEY `view_emp_usr_id` (`view_emp_usr_id`),
  KEY `view_grad_usr_id` (`view_grad_usr_id`),
  CONSTRAINT `employer_profile_views_ibfk_1` FOREIGN KEY (`view_emp_usr_id`) REFERENCES `users` (`usr_id`) ON DELETE CASCADE,
  CONSTRAINT `employer_profile_views_ibfk_2` FOREIGN KEY (`view_grad_usr_id`) REFERENCES `users` (`usr_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `employers`;
CREATE TABLE `employers` (
  `emp_id` int(11) NOT NULL AUTO_INCREMENT,
  `emp_usr_id` int(11) NOT NULL,
  `emp_company_name` varchar(255) NOT NULL,
  `emp_industry` varchar(255) NOT NULL,
  `emp_contact_person` varchar(100) NOT NULL,
  `emp_company_description` text DEFAULT NULL,
  `emp_business_permit` varchar(255) DEFAULT NULL,
  `emp_dti_sec` varchar(255) DEFAULT NULL,
  `emp_created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `emp_updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`emp_id`),
  KEY `emp_usr_id` (`emp_usr_id`),
  CONSTRAINT `employers_ibfk_1` FOREIGN KEY (`emp_usr_id`) REFERENCES `users` (`usr_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `employers` VALUES ('1','3','TechSolutions Inc.','Information Technology','Maria Santos','Leading IT solutions provider specializing in software development and digital transformation services.','business_permit_12345.pdf','dti_certificate_67890.pdf','2025-09-14 11:35:28','2025-09-14 11:35:28');

DROP TABLE IF EXISTS `graduate_skills`;
CREATE TABLE `graduate_skills` (
  `gs_id` int(11) NOT NULL AUTO_INCREMENT,
  `grad_usr_id` int(11) NOT NULL,
  `skill_id` int(11) NOT NULL,
  `skill_level` enum('beginner','intermediate','advanced','expert') DEFAULT 'intermediate',
  `gs_created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`gs_id`),
  KEY `grad_usr_id` (`grad_usr_id`),
  KEY `skill_id` (`skill_id`),
  CONSTRAINT `graduate_skills_ibfk_1` FOREIGN KEY (`grad_usr_id`) REFERENCES `users` (`usr_id`) ON DELETE CASCADE,
  CONSTRAINT `graduate_skills_ibfk_2` FOREIGN KEY (`skill_id`) REFERENCES `skills` (`skill_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `graduates`;
CREATE TABLE `graduates` (
  `grad_id` int(11) NOT NULL AUTO_INCREMENT,
  `grad_usr_id` int(11) NOT NULL,
  `grad_school_id` varchar(255) NOT NULL,
  `grad_degree` varchar(255) NOT NULL,
  `grad_year_graduated` year(4) NOT NULL,
  `grad_job_preference` varchar(255) NOT NULL,
  `grad_summary` text DEFAULT NULL,
  `grad_created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `grad_updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`grad_id`),
  KEY `grad_usr_id` (`grad_usr_id`),
  CONSTRAINT `graduates_ibfk_1` FOREIGN KEY (`grad_usr_id`) REFERENCES `users` (`usr_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `graduates` VALUES ('1','2','2020-12345','Bachelor of Science in Information Technology','2022','Software Developer, Web Developer','Recent IT graduate with strong programming skills in Java, Python, and JavaScript. Passionate about web development and software engineering.','2025-09-14 11:35:28','2025-09-14 11:35:28');

DROP TABLE IF EXISTS `job_skills`;
CREATE TABLE `job_skills` (
  `js_id` int(11) NOT NULL AUTO_INCREMENT,
  `job_id` int(11) NOT NULL,
  `skill_id` int(11) NOT NULL,
  `js_created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`js_id`),
  KEY `job_id` (`job_id`),
  KEY `skill_id` (`skill_id`),
  CONSTRAINT `job_skills_ibfk_1` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`job_id`) ON DELETE CASCADE,
  CONSTRAINT `job_skills_ibfk_2` FOREIGN KEY (`skill_id`) REFERENCES `skills` (`skill_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `job_skills` VALUES ('1','1','3','2025-09-14 11:35:28'),
('2','1','4','2025-09-14 11:35:28'),
('3','1','5','2025-09-14 11:35:28'),
('4','1','6','2025-09-14 11:35:28');

DROP TABLE IF EXISTS `jobs`;
CREATE TABLE `jobs` (
  `job_id` int(11) NOT NULL AUTO_INCREMENT,
  `job_emp_usr_id` int(11) NOT NULL,
  `job_title` varchar(255) NOT NULL,
  `job_description` text DEFAULT NULL,
  `job_requirements` text DEFAULT NULL,
  `job_location` varchar(100) DEFAULT NULL,
  `job_type` enum('full-time','part-time','contract','internship') DEFAULT 'full-time',
  `job_salary_range` varchar(100) DEFAULT NULL,
  `job_domain` varchar(255) DEFAULT NULL,
  `job_skills` text DEFAULT NULL,
  `job_status` enum('active','inactive','pending','closed') DEFAULT 'pending',
  `job_created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `job_updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`job_id`),
  KEY `job_emp_usr_id` (`job_emp_usr_id`),
  CONSTRAINT `jobs_ibfk_1` FOREIGN KEY (`job_emp_usr_id`) REFERENCES `users` (`usr_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `jobs` VALUES ('1','3','Junior Web Developer','We are looking for a passionate Junior Web Developer to design, develop and maintain web applications.','Bachelor\'s degree in IT or related field, knowledge of HTML, CSS, JavaScript, and basic understanding of backend technologies.','Cebu City','full-time','₱20,000 - ₱25,000','Web Development','HTML, CSS, JavaScript, React','active','2025-09-14 11:35:28','2025-09-14 11:35:28');

DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `notif_id` int(11) NOT NULL AUTO_INCREMENT,
  `notif_usr_id` int(11) NOT NULL,
  `notif_message` text NOT NULL,
  `notif_type` varchar(50) NOT NULL,
  `notif_is_read` tinyint(1) DEFAULT 0,
  `notif_created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`notif_id`),
  KEY `notif_usr_id` (`notif_usr_id`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`notif_usr_id`) REFERENCES `users` (`usr_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `notifications` VALUES ('1','2','Welcome to G.R.O.W.T.H. System! Complete your profile to increase your visibility to employers.','welcome','0','2025-09-14 11:35:28'),
('2','3','Your job posting for Junior Web Developer is now active and visible to graduates.','job_posted','0','2025-09-14 11:35:28');

DROP TABLE IF EXISTS `portfolio_items`;
CREATE TABLE `portfolio_items` (
  `port_id` int(11) NOT NULL AUTO_INCREMENT,
  `port_usr_id` int(11) NOT NULL,
  `port_item_type` enum('resume','project','certificate','skill') NOT NULL,
  `port_item_title` varchar(255) DEFAULT NULL,
  `port_item_description` text DEFAULT NULL,
  `port_item_file` varchar(255) DEFAULT NULL,
  `port_item_date` date DEFAULT NULL,
  `port_created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `port_updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`port_id`),
  KEY `port_usr_id` (`port_usr_id`),
  CONSTRAINT `portfolio_items_ibfk_1` FOREIGN KEY (`port_usr_id`) REFERENCES `users` (`usr_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `portfolio_items` VALUES ('1','2','resume','My Resume','Detailed resume with education, skills, and projects','juan_delacruz_resume.pdf','2025-09-14','2025-09-14 11:35:28','2025-09-14 11:35:28');

DROP TABLE IF EXISTS `saved_jobs`;
CREATE TABLE `saved_jobs` (
  `saved_id` int(11) NOT NULL AUTO_INCREMENT,
  `grad_usr_id` int(11) NOT NULL,
  `job_id` int(11) NOT NULL,
  `saved_date` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`saved_id`),
  KEY `grad_usr_id` (`grad_usr_id`),
  KEY `job_id` (`job_id`),
  CONSTRAINT `saved_jobs_ibfk_1` FOREIGN KEY (`grad_usr_id`) REFERENCES `users` (`usr_id`) ON DELETE CASCADE,
  CONSTRAINT `saved_jobs_ibfk_2` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`job_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `skills`;
CREATE TABLE `skills` (
  `skill_id` int(11) NOT NULL AUTO_INCREMENT,
  `skill_name` varchar(100) NOT NULL,
  `skill_category` varchar(100) DEFAULT NULL,
  `skill_created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`skill_id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `skills` VALUES ('1','Java','Programming','2025-09-14 11:35:28'),
('2','Python','Programming','2025-09-14 11:35:28'),
('3','JavaScript','Programming','2025-09-14 11:35:28'),
('4','HTML','Web Development','2025-09-14 11:35:28'),
('5','CSS','Web Development','2025-09-14 11:35:28'),
('6','React','Web Development','2025-09-14 11:35:28'),
('7','Node.js','Web Development','2025-09-14 11:35:28'),
('8','PHP','Programming','2025-09-14 11:35:28'),
('9','MySQL','Database','2025-09-14 11:35:28'),
('10','MongoDB','Database','2025-09-14 11:35:28'),
('11','Git','Tools','2025-09-14 11:35:28'),
('12','AWS','Cloud Computing','2025-09-14 11:35:28'),
('13','Docker','DevOps','2025-09-14 11:35:28'),
('14','Agile','Methodology','2025-09-14 11:35:28'),
('15','Project Management','Management','2025-09-14 11:35:28');

DROP TABLE IF EXISTS `user_activities`;
CREATE TABLE `user_activities` (
  `activity_id` int(11) NOT NULL AUTO_INCREMENT,
  `activity_usr_id` int(11) NOT NULL,
  `activity_type` varchar(50) NOT NULL,
  `activity_details` text NOT NULL,
  `activity_date` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`activity_id`),
  KEY `activity_usr_id` (`activity_usr_id`),
  CONSTRAINT `user_activities_ibfk_1` FOREIGN KEY (`activity_usr_id`) REFERENCES `users` (`usr_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `user_activities` VALUES ('1','2','login','User logged in successfully','2025-09-14 11:35:28');

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `usr_id` int(11) NOT NULL AUTO_INCREMENT,
  `usr_name` varchar(100) NOT NULL,
  `usr_email` varchar(100) DEFAULT NULL,
  `usr_phone` varchar(15) DEFAULT NULL,
  `usr_password` varchar(255) NOT NULL,
  `usr_profile_photo` varchar(255) DEFAULT NULL,
  `usr_role` enum('admin','graduate','employer','staff') NOT NULL DEFAULT 'graduate',
  `usr_gender` enum('Male','Female') NOT NULL,
  `usr_birthdate` date NOT NULL,
  `usr_is_approved` tinyint(1) DEFAULT 0,
  `usr_account_status` enum('active','pending','suspended','inactive') DEFAULT 'pending',
  `usr_failed_login_attempts` int(11) DEFAULT 0,
  `usr_last_login` timestamp NULL DEFAULT NULL,
  `usr_reset_token` varchar(255) DEFAULT NULL,
  `usr_reset_expiry` timestamp NULL DEFAULT NULL,
  `usr_created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `usr_updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`usr_id`),
  UNIQUE KEY `usr_email` (`usr_email`),
  UNIQUE KEY `usr_phone` (`usr_phone`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `users` VALUES ('1','Admin User','admin@ctu.edu.ph',NULL,'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',NULL,'admin','Male','1980-01-01','1','active','0','2025-09-14 11:38:58',NULL,NULL,'2025-09-14 11:28:27','2025-09-14 11:38:58'),
('2','Juan Dela Cruz','juan.delacruz@gmail.com','09171234567','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','graduate_profile.jpg','graduate','Male','1998-05-15','1','active','0',NULL,NULL,NULL,'2025-09-14 11:35:28','2025-09-14 11:35:28'),
('3','Maria Santos','maria.santos@gmail.com','09179876543','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','employer_profile.jpg','employer','Female','1985-08-22','1','active','0',NULL,NULL,NULL,'2025-09-14 11:35:28','2025-09-14 11:35:28'),
('4','Pedro Reyes','pedro.reyes@gmail.com','09175551234','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','staff_profile.jpg','staff','Male','1990-11-10','1','active','0',NULL,NULL,NULL,'2025-09-14 11:35:28','2025-09-14 11:35:28');

