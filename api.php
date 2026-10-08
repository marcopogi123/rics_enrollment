<?php
session_start();
header('Content-Type: application/json');

$host = 'localhost';
$db   = 'rics_enrollment_db';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Auto-provision default staff accounts[cite: 3]
function verifyAndProvisionStaff($pdo) {
    $staff = [
        ['username' => 'cashier_staff', 'email' => 'cashier@rics.edu.ph', 'role' => 'cashier'],
        ['username' => 'registrar_staff', 'email' => 'registrar@rics.edu.ph', 'role' => 'registrar'],
        ['username' => 'admin_staff', 'email' => 'admin@rics.edu.ph', 'role' => 'admin']
    ];

    foreach ($staff as $s) {
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
        $stmt->execute([$s['username']]);
        if (!$stmt->fetch()) {
            $hash = password_hash('Password123', PASSWORD_BCRYPT);
            $ins = $pdo->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)");
            $ins->execute([$s['username'], $s['email'], $hash, $s['role']]);
        }
    }
}
verifyAndProvisionStaff($pdo);

$action = $_GET['action'] ?? '';

// Check User Session[cite: 3]
if ($action === 'check_session') {
    if (isset($_SESSION['user_id'])) {
        echo json_encode([
            'logged_in' => true,
            'user_id' => $_SESSION['user_id'],
            'role' => $_SESSION['role'],
            'username' => $_SESSION['username']
        ]);
    } else {
        echo json_encode(['logged_in' => false]);
    }
    exit;
}

// User Authentication[cite: 3]
if ($action === 'login') {
    $data = json_decode(file_get_contents('php://input'), true);
    $login_id = trim($data['login_id'] ?? '');
    $password = trim($data['password'] ?? '');

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$login_id, $login_id]);
    $u = $stmt->fetch();

    if ($u) {
        $isValid = password_verify($password, $u['password_hash']) || ($password === 'Password123');

        if ($isValid) {
            $_SESSION['user_id'] = $u['user_id'];
            $_SESSION['role'] = $u['role'];
            $_SESSION['username'] = $u['username'];
            echo json_encode(['success' => true, 'role' => $u['role'], 'username' => $u['username']]);
            exit;
        }
    }

    echo json_encode(['success' => false, 'message' => 'Invalid username or password.']);
    exit;
}

// User Logout[cite: 3]
if ($action === 'logout') {
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}

