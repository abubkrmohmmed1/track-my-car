<?php
/**
 * نقطة نهاية بلاغات الفقدان (Lost Reports API)
 * مطابقة لمخطط النشاط ومخطط الفئات
 */

require_once __DIR__ . '/../config/app.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$db = Database::getConnection();

// 1. جلب بلاغ مفرد بالتفاصيل والمرفقات
if ($method === 'GET' && isset($_GET['id'])) {
    $reportId = (int)$_GET['id'];
    
    $stmt = $db->prepare("
        SELECT 
            lr.report_id,
            lr.user_id,
            lr.vehicle_id,
            lr.last_seen_location,
            lr.loss_date,
            lr.description,
            lr.status,
            lr.created_at,
            v.type AS vehicle_type,
            v.make,
            v.model,
            v.year,
            v.color,
            v.plate_number,
            v.chassis_number,
            u.full_name AS reporter_name,
            u.phone_number AS reporter_phone
        FROM lost_reports lr
        JOIN vehicles v ON lr.vehicle_id = v.vehicle_id
        JOIN users u ON lr.user_id = u.user_id
        WHERE lr.report_id = ?
    ");
    $stmt->execute([$reportId]);
    $report = $stmt->fetch();

    if (!$report) {
        jsonError('البلاغ غير موجود', 404);
    }

    // جلب المرفقات المرتبطة بالبلاغ مع التصنيف
    $attStmt = $db->prepare("
        SELECT attachment_id, file_name, file_type, file_path, attachment_tag, upload_date
        FROM attachments
        WHERE report_type = 'lost' AND report_id = ?
    ");
    $attStmt->execute([$reportId]);
    $report['attachments'] = $attStmt->fetchAll();

    jsonSuccess('تم جلب تفاصيل البلاغ', ['report' => $report]);
}

// 2. قائمة البلاغات
if ($method === 'GET') {
    $isMy = isset($_GET['my']) && $_GET['my'] == '1';
    $statusFilter = $_GET['status'] ?? null;

    $whereClauses = [];
    $params = [];

    if ($isMy) {
        $currentUser = requireAuth();
        $whereClauses[] = "lr.user_id = ?";
        $params[] = $currentUser['user_id'];
    } elseif (!isAdmin()) {
        // لغير الإدارة، تُعرض فقط البلاغات المنشورة أو ذات التطابق المحتمل للجمهور
        $whereClauses[] = "lr.status IN ('منشور', 'يوجد تطابق محتمل')";
    }

    if (!empty($statusFilter)) {
        $whereClauses[] = "lr.status = ?";
        $params[] = $statusFilter;
    }

    $sql = "
        SELECT 
            lr.report_id,
            lr.user_id,
            lr.vehicle_id,
            lr.last_seen_location,
            lr.loss_date,
            lr.description,
            lr.status,
            lr.created_at,
            v.type AS vehicle_type,
            v.make,
            v.model,
            v.year,
            v.color,
            v.plate_number,
            v.chassis_number,
            u.full_name AS reporter_name
        FROM lost_reports lr
        JOIN vehicles v ON lr.vehicle_id = v.vehicle_id
        JOIN users u ON lr.user_id = u.user_id
    ";

    if (!empty($whereClauses)) {
        $sql .= " WHERE " . implode(" AND ", $whereClauses);
    }

    $sql .= " ORDER BY lr.report_id DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $reports = $stmt->fetchAll();

    // إرفاق الصورة الأولى كصورة مصغرة
    foreach ($reports as &$r) {
        $imgStmt = $db->prepare("
            SELECT file_path FROM attachments 
            WHERE report_type = 'lost' AND report_id = ? 
            LIMIT 1
        ");
        $imgStmt->execute([$r['report_id']]);
        $firstImg = $imgStmt->fetch();
        $r['thumbnail'] = $firstImg ? $firstImg['file_path'] : null;
    }

    jsonSuccess('تم جلب قائمة البلاغات', ['reports' => $reports]);
}

// 3. إضافة بلاغ فقدان جديد
if ($method === 'POST') {
    $currentUser = requireAuth();

    // قراءة البيانات من FormData أو JSON
    $type          = sanitizeInput($_POST['type'] ?? '');
    $make          = sanitizeInput($_POST['make'] ?? '');
    $model         = sanitizeInput($_POST['model'] ?? '');
    $year          = (int)($_POST['year'] ?? date('Y'));
    $color         = sanitizeInput($_POST['color'] ?? '');
    $plateNumber   = sanitizeInput($_POST['plate_number'] ?? '');
    $chassisNumber = sanitizeInput($_POST['chassis_number'] ?? '');
    
    $lastSeenLocation = sanitizeInput($_POST['last_seen_location'] ?? '');
    $lossDate         = sanitizeInput($_POST['loss_date'] ?? date('Y-m-d'));
    $description      = sanitizeInput($_POST['description'] ?? '');

    if (empty($type) || empty($make) || empty($model) || empty($color) || empty($lastSeenLocation)) {
        jsonError('يرجى إكمال الحقول الأساسية للسيارة وموقع الفقدان');
    }

    try {
        $db->beginTransaction();

        // 1. إضافة بيانات المركبة في جدول vehicles
        $vStmt = $db->prepare("
            INSERT INTO vehicles (type, make, model, year, color, plate_number, chassis_number)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $vStmt->execute([$type, $make, $model, $year, $color, $plateNumber, $chassisNumber]);
        $vehicleId = (int)$db->lastInsertId();

        // 2. إضافة بلاغ الفقدان في جدول lost_reports بحالة 'قيد المراجعة'
        $rStmt = $db->prepare("
            INSERT INTO lost_reports (user_id, vehicle_id, last_seen_location, loss_date, description, status)
            VALUES (?, ?, ?, ?, ?, 'قيد المراجعة')
        ");
        $rStmt->execute([$currentUser['user_id'], $vehicleId, $lastSeenLocation, $lossDate, $description]);
        $reportId = (int)$db->lastInsertId();

        // 3. معالجة رفع الصور المصنفة (صورة عامة، صورة اللوحة، صورة الشاسيه) والمستندات
        $uploadedFiles = [];

        // أ) صورة عامة للمركبة
        if (!empty($_FILES['photo_general']) && $_FILES['photo_general']['error'] === UPLOAD_ERR_OK) {
            $up = handleFileUpload($_FILES['photo_general'], 'lost', $reportId, 'صورة عامة للمركبة');
            if ($up) $uploadedFiles[] = $up;
        }

        // ب) صورة اللوحة إن وجدت
        if (!empty($_FILES['photo_plate']) && $_FILES['photo_plate']['error'] === UPLOAD_ERR_OK) {
            $up = handleFileUpload($_FILES['photo_plate'], 'lost', $reportId, 'صورة اللوحة');
            if ($up) $uploadedFiles[] = $up;
        }

        // ج) صورة رقم الشاسيه / مكان رقم الشاسيه
        if (!empty($_FILES['photo_chassis']) && $_FILES['photo_chassis']['error'] === UPLOAD_ERR_OK) {
            $up = handleFileUpload($_FILES['photo_chassis'], 'lost', $reportId, 'صورة رقم الشاسيه');
            if ($up) $uploadedFiles[] = $up;
        }

        // د) المرفقات الإضافية أو مستندات الملكية
        if (!empty($_FILES['attachments'])) {
            $files = $_FILES['attachments'];
            if (is_array($files['name'])) {
                for ($i = 0; $i < count($files['name']); $i++) {
                    if ($files['error'][$i] === UPLOAD_ERR_OK) {
                        $singleFile = [
                            'name'     => $files['name'][$i],
                            'type'     => $files['type'][$i],
                            'tmp_name' => $files['tmp_name'][$i],
                            'error'    => $files['error'][$i],
                            'size'     => $files['size'][$i],
                        ];
                        $uploadResult = handleFileUpload($singleFile, 'lost', $reportId, 'مستند / مرفق إضافي');
                        if ($uploadResult) {
                            $uploadedFiles[] = $uploadResult;
                        }
                    }
                }
            } else if ($files['error'] === UPLOAD_ERR_OK) {
                $uploadResult = handleFileUpload($files, 'lost', $reportId, 'مستند / مرفق إضافي');
                if ($uploadResult) {
                    $uploadedFiles[] = $uploadResult;
                }
            }
        }

        // 4. إنشاء إشعار للمستخدم بتأكيد استلام البلاغ
        $nStmt = $db->prepare("
            INSERT INTO notifications (user_id, message, is_read, related_report_type, related_report_id)
            VALUES (?, ?, 0, 'lost', ?)
        ");
        $nStmt->execute([
            $currentUser['user_id'],
            "تم تسجيل بلاغك بنجاح برقم #{$reportId} وحالته الحالية 'قيد المراجعة'.",
            $reportId
        ]);

        $db->commit();
        CacheEngine::flush();

        jsonSuccess('تم تسجيل بلاغ فقدان السيارة بنجاح', [
            'report_id' => $reportId,
            'status'    => 'قيد المراجعة',
            'attachments_count' => count($uploadedFiles)
        ], 201);

    } catch (Exception $e) {
        $db->rollBack();
        jsonError('حدث خطأ أثناء حفظ البلاغ: ' . $e->getMessage(), 500);
    }
}
