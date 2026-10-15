-- Add 50 clearly labeled test students without deleting existing data.
-- Distribution: 20 current-year, 15 previous-year, 15 older-year records.
-- Current-year test transferees marked Officially Enrolled: 3.
-- Test account password: TestPass123!
--
-- Run this in phpMyAdmin with rics_enrollment_db selected.
-- Requires existing grade_levels and at least one section for each grade level.

USE rics_enrollment_db;

DROP PROCEDURE IF EXISTS seed_school_year_test_students;
DELIMITER $$
CREATE PROCEDURE seed_school_year_test_students()
BEGIN
    DECLARE i INT DEFAULT 1;
    DECLARE userId INT;
    DECLARE studentId INT;
    DECLARE sectionId INT;
    DECLARE testUsername VARCHAR(100);
    DECLARE testEmail VARCHAR(150);
    DECLARE testLrn VARCHAR(12);
    DECLARE testYear VARCHAR(20);
    DECLARE testStatus VARCHAR(30);
    DECLARE testTransferee VARCHAR(3);
    DECLARE testPaymentScheme VARCHAR(20);
    DECLARE testTotalAmount DECIMAL(10, 2);
    DECLARE testEnrollmentInstallment DECIMAL(10, 2);
    DECLARE testAmountPaid DECIMAL(10, 2);
    DECLARE testBalance DECIMAL(10, 2);
    DECLARE testPaymentStatus VARCHAR(30);
    DECLARE relatedCount INT DEFAULT 0;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    WHILE i <= 50 DO
        SET testUsername = CONCAT('seed_sy_', LPAD(i, 3, '0'));
        SET testEmail = CONCAT(testUsername, '@example.test');
        SET testLrn = CONCAT('99000000', LPAD(i, 4, '0'));

        IF i <= 20 THEN
            SET testYear = '2026-2027';
        ELSEIF i <= 35 THEN
            SET testYear = '2025-2026';
        ELSE
            SET testYear = '2024-2025';
        END IF;

        CASE MOD(i - 1, 4)
            WHEN 0 THEN
                SET testPaymentScheme = 'Annually';
                SET testTotalAmount = 45000.00;
                SET testEnrollmentInstallment = 45000.00;
            WHEN 1 THEN
                SET testPaymentScheme = 'Semi-Annually';
                SET testTotalAmount = 46035.00;
                SET testEnrollmentInstallment = 23017.50;
            WHEN 2 THEN
                SET testPaymentScheme = 'Quarterly';
                SET testTotalAmount = 46480.50;
                SET testEnrollmentInstallment = 11620.13;
            ELSE
                SET testPaymentScheme = 'Monthly';
                SET testTotalAmount = 47367.00;
                SET testEnrollmentInstallment = 5263.00;
        END CASE;

        IF MOD(i, 3) = 0 THEN
            SET testStatus = 'Officially Enrolled';
            SET testAmountPaid = testEnrollmentInstallment;
        ELSE
            SET testStatus = 'Payment Verification';
            SET testAmountPaid = 0.00;
        END IF;

        SET testBalance = testTotalAmount - testAmountPaid;
        IF testBalance <= 0 THEN
            SET testPaymentStatus = 'Fully Paid';
        ELSEIF testAmountPaid > 0 THEN
            SET testPaymentStatus = 'Partially Paid';
        ELSE
            SET testPaymentStatus = 'Pending Official Receipt';
        END IF;

        IF MOD(i, 2) = 0 THEN
            SET testTransferee = 'Yes';
        ELSE
            SET testTransferee = 'No';
        END IF;

        SET userId = (
            SELECT user_id FROM users WHERE username = testUsername LIMIT 1
        );

        IF userId IS NULL THEN
            INSERT INTO users (username, email, password_hash, role)
            VALUES (
                testUsername,
                testEmail,
                '$2y$10$0enqFV55RY1AkJM7EGDf7.UL8Jhz9gBMRgPV9GnvaGphq2n3/GyTy',
                'student'
            );
            SET userId = LAST_INSERT_ID();
        END IF;

        SET studentId = (
            SELECT student_id FROM students WHERE lrn = testLrn LIMIT 1
        );
        SET sectionId = (
            SELECT section_id
            FROM sections
            WHERE grade_level_id = MOD(i - 1, 12) + 1
            ORDER BY section_id
            LIMIT 1
        );

        IF sectionId IS NULL THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'A grade level is missing its section; no test data was committed.';
        END IF;

        IF studentId IS NULL THEN
            INSERT INTO students (
                user_id, lrn, school_year, date_of_registration, grade_level_id,
                section_id, slot_reserved, last_name, first_name, middle_name,
                nickname, date_of_birth, place_of_birth, gender, religion,
                contact_no, present_address, is_transferee,
                former_elementary_school, general_average, enrollment_status
            )
            VALUES (
                userId, testLrn, testYear, CURDATE(), MOD(i - 1, 12) + 1,
                sectionId, 0, CONCAT('Seed', LPAD(i, 3, '0')),
                CONCAT('TestStudent', LPAD(i, 3, '0')), NULL, NULL,
                DATE_ADD('2008-01-01', INTERVAL (i * 13) DAY),
                'Test City', IF(MOD(i, 2) = 0, 'Female', 'Male'),
                'Christian', CONCAT('0999000', LPAD(i, 4, '0')),
                'TEST DATA - NOT A REAL ADDRESS', testTransferee,
                IF(testTransferee = 'Yes', 'Seed Test School', NULL),
                80.00 + MOD(i, 20), testStatus
            );
            SET studentId = LAST_INSERT_ID();
        END IF;

        SELECT COUNT(*) INTO relatedCount
        FROM parent_emergency_info
        WHERE student_id = studentId;
        IF relatedCount = 0 THEN
            INSERT INTO parent_emergency_info (
                student_id, emergency_contact_name, emergency_contact_number,
                emergency_relationship, emergency_address
            )
            VALUES (
                studentId, CONCAT('Test Emergency Contact ', LPAD(i, 3, '0')),
                CONCAT('0998000', LPAD(i, 4, '0')), 'Parent',
                'TEST DATA - NOT A REAL ADDRESS'
            );
        END IF;

        SELECT COUNT(*) INTO relatedCount
        FROM medical_records
        WHERE student_id = studentId;
        IF relatedCount = 0 THEN
            INSERT INTO medical_records (
                student_id, illnesses, allergies, allowed_medications,
                opt_out_otc, signature_name, signature_relation
            )
            VALUES (
                studentId, NULL, NULL, '[]', 0,
                CONCAT('Test Guardian ', LPAD(i, 3, '0')), 'Parent'
            );
        END IF;

        SELECT COUNT(*) INTO relatedCount
        FROM submitted_documents
        WHERE student_id = studentId;
        IF relatedCount = 0 THEN
            INSERT INTO submitted_documents (student_id, doc_type, status)
            VALUES
                (studentId, 'Birth Certificate', 'Pending'),
                (studentId, 'Form 137', 'Pending'),
                (studentId, 'Good Moral', 'Pending'),
                (studentId, 'Report Card', 'Pending'),
                (studentId, 'ESC Certificate', 'Pending');
        END IF;

        SELECT COUNT(*) INTO relatedCount
        FROM payment_records
        WHERE student_id = studentId;
        IF relatedCount = 0 THEN
            INSERT INTO payment_records (
                student_id, payment_scheme, payment_method, total_amount,
                amount_paid, balance, payment_status
            )
            VALUES (
                studentId, testPaymentScheme,
                IF(MOD(i, 2) = 0, 'GCash', 'Cash'), testTotalAmount,
                testAmountPaid, testBalance, testPaymentStatus
            );
        END IF;

        SET i = i + 1;
    END WHILE;

    COMMIT;
END$$
DELIMITER ;

CALL seed_school_year_test_students();
DROP PROCEDURE seed_school_year_test_students;

SELECT
    s.school_year,
    p.payment_scheme,
    COUNT(*) AS test_students,
    SUM(s.is_transferee = 'Yes' AND s.enrollment_status = 'Officially Enrolled')
        AS officially_enrolled_test_transferees
FROM students s
JOIN users u ON u.user_id = s.user_id
JOIN payment_records p ON p.student_id = s.student_id
WHERE u.username LIKE 'seed_sy_%'
GROUP BY s.school_year, p.payment_scheme
ORDER BY s.school_year DESC, p.payment_scheme;

-- Optional cleanup: run only if you want to remove these generated accounts.
-- Deleting these users also deletes their linked student and enrollment records.
-- DELETE FROM users WHERE username LIKE 'seed_sy_%';