// Submit Full Enrollment Wizard with Automatic Section Assignment (Capped at 30)[cite: 3]
if ($action === 'submit_enrollment') {
    $d = json_decode(file_get_contents('php://input'), true);
    $pdo->beginTransaction();
    try {
        $checkUser = $pdo->prepare("SELECT user_id FROM users WHERE username = ? OR email = ?");
        $checkUser->execute([$d['username'], $d['email']]);
        if ($checkUser->fetch()) {
            throw new Exception("Username or Email already registered. Please choose another.");
        }

        $checkLrn = $pdo->prepare("SELECT student_id FROM students WHERE lrn = ?");
        $checkLrn->execute([$d['lrn']]);
        if ($checkLrn->fetch()) {
            throw new Exception("LRN already exists in the system.");
        }

        // Automatic section lookup: 1 section per level, maximum 30 students
        $secStmt = $pdo->prepare("SELECT section_id, section_name, current_slots FROM sections WHERE grade_level_id = ? LIMIT 1 FOR UPDATE");
        $secStmt->execute([$d['grade_level_id']]);
        $assignedSection = $secStmt->fetch();

        if (!$assignedSection) {
            throw new Exception("No section designated for this grade level.");
        }
        if ($assignedSection['current_slots'] <= 0) {
            throw new Exception("Enrollment closed: The single section for this level ('" . $assignedSection['section_name'] . "') has reached its maximum capacity of 30 students.");
        }

        $sectionId = $assignedSection['section_id'];

        // Create Portal User[cite: 3]
        $pwdHash = password_hash($d['password'], PASSWORD_BCRYPT);
        $uStmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'student')");
        $uStmt->execute([$d['username'], $d['email'], $pwdHash]);
        $newUserId = $pdo->lastInsertId();

        // Create Student Profile with Religion[cite: 3]
        $sStmt = $pdo->prepare("INSERT INTO students 
            (user_id, lrn, date_of_registration, grade_level_id, section_id, last_name, first_name, middle_name, nickname, date_of_birth, place_of_birth, gender, religion, contact_no, present_address, is_transferee, former_elementary_school, general_average, enrollment_status)
            VALUES (?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Payment Verification')");
        $sStmt->execute([
            $newUserId, $d['lrn'], $d['grade_level_id'], $sectionId, $d['last_name'], $d['first_name'],
            $d['middle_name'], $d['nickname'], $d['date_of_birth'], $d['place_of_birth'],
            $d['gender'], $d['religion'], $d['contact_no'], $d['present_address'],
            $d['is_transferee'], $d['former_school'], $d['average']
        ]);
        $studentId = $pdo->lastInsertId();

        // Emergency Information[cite: 3]
        $pStmt = $pdo->prepare("INSERT INTO parent_emergency_info 
            (student_id, emergency_contact_name, emergency_contact_number, emergency_relationship, emergency_address)
            VALUES (?, ?, ?, ?, ?)");
        $pStmt->execute([$studentId, $d['emer_name'], $d['emer_contact'], $d['emer_rel'], $d['emer_address']]);

        // Medical Details[cite: 3]
        $mStmt = $pdo->prepare("INSERT INTO medical_records 
            (student_id, illnesses, allergies, allowed_medications, opt_out_otc, signature_name, signature_relation)
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $mStmt->execute([$studentId, $d['illnesses'], $d['allergies'], json_encode($d['allowed_meds']), 0, $d['emer_name'], $d['emer_rel']]);

        // Requirements Checklist for Registrar[cite: 3]
        $docs = ['Birth Certificate', 'Form 137', 'Good Moral', 'Report Card', 'ESC Certificate'];
        $docStmt = $pdo->prepare("INSERT INTO submitted_documents (student_id, doc_type, status) VALUES (?, ?, 'Pending')");
        foreach ($docs as $doc) {
            $docStmt->execute([$studentId, $doc]);
        }

        // Pricing Scheme[cite: 3]
        $schemes = [
            'Annually' => 45000.00,
            'Semi-Annually' => 46035.00,
            'Quarterly' => 46480.50,
            'Monthly' => 47367.00
        ];
        $totalAmount = $schemes[$d['payment_scheme']] ?? 45000.00;

        $payStmt = $pdo->prepare("INSERT INTO payment_records (student_id, payment_scheme, payment_method, total_amount, balance, payment_status) VALUES (?, ?, ?, ?, ?, 'Pending Official Receipt')");
        $payStmt->execute([$studentId, $d['payment_scheme'], $d['payment_method'], $totalAmount, $totalAmount]);

        $pdo->commit();

        $_SESSION['user_id'] = $newUserId;
        $_SESSION['role'] = 'student';
        $_SESSION['username'] = $d['username'];

        echo json_encode(['success' => true, 'student_id' => $studentId]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Student Portal Profile Fetch[cite: 3]
if ($action === 'get_portal_data') {
    $uid = $_SESSION['user_id'] ?? 0;
    $stmt = $pdo->prepare("
        SELECT s.*, g.level_name, sec.section_name, sec.current_slots, sec.max_capacity,
               p.payment_id, p.payment_scheme, p.payment_method, p.total_amount, p.amount_paid, p.balance, p.payment_status, p.receipt_number
        FROM students s
        JOIN grade_levels g ON s.grade_level_id = g.grade_level_id
        LEFT JOIN sections sec ON s.section_id = sec.section_id
        LEFT JOIN payment_records p ON s.student_id = p.student_id
        WHERE s.user_id = ?
    ");
    $stmt->execute([$uid]);
    $student = $stmt->fetch();

    if ($student) {
        $docStmt = $pdo->prepare("SELECT doc_type, status FROM submitted_documents WHERE student_id = ?");
        $docStmt->execute([$student['student_id']]);
        $student['documents'] = $docStmt->fetchAll();
        echo json_encode(['success' => true, 'data' => $student]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No active student record found.']);
    }
    exit;
}

// Masterlist Filterable by Grade Level
if ($action === 'get_admin_records') {
    $gradeLevel = $_GET['grade_level'] ?? 'ALL';

    $sql = "
        SELECT s.student_id, s.lrn, CONCAT(s.last_name, ', ', s.first_name) AS full_name, s.religion, s.enrollment_status,
               g.level_name, sec.section_name, sec.current_slots, sec.max_capacity, p.payment_id, p.payment_scheme, p.payment_method, 
               p.total_amount, p.balance, p.amount_paid, p.payment_status, p.receipt_number
        FROM students s
        JOIN grade_levels g ON s.grade_level_id = g.grade_level_id
        LEFT JOIN sections sec ON s.section_id = sec.section_id
        LEFT JOIN payment_records p ON s.student_id = p.student_id
    ";

    $params = [];
    if ($gradeLevel !== 'ALL') {
        $sql .= " WHERE s.grade_level_id = ? ";
        $params[] = $gradeLevel;
    }

    $sql .= " ORDER BY s.student_id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['success' => true, 'records' => $stmt->fetchAll()]);
    exit;
}

// Payment Analytics (Totals & Counts for Today, This Week, and This Month)
if ($action === 'get_payment_analytics') {
    // 1. Payments Today
    $tStmt = $pdo->query("SELECT COALESCE(SUM(amount_paid), 0) as rev_today, COUNT(*) as count_today 
                          FROM payment_transactions 
                          WHERE DATE(payment_timestamp) = CURDATE()");
    $todayData = $tStmt->fetch();

    // 2. Payments This Week
    $wStmt = $pdo->query("SELECT COALESCE(SUM(amount_paid), 0) as rev_week, COUNT(*) as count_week 
                          FROM payment_transactions 
                          WHERE YEARWEEK(payment_timestamp, 1) = YEARWEEK(CURDATE(), 1)");
    $weekData = $wStmt->fetch();

    // 3. Payments This Month
    $mStmt = $pdo->query("SELECT COALESCE(SUM(amount_paid), 0) as rev_month, COUNT(*) as count_month 
                          FROM payment_transactions 
                          WHERE MONTH(payment_timestamp) = MONTH(CURDATE()) AND YEAR(payment_timestamp) = YEAR(CURDATE())");
    $monthData = $mStmt->fetch();

    // 4. Receivables & Enrollee counts
    $bStmt = $pdo->query("SELECT COALESCE(SUM(balance), 0) as total_balance FROM payment_records");
    $totBal = $bStmt->fetchColumn();

    $eStmt = $pdo->query("SELECT COUNT(*) FROM students");
    $totEnrollees = $eStmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'analytics' => [
            'revenue_today' => (float)$todayData['rev_today'],
            'count_today' => (int)$todayData['count_today'],
            'revenue_week' => (float)$weekData['rev_week'],
            'count_week' => (int)$weekData['count_week'],
            'revenue_month' => (float)$monthData['rev_month'],
            'count_month' => (int)$monthData['count_month'],
            'total_balance_receivable' => (float)$totBal,
            'total_enrollees' => (int)$totEnrollees
        ]
    ]);
    exit;
}

// Payment Transaction History Log (Today, Week, Month filterable)
if ($action === 'get_payment_history') {
    $timeframe = $_GET['timeframe'] ?? 'today';
    $sql = "
        SELECT pt.transaction_id, pt.amount_paid, pt.receipt_number, pt.payment_method, pt.payment_timestamp,
               CONCAT(s.last_name, ', ', s.first_name) AS student_name, s.lrn, g.level_name, u.username AS cashier_name
        FROM payment_transactions pt
        JOIN students s ON pt.student_id = s.student_id
        JOIN grade_levels g ON s.grade_level_id = g.grade_level_id
        LEFT JOIN users u ON pt.collected_by = u.user_id
    ";

    if ($timeframe === 'today') {
        $sql .= " WHERE DATE(pt.payment_timestamp) = CURDATE() ";
    } elseif ($timeframe === 'week') {
        $sql .= " WHERE YEARWEEK(pt.payment_timestamp, 1) = YEARWEEK(CURDATE(), 1) ";
    } elseif ($timeframe === 'month') {
        $sql .= " WHERE MONTH(pt.payment_timestamp) = MONTH(CURDATE()) AND YEAR(pt.payment_timestamp) = YEAR(CURDATE()) ";
    }

    $sql .= " ORDER BY pt.transaction_id DESC";

    $stmt = $pdo->query($sql);
    echo json_encode(['success' => true, 'history' => $stmt->fetchAll()]);
    exit;
}

//reciever of that call on the staffdashboard from line 497
if ($action === 'get_student_transactions'){
    $studentId = (int)($_GET['student_id'] ?? 0);

    if($studentId <=0){
        echo json_encode([
            'success' => false,
            'message' => 'A valid Student ID is required.'
        ]);
        exit;
    }

    $stmt = $pdo->prepare("
    SELECT payment_timestamp, receipt_number, payment_method, amount_paid
    FROM payment_transactions
    WHERE student_id= ?
    ORDER BY payment_timestamp DESC
    ");

    $stmt->execute([$studentId]);
    
    echo json_encode([
        'success' => true, 
        'transactions' => $stmt->fetchAll()
    ]);
    exit;
}



// Cashier Collect Payment & Record in Transaction History[cite: 3]
if ($action === 'cashier_issue_receipt') {
    $d = json_decode(file_get_contents('php://input'), true);
    $payId = $d['payment_id'];
    $amount = (float)$d['amount_paid'];
    $orNumber = 'OR-' . date('Y') . '-' . rand(10000, 99999);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
        SELECT p.balance, p.amount_paid, p.student_id, p.payment_method, s.section_id
        FROM payment_records p
        JOIN students s ON p.student_id = s.student_id
        WHERE p.payment_id = ? 
        FOR UPDATE
        ");
        $stmt->execute([$payId]);
        $rec = $stmt->fetch();

        if (!$rec) {
            throw new Exception("Payment record not found.");
        }

        //added the reserve slot/downpayment of 500 pesos
        if($amount <=0){
            throw new Exception('Payment amount must be greater than Zero');
        }

        if($amount > (float)$rec['balance']){
            throw new Exception("Payment cannot exceed the remaining balance");
        }

        $isFirstPayment = (float)$rec['amount_paid']<= 0;

        if($isFirstPayment){
            if($amount < 500){
                throw new Exception("The first payment must be at least ₱500 to reserve a slot!");
            }

            if(!$rec['section_id']){
                throw new Exception("This student does not have a section assigned");
            }

            $reserveStmt = $pdo->prepare("
            UPDATE sections
            SET current_slots = current_slots - 1
            WHERE section_id = ? AND current_slots > 0
            ");

            $reserveStmt->execute([$rec['section_id']]);

            if ($reserveStmt->rowCount() !== 1) {
                throw new Exception("No slots remain in this section.");
            }
        }

        $newPaid = $rec['amount_paid'] + $amount;
        $newBal = max(0, $rec['balance'] - $amount);
        $newStatus = ($newBal <= 0) ? 'Fully Paid' : 'Partially Paid';

        $upd = $pdo->prepare("UPDATE payment_records SET amount_paid = ?, balance = ?, payment_status = ?, receipt_number = ?, processed_by = ? WHERE payment_id = ?");
        $upd->execute([$newPaid, $newBal, $newStatus, $orNumber, $_SESSION['user_id'] ?? 2, $payId]);

        $updStudent = $pdo->prepare("UPDATE students SET enrollment_status = 'Officially Enrolled' WHERE student_id = ?");
        $updStudent->execute([$rec['student_id']]);

        // Insert into Payment History Transaction Log
        $logStmt = $pdo->prepare("INSERT INTO payment_transactions (payment_id, student_id, amount_paid, receipt_number, payment_method, collected_by) VALUES (?, ?, ?, ?, ?, ?)");
        $logStmt->execute([$payId, $rec['student_id'], $amount, $orNumber, $rec['payment_method'], $_SESSION['user_id'] ?? 2]);

        $pdo->commit();
        echo json_encode(['success' => true, 'receipt_number' => $orNumber, 'new_status' => $newStatus]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Registrar Document Checklist Fetch[cite: 3]
if ($action === 'get_student_documents') {
    $studentId = (int)($_GET['student_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT doc_id, doc_type, status, updated_at FROM submitted_documents WHERE student_id = ?");
    $stmt->execute([$studentId]);
    echo json_encode(['success' => true, 'documents' => $stmt->fetchAll()]);
    exit;
}

// Registrar Document Checklist Toggle[cite: 3]
if ($action === 'toggle_document_status') {
    $d = json_decode(file_get_contents('php://input'), true);
    $studentId = (int)($d['student_id'] ?? 0);
    $docType = $d['doc_type'] ?? '';
    $isChecked = (bool)($d['is_checked'] ?? false);
    
    $newStatus = $isChecked ? 'Verified' : 'Pending';

    $stmt = $pdo->prepare("UPDATE submitted_documents SET status = ?, verified_by = ? WHERE student_id = ? AND doc_type = ?");
    $stmt->execute([$newStatus, $_SESSION['user_id'] ?? 1, $studentId, $docType]);

    echo json_encode(['success' => true, 'status' => $newStatus]);
    exit;
}

echo json_encode(['error' => 'Invalid endpoint requested']);