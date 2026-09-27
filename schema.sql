DROP DATABASE IF EXISTS rics_enrollment_db;
CREATE DATABASE rics_enrollment_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE rics_enrollment_db;


CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('student', 'registrar', 'cashier', 'admin') NOT NULL DEFAULT 'student',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE grade_levels (
    grade_level_id INT AUTO_INCREMENT PRIMARY KEY,
    level_name VARCHAR(50) NOT NULL
);

CREATE TABLE sections (
    section_id INT AUTO_INCREMENT PRIMARY KEY,
    grade_level_id INT NOT NULL,
    section_name VARCHAR(100) NOT NULL,
    max_capacity INT NOT NULL DEFAULT 30,
    current_slots INT NOT NULL DEFAULT 30,
    adviser_name VARCHAR(150) NULL,
    FOREIGN KEY (grade_level_id) REFERENCES grade_levels(grade_level_id) ON DELETE CASCADE
);

CREATE TABLE students (
    student_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    lrn VARCHAR(12) UNIQUE NOT NULL,
    school_year VARCHAR(20) NOT NULL DEFAULT '2026-2027',
    date_of_registration DATE NOT NULL,
    grade_level_id INT NOT NULL,
    section_id INT DEFAULT NULL,
    last_name VARCHAR(100) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100) NULL,
    nickname VARCHAR(50) NULL,
    date_of_birth DATE NOT NULL,
    place_of_birth VARCHAR(150) NOT NULL,
    gender ENUM('Male', 'Female') NOT NULL,
    religion VARCHAR(100) NOT NULL,
    contact_no VARCHAR(20) NOT NULL,
    present_address TEXT NOT NULL,
    is_transferee ENUM('Yes', 'No') DEFAULT 'No',
    former_elementary_school VARCHAR(200) NULL,
    general_average DECIMAL(5,2) NULL,
    enrollment_status ENUM('Pending Review', 'Documents Pending', 'Assessment Pending', 'Payment Verification', 'Officially Enrolled') DEFAULT 'Payment Verification',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (grade_level_id) REFERENCES grade_levels(grade_level_id),
    FOREIGN KEY (section_id) REFERENCES sections(section_id) ON DELETE SET NULL
);

CREATE TABLE parent_emergency_info (
    parent_info_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    father_name VARCHAR(150) NULL,
    father_contact VARCHAR(20) NULL,
    father_occupation VARCHAR(100) NULL,
    mother_name VARCHAR(150) NULL,
    mother_contact VARCHAR(20) NULL,
    mother_occupation VARCHAR(100) NULL,
    emergency_contact_name VARCHAR(150) NOT NULL,
    emergency_contact_number VARCHAR(20) NOT NULL,
    emergency_relationship VARCHAR(50) NOT NULL,
    emergency_address TEXT NOT NULL,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE
);


CREATE TABLE medical_records (
    medical_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    illnesses TEXT NULL,
    allergies TEXT NULL,
    surgical_operations TEXT NULL,
    allowed_medications TEXT NULL,
    opt_out_otc TINYINT(1) DEFAULT 0,
    signature_name VARCHAR(150) NOT NULL,
    signature_relation VARCHAR(50) NOT NULL,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE
);


CREATE TABLE submitted_documents (
    doc_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    doc_type ENUM('Birth Certificate', 'Form 137', 'Good Moral', 'Report Card', 'ESC Certificate') NOT NULL,
    status ENUM('Submitted', 'Pending', 'Missing', 'Verified') DEFAULT 'Pending',
    verified_by INT DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES users(user_id)
);


CREATE TABLE payment_records (
    payment_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    payment_scheme ENUM('Annually', 'Semi-Annually', 'Quarterly', 'Monthly') NOT NULL,
    payment_method ENUM('Cash', 'GCash') NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    amount_paid DECIMAL(10,2) DEFAULT 0.00,
    balance DECIMAL(10,2) NOT NULL,
    payment_status ENUM('Pending Official Receipt', 'Partially Paid', 'Fully Paid') DEFAULT 'Pending Official Receipt',
    receipt_number VARCHAR(50) UNIQUE DEFAULT NULL,
    processed_by INT DEFAULT NULL,
    date_issued TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (processed_by) REFERENCES users(user_id)
);


CREATE TABLE payment_transactions (
    transaction_id INT AUTO_INCREMENT PRIMARY KEY,
    payment_id INT NOT NULL,
    student_id INT NOT NULL,
    amount_paid DECIMAL(10,2) NOT NULL,
    receipt_number VARCHAR(50) NOT NULL,
    payment_method VARCHAR(50) NOT NULL,
    collected_by INT NOT NULL,
    payment_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (payment_id) REFERENCES payment_records(payment_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (collected_by) REFERENCES users(user_id)
);


INSERT INTO grade_levels (grade_level_id, level_name) VALUES 
(1, 'Preschool'),
(2, 'Kindergarten'),
(3, 'Grade 1'),
(4, 'Grade 2'),
(5, 'Grade 3'),
(6, 'Grade 4'),
(7, 'Grade 5'),
(8, 'Grade 6'),
(9, 'Grade 7 (Junior High)'),
(10, 'Grade 8 (Junior High)'),
(11, 'Grade 9 (Junior High)'),
(12, 'Grade 10 (Junior High)');


INSERT INTO sections (grade_level_id, section_name, max_capacity, current_slots, adviser_name) VALUES 
(1, 'Preschool - Faith', 30, 30, 'Mrs. Joy'),
(2, 'Kinder - Hope', 30, 30, 'Mrs. Grace'),
(3, 'Grade 1 - Charity', 30, 30, 'Mrs. Mary'),
(4, 'Grade 2 - Peace', 30, 30, 'Mrs. Sarah'),
(5, 'Grade 3 - Patience', 30, 30, 'Mrs. Hannah'),
(6, 'Grade 4 - Kindness', 30, 30, 'Mrs. Ruth'),
(7, 'Grade 5 - Goodness', 30, 30, 'Mrs. Esther'),
(8, 'Grade 6 - Faithfulness', 30, 30, 'Mrs. Deborah'),
(9, 'Grade 7 - St. Paul', 30, 30, 'Mrs. Nila Santiago'),
(10, 'Grade 8 - St. Peter', 30, 30, 'Mr. Lacandula'),
(11, 'Grade 9 - St. John', 30, 30, 'Mr. Guzman'),
(12, 'Grade 10 - St. Luke', 30, 30, 'Mr. Reyes');