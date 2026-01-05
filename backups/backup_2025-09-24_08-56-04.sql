DROP TABLE IF EXISTS `applications`;
CREATE TABLE `applications` (
  `app_id` int NOT NULL AUTO_INCREMENT,
  `app_job_id` int NOT NULL,
  `app_grad_usr_id` int NOT NULL,
  `app_cover_letter` text COLLATE utf8mb4_unicode_ci,
  `app_status` enum('pending','reviewed','shortlisted','rejected','hired') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `app_applied_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `app_updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`app_id`),
  UNIQUE KEY `unique_application` (`app_job_id`,`app_grad_usr_id`),
  KEY `idx_app_status` (`app_status`),
  KEY `idx_app_grad_usr_id` (`app_grad_usr_id`),
  CONSTRAINT `applications_ibfk_1` FOREIGN KEY (`app_job_id`) REFERENCES `jobs` (`job_id`) ON DELETE CASCADE,
  CONSTRAINT `applications_ibfk_2` FOREIGN KEY (`app_grad_usr_id`) REFERENCES `users` (`usr_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `employer_analytics`;
CREATE TABLE `employer_analytics` (
  `analytics_id` int NOT NULL AUTO_INCREMENT,
  `analytics_emp_usr_id` int NOT NULL,
  `analytics_type` enum('view','application','performance') COLLATE utf8mb4_unicode_ci NOT NULL,
  `analytics_grad_usr_id` int DEFAULT NULL,
  `analytics_job_id` int DEFAULT NULL,
  `analytics_date` date NOT NULL,
  `analytics_value` int DEFAULT '0',
  `analytics_metadata` text COLLATE utf8mb4_unicode_ci,
  `analytics_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`analytics_id`),
  KEY `analytics_grad_usr_id` (`analytics_grad_usr_id`),
  KEY `analytics_job_id` (`analytics_job_id`),
  KEY `idx_analytics_emp` (`analytics_emp_usr_id`,`analytics_date`),
  KEY `idx_analytics_type` (`analytics_type`),
  CONSTRAINT `employer_analytics_ibfk_1` FOREIGN KEY (`analytics_emp_usr_id`) REFERENCES `users` (`usr_id`) ON DELETE CASCADE,
  CONSTRAINT `employer_analytics_ibfk_2` FOREIGN KEY (`analytics_grad_usr_id`) REFERENCES `users` (`usr_id`) ON DELETE SET NULL,
  CONSTRAINT `employer_analytics_ibfk_3` FOREIGN KEY (`analytics_job_id`) REFERENCES `jobs` (`job_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `employer_profile_views`;
CREATE TABLE `employer_profile_views` (
  `view_id` int NOT NULL AUTO_INCREMENT,
  `view_emp_usr_id` int NOT NULL,
  `view_grad_usr_id` int NOT NULL,
  `view_viewed_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`view_id`),
  KEY `idx_view_emp` (`view_emp_usr_id`,`view_viewed_at`),
  KEY `idx_view_grad` (`view_grad_usr_id`),
  CONSTRAINT `employer_profile_views_ibfk_1` FOREIGN KEY (`view_emp_usr_id`) REFERENCES `users` (`usr_id`) ON DELETE CASCADE,
  CONSTRAINT `employer_profile_views_ibfk_2` FOREIGN KEY (`view_grad_usr_id`) REFERENCES `users` (`usr_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `employers`;
CREATE TABLE `employers` (
  `emp_id` int NOT NULL AUTO_INCREMENT,
  `emp_usr_id` int NOT NULL,
  `emp_company_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `emp_industry` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `emp_contact_person` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `emp_company_description` text COLLATE utf8mb4_unicode_ci,
  `emp_business_permit` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `emp_dti_sec` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `emp_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `emp_updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`emp_id`),
  UNIQUE KEY `emp_usr_id` (`emp_usr_id`),
  KEY `idx_emp_usr_id` (`emp_usr_id`),
  KEY `idx_company_name` (`emp_company_name`),
  CONSTRAINT `employers_ibfk_1` FOREIGN KEY (`emp_usr_id`) REFERENCES `users` (`usr_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `employers` VALUES ('1','11','ABC Corporation','Information Technology','Maria Santos','A leading IT solutions provider specializing in software development and digital transformation services for enterprises across Southeast Asia.','BP123456789','DTI2024001','2025-09-23 19:45:45','2025-09-23 19:45:45'),
('2','12','XYZ Technology Solutions','Software Development','Juan Dela Cruz','Innovative software development company focused on creating cutting-edge applications for the education and healthcare sectors.','BP987654321','SEC2024002','2025-09-23 19:45:45','2025-09-23 19:45:45');

DROP TABLE IF EXISTS `graduate_skills`;
CREATE TABLE `graduate_skills` (
  `gs_id` int NOT NULL AUTO_INCREMENT,
  `grad_usr_id` int NOT NULL,
  `skill_id` int NOT NULL,
  `skill_level` enum('beginner','intermediate','advanced','expert') COLLATE utf8mb4_unicode_ci DEFAULT 'intermediate',
  `gs_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`gs_id`),
  UNIQUE KEY `unique_graduate_skill` (`grad_usr_id`,`skill_id`),
  KEY `skill_id` (`skill_id`),
  KEY `idx_grad_skills` (`grad_usr_id`),
  CONSTRAINT `graduate_skills_ibfk_1` FOREIGN KEY (`grad_usr_id`) REFERENCES `users` (`usr_id`) ON DELETE CASCADE,
  CONSTRAINT `graduate_skills_ibfk_2` FOREIGN KEY (`skill_id`) REFERENCES `skills` (`skill_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `graduates`;
CREATE TABLE `graduates` (
  `grad_id` int NOT NULL AUTO_INCREMENT,
  `grad_usr_id` int NOT NULL,
  `grad_school_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `grad_degree` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `grad_year_graduated` year NOT NULL,
  `grad_job_preference` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `grad_summary` text COLLATE utf8mb4_unicode_ci,
  `grad_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `grad_updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`grad_id`),
  UNIQUE KEY `grad_usr_id` (`grad_usr_id`),
  KEY `idx_grad_usr_id` (`grad_usr_id`),
  CONSTRAINT `graduates_ibfk_1` FOREIGN KEY (`grad_usr_id`) REFERENCES `users` (`usr_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `graduates` VALUES ('2','6','2','BSIS','2025','Software Developer, Web Developer, Data Analyst','None','2025-09-23 14:31:49','2025-09-23 14:31:49'),
('4','10','3','BEEd','2022','Elementary School Teacher','','2025-09-23 19:36:03','2025-09-23 19:36:03'),
('5','13','5','BSIS','2025','Data Analyst','Sample','2025-09-24 12:55:58','2025-09-24 13:07:40');

DROP TABLE IF EXISTS `job_skills`;
CREATE TABLE `job_skills` (
  `js_id` int NOT NULL AUTO_INCREMENT,
  `job_id` int NOT NULL,
  `skill_id` int NOT NULL,
  `js_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`js_id`),
  UNIQUE KEY `unique_job_skill` (`job_id`,`skill_id`),
  KEY `skill_id` (`skill_id`),
  KEY `idx_job_skills` (`job_id`),
  CONSTRAINT `job_skills_ibfk_1` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`job_id`) ON DELETE CASCADE,
  CONSTRAINT `job_skills_ibfk_2` FOREIGN KEY (`skill_id`) REFERENCES `skills` (`skill_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `jobs`;
CREATE TABLE `jobs` (
  `job_id` int NOT NULL AUTO_INCREMENT,
  `job_emp_usr_id` int NOT NULL,
  `job_title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `job_description` text COLLATE utf8mb4_unicode_ci,
  `job_requirements` text COLLATE utf8mb4_unicode_ci,
  `job_location` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `job_type` enum('full-time','part-time','contract','internship') COLLATE utf8mb4_unicode_ci DEFAULT 'full-time',
  `job_salary_range` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `job_domain` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `job_skills` text COLLATE utf8mb4_unicode_ci,
  `job_status` enum('active','inactive','pending','closed','rejected') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `job_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `job_updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`job_id`),
  KEY `idx_job_emp_usr_id` (`job_emp_usr_id`),
  KEY `idx_job_status` (`job_status`),
  KEY `idx_job_type` (`job_type`),
  FULLTEXT KEY `idx_job_search` (`job_title`,`job_description`,`job_location`),
  CONSTRAINT `jobs_ibfk_1` FOREIGN KEY (`job_emp_usr_id`) REFERENCES `users` (`usr_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `jobs` VALUES ('1','11','Senior Software Developer','We are looking for an experienced Senior Software Developer to join our dynamic team. You will be responsible for developing and maintaining high-quality software solutions.','Bachelor\'s degree in Computer Science or related field, 5+ years of experience in software development, strong knowledge of JavaScript and React','Cebu City','full-time','₱50,000 - ₱80,000','Software Development','JavaScript,React,Node.js','active','2025-09-23 19:45:45','2025-09-23 19:45:45'),
('2','11','IT Project Manager','Manage software development projects from conception to deployment, ensuring timely delivery and quality standards.','PMP certification preferred, 3+ years project management experience, excellent communication skills','Cebu City (Hybrid)','full-time','₱60,000 - ₱90,000','Project Management','Project Management,Agile,Scrum','active','2025-09-23 19:45:45','2025-09-23 19:45:45'),
('3','12','Frontend Developer','Join our frontend team to create beautiful and responsive web applications using modern technologies.','2+ years experience in frontend development, proficiency in React/Vue.js, strong CSS skills','Mandaue City','full-time','₱35,000 - ₱55,000','Web Development','JavaScript,React,HTML,CSS','active','2025-09-23 19:45:45','2025-09-23 19:45:45'),
('4','12','Data Analyst','Analyze complex datasets to provide insights that drive business decisions and improve operational efficiency.','Experience with SQL and Python, knowledge of data visualization tools, strong analytical thinking','Cebu City (Remote)','full-time','₱40,000 - ₱60,000','Data Science','Python,SQL,Data Analysis','active','2025-09-23 19:45:45','2025-09-23 19:45:45');

DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `notif_id` int NOT NULL AUTO_INCREMENT,
  `notif_usr_id` int NOT NULL,
  `notif_message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `notif_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notif_is_read` tinyint(1) DEFAULT '0',
  `notif_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`notif_id`),
  KEY `idx_notif_usr_id` (`notif_usr_id`,`notif_is_read`),
  KEY `idx_notif_created` (`notif_created_at`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`notif_usr_id`) REFERENCES `users` (`usr_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `notifications` VALUES ('2','13','Your account has been approved by administrator','user_approval','0','2025-09-24 12:56:12'),
('3','15','Your account has been approved by administrator','user_approval','0','2025-09-24 14:16:28');

DROP TABLE IF EXISTS `otp_tokens`;
CREATE TABLE `otp_tokens` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `otp_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `attempts` int DEFAULT '0',
  `created_at` datetime NOT NULL,
  `purpose` enum('signup','reset') COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_expires_at` (`expires_at`),
  CONSTRAINT `otp_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`usr_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `otp_tokens` VALUES ('5','6','$2y$10$hl0yPkaiqyISUGBCkty2hOfGXgP/YYWcO9tqiit7vMzZddG6Njbvy','2025-09-23 08:45:36','0','2025-09-23 14:30:36','signup'),
('9','10','$2y$10$Qlzc8Wv9gb5DT.jnkfJZD.oWLvFu7VWN1iYeUQ1mcPpM2XbG6bxPW','2025-09-23 13:46:03','0','2025-09-23 19:31:03','signup'),
('10','13','$2y$10$AyrU8QgpyC0N6EDa9eDyLehZYTc3nvll60K1il4oFwWcYc98XR7ia','2025-09-24 07:08:45','0','2025-09-24 12:53:45','signup'),
('11','13','$2y$10$Lf2R/E8ibljhiJuolt/Hc.rxXP6pV0VbOb.w7d3WsrbHjqDTJlMze','2025-09-24 07:19:27','0','2025-09-24 13:04:27','reset'),
('13','15','$2y$10$zqDF5U3IfkeXOlHENNUEI.2Ag1O0yGoKi0ZZQjodqRRwy3slPD60e','2025-09-24 08:28:18','0','2025-09-24 14:13:18','signup');

DROP TABLE IF EXISTS `portfolio_items`;
CREATE TABLE `portfolio_items` (
  `port_id` int NOT NULL AUTO_INCREMENT,
  `port_usr_id` int NOT NULL,
  `port_item_type` enum('resume','project','certificate','skill') COLLATE utf8mb4_unicode_ci NOT NULL,
  `port_item_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `port_item_description` text COLLATE utf8mb4_unicode_ci,
  `port_item_file` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `port_item_date` date DEFAULT NULL,
  `port_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `port_updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`port_id`),
  KEY `idx_port_usr_id` (`port_usr_id`),
  KEY `idx_port_type` (`port_item_type`),
  CONSTRAINT `portfolio_items_ibfk_1` FOREIGN KEY (`port_usr_id`) REFERENCES `users` (`usr_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `saved_jobs`;
CREATE TABLE `saved_jobs` (
  `saved_id` int NOT NULL AUTO_INCREMENT,
  `grad_usr_id` int NOT NULL,
  `job_id` int NOT NULL,
  `saved_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`saved_id`),
  UNIQUE KEY `unique_saved_job` (`grad_usr_id`,`job_id`),
  KEY `job_id` (`job_id`),
  KEY `idx_saved_grad` (`grad_usr_id`),
  CONSTRAINT `saved_jobs_ibfk_1` FOREIGN KEY (`grad_usr_id`) REFERENCES `users` (`usr_id`) ON DELETE CASCADE,
  CONSTRAINT `saved_jobs_ibfk_2` FOREIGN KEY (`job_id`) REFERENCES `jobs` (`job_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `skills`;
CREATE TABLE `skills` (
  `skill_id` int NOT NULL AUTO_INCREMENT,
  `skill_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `skill_category` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `skill_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`skill_id`),
  UNIQUE KEY `skill_name` (`skill_name`),
  KEY `idx_skill_category` (`skill_category`)
) ENGINE=InnoDB AUTO_INCREMENT=171 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `skills` VALUES ('1','JavaScript','Programming','2025-09-23 19:45:45'),
('2','Python','Programming','2025-09-23 19:45:45'),
('3','React','Web Development','2025-09-23 19:45:45'),
('4','Node.js','Backend Development','2025-09-23 19:45:45'),
('5','MySQL','Database','2025-09-23 19:45:45'),
('6','Project Management','Business','2025-09-23 19:45:45'),
('7','UI/UX Design','Design','2025-09-23 19:45:45'),
('8','Cloud Computing','IT Infrastructure','2025-09-23 19:45:45'),
('9','Data Analysis','Analytics','2025-09-23 19:45:45'),
('10','Mobile Development','Programming','2025-09-23 19:45:45'),
('11','Java','Programming Languages','2025-09-23 19:53:58'),
('12','C++','Programming Languages','2025-09-23 19:53:58'),
('13','C#','Programming Languages','2025-09-23 19:53:58'),
('14','PHP','Programming Languages','2025-09-23 19:53:58'),
('15','TypeScript','Programming Languages','2025-09-23 19:53:58'),
('16','Ruby','Programming Languages','2025-09-23 19:53:58'),
('17','Go','Programming Languages','2025-09-23 19:53:58'),
('18','Swift','Programming Languages','2025-09-23 19:53:58'),
('19','Kotlin','Programming Languages','2025-09-23 19:53:58'),
('20','Rust','Programming Languages','2025-09-23 19:53:58'),
('21','Scala','Programming Languages','2025-09-23 19:53:58'),
('22','Perl','Programming Languages','2025-09-23 19:53:58'),
('23','R','Programming Languages','2025-09-23 19:53:58'),
('24','MATLAB','Programming Languages','2025-09-23 19:53:58'),
('25','HTML5','Web Development','2025-09-23 19:53:58'),
('26','CSS3','Web Development','2025-09-23 19:53:58'),
('27','Angular','Web Development','2025-09-23 19:53:58'),
('28','Vue.js','Web Development','2025-09-23 19:53:58'),
('29','Express.js','Web Development','2025-09-23 19:53:58'),
('30','Django','Web Development','2025-09-23 19:53:58'),
('31','Flask','Web Development','2025-09-23 19:53:58'),
('32','Spring Boot','Web Development','2025-09-23 19:53:58'),
('33','Laravel','Web Development','2025-09-23 19:53:58'),
('34','Ruby on Rails','Web Development','2025-09-23 19:53:58'),
('35','ASP.NET','Web Development','2025-09-23 19:53:58'),
('36','jQuery','Web Development','2025-09-23 19:53:58'),
('37','Bootstrap','Web Development','2025-09-23 19:53:58'),
('38','Tailwind CSS','Web Development','2025-09-23 19:53:58'),
('39','SASS/SCSS','Web Development','2025-09-23 19:53:58'),
('40','Webpack','Web Development','2025-09-23 19:53:58'),
('41','RESTful APIs','Web Development','2025-09-23 19:53:58'),
('42','GraphQL','Web Development','2025-09-23 19:53:58'),
('43','React Native','Mobile Development','2025-09-23 19:53:58'),
('44','Flutter','Mobile Development','2025-09-23 19:53:58'),
('45','Android Development','Mobile Development','2025-09-23 19:53:58'),
('46','iOS Development','Mobile Development','2025-09-23 19:53:58'),
('47','Xamarin','Mobile Development','2025-09-23 19:53:58'),
('48','Ionic','Mobile Development','2025-09-23 19:53:58'),
('49','PostgreSQL','Database Technologies','2025-09-23 19:53:58'),
('50','MongoDB','Database Technologies','2025-09-23 19:53:58'),
('51','SQLite','Database Technologies','2025-09-23 19:53:58'),
('52','Oracle','Database Technologies','2025-09-23 19:53:58'),
('53','SQL Server','Database Technologies','2025-09-23 19:53:58'),
('54','Redis','Database Technologies','2025-09-23 19:53:58'),
('55','Firebase','Database Technologies','2025-09-23 19:53:58'),
('56','Cassandra','Database Technologies','2025-09-23 19:53:58'),
('57','Elasticsearch','Database Technologies','2025-09-23 19:53:58'),
('58','AWS','Cloud & DevOps','2025-09-23 19:53:58'),
('59','Azure','Cloud & DevOps','2025-09-23 19:53:58'),
('60','Google Cloud','Cloud & DevOps','2025-09-23 19:53:58'),
('61','Docker','Cloud & DevOps','2025-09-23 19:53:58'),
('62','Kubernetes','Cloud & DevOps','2025-09-23 19:53:58'),
('63','Jenkins','Cloud & DevOps','2025-09-23 19:53:58'),
('64','GitLab CI/CD','Cloud & DevOps','2025-09-23 19:53:58'),
('65','Terraform','Cloud & DevOps','2025-09-23 19:53:58'),
('66','Ansible','Cloud & DevOps','2025-09-23 19:53:58'),
('67','Linux Administration','Cloud & DevOps','2025-09-23 19:53:58'),
('68','Shell Scripting','Cloud & DevOps','2025-09-23 19:53:58'),
('69','Machine Learning','Data Science & Analytics','2025-09-23 19:53:58'),
('70','Deep Learning','Data Science & Analytics','2025-09-23 19:53:58'),
('71','Data Visualization','Data Science & Analytics','2025-09-23 19:53:58'),
('72','Statistical Analysis','Data Science & Analytics','2025-09-23 19:53:58'),
('73','Pandas','Data Science & Analytics','2025-09-23 19:53:58'),
('74','NumPy','Data Science & Analytics','2025-09-23 19:53:58'),
('75','TensorFlow','Data Science & Analytics','2025-09-23 19:53:58'),
('76','PyTorch','Data Science & Analytics','2025-09-23 19:53:58'),
('77','Tableau','Data Science & Analytics','2025-09-23 19:53:58'),
('78','Power BI','Data Science & Analytics','2025-09-23 19:53:58'),
('79','Object-Oriented Programming','Software Engineering','2025-09-23 19:53:58'),
('80','Functional Programming','Software Engineering','2025-09-23 19:53:58'),
('81','Design Patterns','Software Engineering','2025-09-23 19:53:58'),
('82','Software Architecture','Software Engineering','2025-09-23 19:53:58'),
('83','Microservices','Software Engineering','2025-09-23 19:53:58'),
('84','API Design','Software Engineering','2025-09-23 19:53:58'),
('85','Test-Driven Development','Software Engineering','2025-09-23 19:53:58'),
('86','Agile Methodology','Software Engineering','2025-09-23 19:53:58'),
('87','Scrum','Software Engineering','2025-09-23 19:53:58'),
('88','Kanban','Software Engineering','2025-09-23 19:53:58'),
('89','Code Review','Software Engineering','2025-09-23 19:53:58'),
('90','Version Control (Git)','Software Engineering','2025-09-23 19:53:58'),
('91','User Interface Design','UI/UX Design','2025-09-23 19:53:58'),
('92','User Experience Design','UI/UX Design','2025-09-23 19:53:58'),
('93','Wireframing','UI/UX Design','2025-09-23 19:53:58'),
('94','Prototyping','UI/UX Design','2025-09-23 19:53:58'),
('95','Figma','UI/UX Design','2025-09-23 19:53:58'),
('96','Adobe XD','UI/UX Design','2025-09-23 19:53:58'),
('97','Sketch','UI/UX Design','2025-09-23 19:53:58'),
('98','Adobe Creative Suite','UI/UX Design','2025-09-23 19:53:58'),
('99','Responsive Design','UI/UX Design','2025-09-23 19:53:58'),
('100','Interaction Design','UI/UX Design','2025-09-23 19:53:58'),
('101','Network Security','Cybersecurity','2025-09-23 19:53:58'),
('102','Ethical Hacking','Cybersecurity','2025-09-23 19:53:58'),
('103','Penetration Testing','Cybersecurity','2025-09-23 19:53:58'),
('104','Cryptography','Cybersecurity','2025-09-23 19:53:58'),
('105','Security Auditing','Cybersecurity','2025-09-23 19:53:58'),
('106','Incident Response','Cybersecurity','2025-09-23 19:53:58'),
('107','Security Compliance','Cybersecurity','2025-09-23 19:53:58'),
('108','Product Management','Business & Management','2025-09-23 19:53:58'),
('109','Business Analysis','Business & Management','2025-09-23 19:53:58'),
('110','Strategic Planning','Business & Management','2025-09-23 19:53:58'),
('111','Team Leadership','Business & Management','2025-09-23 19:53:58'),
('112','Stakeholder Management','Business & Management','2025-09-23 19:53:58'),
('113','Risk Management','Business & Management','2025-09-23 19:53:58'),
('114','Budget Management','Business & Management','2025-09-23 19:53:58'),
('115','SEO','Digital Marketing','2025-09-23 19:53:58'),
('116','SEM','Digital Marketing','2025-09-23 19:53:58'),
('117','Social Media Marketing','Digital Marketing','2025-09-23 19:53:58'),
('118','Content Marketing','Digital Marketing','2025-09-23 19:53:58'),
('119','Email Marketing','Digital Marketing','2025-09-23 19:53:58'),
('120','Google Analytics','Digital Marketing','2025-09-23 19:53:58'),
('121','PPC Advertising','Digital Marketing','2025-09-23 19:53:58'),
('122','Marketing Automation','Digital Marketing','2025-09-23 19:53:58'),
('123','Communication Skills','Soft Skills','2025-09-23 19:53:58'),
('124','Problem Solving','Soft Skills','2025-09-23 19:53:58'),
('125','Critical Thinking','Soft Skills','2025-09-23 19:53:58'),
('126','Teamwork','Soft Skills','2025-09-23 19:53:58'),
('127','Leadership','Soft Skills','2025-09-23 19:53:58'),
('128','Time Management','Soft Skills','2025-09-23 19:53:58'),
('129','Adaptability','Soft Skills','2025-09-23 19:53:58'),
('130','Creativity','Soft Skills','2025-09-23 19:53:58'),
('131','Emotional Intelligence','Soft Skills','2025-09-23 19:53:58'),
('132','Negotiation','Soft Skills','2025-09-23 19:53:58'),
('133','Microsoft Office','Office & Productivity','2025-09-23 19:53:58'),
('134','Google Workspace','Office & Productivity','2025-09-23 19:53:58'),
('135','Technical Writing','Office & Productivity','2025-09-23 19:53:58'),
('136','Presentation Skills','Office & Productivity','2025-09-23 19:53:58'),
('137','Data Entry','Office & Productivity','2025-09-23 19:53:58'),
('138','Accounting','Industry Specific','2025-09-23 19:53:58'),
('139','Finance','Industry Specific','2025-09-23 19:53:58'),
('140','Healthcare IT','Industry Specific','2025-09-23 19:53:58'),
('141','E-commerce','Industry Specific','2025-09-23 19:53:58'),
('142','Education Technology','Industry Specific','2025-09-23 19:53:58'),
('143','Game Development','Industry Specific','2025-09-23 19:53:58'),
('144','IoT Development','Industry Specific','2025-09-23 19:53:58'),
('145','Blockchain','Industry Specific','2025-09-23 19:53:58'),
('146','AR/VR Development','Industry Specific','2025-09-23 19:53:58'),
('147','Manual Testing','Quality Assurance','2025-09-23 19:53:58'),
('148','Automated Testing','Quality Assurance','2025-09-23 19:53:58'),
('149','Selenium','Quality Assurance','2025-09-23 19:53:58'),
('150','JUnit','Quality Assurance','2025-09-23 19:53:58'),
('151','Test Automation','Quality Assurance','2025-09-23 19:53:58'),
('152','Quality Assurance','Quality Assurance','2025-09-23 19:53:58'),
('153','Software Testing','Quality Assurance','2025-09-23 19:53:58'),
('154','TCP/IP','Networking','2025-09-23 19:53:58'),
('155','Network Administration','Networking','2025-09-23 19:53:58'),
('156','Cisco Networking','Networking','2025-09-23 19:53:58'),
('157','Wireless Networks','Networking','2025-09-23 19:53:58'),
('158','Embedded Systems','Other Technical Skills','2025-09-23 19:53:58'),
('159','Robotics','Other Technical Skills','2025-09-23 19:53:58'),
('160','3D Modeling','Other Technical Skills','2025-09-23 19:53:58'),
('161','CAD Design','Other Technical Skills','2025-09-23 19:53:58'),
('162','Technical Support','Other Technical Skills','2025-09-23 19:53:58');

DROP TABLE IF EXISTS `staff`;
CREATE TABLE `staff` (
  `staff_id` int NOT NULL AUTO_INCREMENT,
  `staff_usr_id` int NOT NULL,
  `staff_department` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `staff_position` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `staff_employee_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `staff_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `staff_updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`staff_id`),
  UNIQUE KEY `staff_usr_id` (`staff_usr_id`),
  UNIQUE KEY `staff_employee_id` (`staff_employee_id`),
  KEY `idx_staff_usr_id` (`staff_usr_id`),
  CONSTRAINT `staff_ibfk_1` FOREIGN KEY (`staff_usr_id`) REFERENCES `users` (`usr_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `staff` VALUES ('1','15','PESO','Guard','09090','2025-09-24 14:16:12','2025-09-24 14:16:12');

DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE `system_settings` (
  `setting_id` int NOT NULL AUTO_INCREMENT,
  `setting_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_id`),
  UNIQUE KEY `setting_name` (`setting_name`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `system_settings` VALUES ('1','auto_approve_users','0','2025-09-23 13:10:57','2025-09-23 13:10:57'),
('2','auto_approve_jobs','0','2025-09-23 13:10:57','2025-09-23 13:10:57'),
('3','enable_staff_accounts','1','2025-09-23 13:10:57','2025-09-24 14:12:35'),
('4','maintenance_mode','0','2025-09-23 13:10:57','2025-09-23 13:10:57'),
('5','results_per_page','25','2025-09-23 13:10:57','2025-09-23 13:10:57');

DROP TABLE IF EXISTS `user_activities`;
CREATE TABLE `user_activities` (
  `activity_id` int NOT NULL AUTO_INCREMENT,
  `activity_usr_id` int NOT NULL,
  `activity_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `activity_details` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `activity_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`activity_id`),
  KEY `idx_activity_usr` (`activity_usr_id`,`activity_date`),
  KEY `idx_activity_type` (`activity_type`),
  CONSTRAINT `user_activities_ibfk_1` FOREIGN KEY (`activity_usr_id`) REFERENCES `users` (`usr_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `usr_id` int NOT NULL AUTO_INCREMENT,
  `usr_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `usr_email` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `usr_phone` varchar(15) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `usr_password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `usr_profile_photo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `usr_role` enum('admin','graduate','employer','staff') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `usr_gender` enum('Male','Female','Other') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `usr_birthdate` date DEFAULT NULL,
  `usr_address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `usr_is_approved` tinyint(1) DEFAULT '0',
  `usr_account_status` enum('active','pending','suspended','inactive') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `usr_failed_login_attempts` int DEFAULT '0',
  `usr_last_login` timestamp NULL DEFAULT NULL,
  `usr_reset_token` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `usr_reset_expiry` timestamp NULL DEFAULT NULL,
  `usr_created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `usr_updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`usr_id`),
  UNIQUE KEY `usr_email` (`usr_email`),
  UNIQUE KEY `usr_phone` (`usr_phone`),
  KEY `idx_usr_email` (`usr_email`),
  KEY `idx_usr_role` (`usr_role`),
  KEY `idx_usr_status` (`usr_account_status`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` VALUES ('1','Admin User','admin@ctu.edu.ph',NULL,'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',NULL,'admin','Male','1980-01-01','Cebu City','1','active','0','2025-09-24 14:55:04',NULL,NULL,'2025-09-23 12:34:19','2025-09-24 14:55:04'),
('6','Kinesu Daluag','daluagkinneth2004@gmail.com','09942707522','$2y$10$GZ1A8ZV07ENWIx3hlGh6vufI.rhZ2c40EMUzmqhrNHkuZJY5eg0YC',NULL,'graduate','Male','2004-06-16',NULL,'1','active','0','2025-09-23 19:45:12',NULL,NULL,'2025-09-23 08:30:36','2025-09-23 19:45:12'),
('10','Kuroko Tetsuya','kinneth.daluag@gws.ctu.edu.ph','+63 9942707522','$2y$10$NG0E2dT2F/IQWJA5um4WWOkZp0a9OXlVDd1LP0Qi4.8ruK3GIU0fq',NULL,'graduate','Male','2004-06-16','Lapu-Lapu City, Cebu','0','pending','0',NULL,NULL,NULL,'2025-09-23 13:31:02','2025-09-23 19:36:03'),
('11','Maria Santos','maria.santos@abccorp.com','+639171234567','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',NULL,'employer','Female','1985-03-15','Cebu Business Park, Cebu City','1','active','0',NULL,NULL,NULL,'2025-09-23 19:45:45','2025-09-23 19:45:45'),
('12','Juan Dela Cruz','juan.delacruz@xyztech.com','+639281234567','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',NULL,'employer','Male','1978-07-22','Mandaue City, Cebu','1','active','0',NULL,NULL,NULL,'2025-09-23 19:45:45','2025-09-23 19:45:45'),
('13','Kinneth Daluag','kinnethdaluag2004@gmail.com','+63 9292958823','$2y$10$QMSg/3tE2anfnvoCab2xPe7kvliaOFNJYv0mRYbtwdyXqEUCidhey','uploads/profile_photos/profile_13_1758690460.jpg','graduate','Male','2004-12-06','Cebu City','1','active','0','2025-09-24 14:49:46',NULL,NULL,'2025-09-24 06:53:45','2025-09-24 14:49:46'),
('15','Ana Mae Yamyamin','grade9irf@gmail.com','+63 9942707521','$2y$10$f3RveyfH1RWQCLoTMv5mqunhB500uTGpw.0jafS9s0C4KXga8Butu',NULL,'staff','Female','2000-10-06',NULL,'1','active','0','2025-09-24 14:16:49',NULL,NULL,'2025-09-24 08:13:17','2025-09-24 14:16:49');

