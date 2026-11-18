<?php
/**
 * نقطة نهاية إدارة الحسابات والمصادقة
 * Authentication API (Register, Login, Logout, Profile)
 */

require_once __DIR__ . '/../config/app.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

// الحصول على بيانات JSON المرسلة إذا كان الطلب JSON
$inputData = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    if (!empty($raw)) {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $inputData = $json;
        }
    }
    // دمج بيانات $_POST مع بيانات JSON
    $inputData = array_merge($_POST, $inputData);
    if (empty($action) && isset($inputData['action'])) {
        $action = $inputData['action'];
    }
}

$db = Database::getConnection();

switch ($action) {
    case 'register':
        if ($method !== 'POST') {
            jsonError('طريقة الطلب غير صحيحة', 405);
        }

        $fullName    = sanitizeInput($inputData['full_name'] ?? '');
        $phoneNumber = sanitizeInput($inputData['phone_number'] ?? '');
        $email       = strtolower(trim($inputData['email'] ?? ''));
        $password    = $inputData['password'] ?? '';

        if (empty($fullName) || empty($phoneNumber) || empty($email) || empty($password)) {
            jsonError('يرجى ملء جميع الحقول المطلوبة');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonError('صيغة البريد الإلكتروني غير صحيحة');
        }

        if (strlen($password) < 6) {
            jsonError('كلمة المرور يجب أن لا تقل عن 6 أحرف أو أرقام');
        }

        // التحقق من عدم وجود البريد مسبقاً
        $checkStmt = $db->prepare("SELECT user_id FROM users WHERE email = ?");
        $checkStmt->execute([$email]);
        if ($checkStmt->fetch()) {
            jsonError('البريد الإلكتروني مسجل مسبقاً، يرجى تسجيل الدخول');
        }

        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        $insertStmt = $db->prepare("
            INSERT INTO users (full_name, phone_number, email, password_hash, role)
            VALUES (?, ?, ?, ?, 'user')
        ");
        $insertStmt->execute([$fullName, $phoneNumber, $email, $passwordHash]);
        $newUserId = (int)$db->lastInsertId();

        // تسجيل الدخول التلقائي
        $_SESSION['user_id']      = $newUserId;
        $_SESSION['full_name']    = $fullName;
        $_SESSION['email']        = $email;
        $_SESSION['phone_number'] = $phoneNumber;
        $_SESSION['role']         = 'user';

        jsonSuccess('تم إنشاء الحساب بنجاح وتم تسجيل دخولك', [
            'user' => currentUser()
        ], 201);
        break;

    case 'login':
        if ($method !== 'POST') {
            jsonError('طريقة الطلب غير صحيحة', 405);
        }

        $email    = strtolower(trim($inputData['email'] ?? ''));
        $password = $inputData['password'] ?? '';

        if (empty($email) || empty($password)) {
            jsonError('يرجى إدخال البريد الإلكتروني وكلمة المرور');
        }

        $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            jsonError('البريد الإلكتروني أو كلمة المرور غير صحيحة', 401);
        }

        $_SESSION['user_id']      = (int)$user['user_id'];
        $_SESSION['full_name']    = $user['full_name'];
        $_SESSION['email']        = $user['email'];
        $_SESSION['phone_number'] = $user['phone_number'];
        $_SESSION['role']         = $user['role'];

        jsonSuccess('تم تسجيل الدخول بنجاح', [
            'user' => currentUser()
        ]);
        break;

    case 'logout':
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
        jsonSuccess('تم تسجيل الخروج بنجاح');
        break;

    case 'me':
        if (isLoggedIn()) {
            jsonSuccess('المستخدم مسجل الدخول', [
                'user' => currentUser()
            ]);
        } else {
            jsonResponse([
                'success' => false,
                'logged_in' => false,
                'user' => null
            ]);
        }
        break;

    default:
        jsonError('إجراء غير معروف', 404);
}
