<?php
/**
 * نقطة نهاية بلاغات العثور على مركبة (Found Reports API)
 * مطابقة لمخطط النشاط ومخطط الفئات
 */

require_once __DIR__ . '/../config/app.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$db = Database::getConnection();

// 1. جلب تفاصيل بلاغ عثور مفرد
if ($method === 'GET' && isset($_GET['id'])) {
    $reportId = (int)$_GET['id'];
    
    $stmt = $db->prepare("
        SELECT 
            fr.report_id,
            fr.user_id,
            fr.vehicle_id,
            fr.found_location,
            fr.found_date,
            fr.description,
            fr.status,
            fr.created_at,
            v.type AS vehicle_type,
            v.make,
            v.model,
            v.year,
            v.color,
            v.plate_number,
            v.chassis_number,
            u.full_name AS finder_name,
            u.phone_number AS finder_phone
        FROM found_reports fr
        LEFT JOIN vehicles v ON fr.vehicle_id = v.vehicle_id
        JOIN users u ON fr.user_id = u.user_id
        WHERE fr.report_id = ?
    ");
    $stmt->execute([$reportId]);
    $report = $stmt->fetch();

    if (!$report) {
        jsonError('البلاغ غير موجود', 404);
    }

    // جلب المرفقات مع تصنيف الصورة
    $attStmt = $db->prepare("
        SELECT attachment_id, file_name, file_type, file_path, attachment_tag, upload_date
        FROM attachments
        WHERE report_type = 'found' AND report_id = ?
    ");
    $attStmt->execute([$reportId]);
    $report['attachments'] = $attStmt->fetchAll();

    jsonSuccess('تم جلب تفاصيل بلاغ العثور', ['report' => $report]);
}

// 2. قائمة بلاغات العثور
if ($method === 'GET') {
    $isMy = isset($_GET['my']) && $_GET['my'] == '1';
    $statusFilter = $_GET['status'] ?? null;

    $whereClauses = [];
    $params = [];

    if ($isMy) {
        $currentUser = requireAuth();
        $whereClauses[] = "fr.user_id = ?";
        $params[] = $currentUser['user_id'];
    } elseif (!isAdmin()) {
        $whereClauses[] = "fr.status IN ('منشور', 'يوجد تطابق محتمل')";
    }

    if (!empty($statusFilter)) {
        $whereClauses[] = "fr.status = ?";
        $params[] = $statusFilter;
    }

    $sql = "
        SELECT 
            fr.report_id,
            fr.user_id,
            fr.vehicle_id,
            fr.found_location,
            fr.found_date,
            fr.description,
            fr.status,
            fr.created_at,
            v.type AS vehicle_type,
            v.make,
            v.model,
            v.year,
            v.color,
            v.plate_number,
            v.chassis_number,
            u.full_name AS finder_name
        FROM found_reports fr
        LEFT JOIN vehicles v ON fr.vehicle_id = v.vehicle_id
        JOIN users u ON fr.user_id = u.user_id
    ";

    if (!empty($whereClauses)) {
        $sql .= " WHERE " . implode(" AND ", $whereClauses);
    }

    $sql .= " ORDER BY fr.report_id DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $reports = $stmt->fetchAll();

    // إرفاق الصورة الأولى كصورة مصغرة
    foreach ($reports as &$r) {
        $imgStmt = $db->prepare("
            SELECT file_path FROM attachments 
            WHERE report_type = 'found' AND report_id = ? 
            LIMIT 1
        ");
        $imgStmt->execute([$r['report_id']]);
        $firstImg = $imgStmt->fetch();
        $r['thumbnail'] = $firstImg ? $firstImg['file_path'] : null;
    }

    jsonSuccess('تم جلب قائمة بلاغات العثور', ['reports' => $reports]);
}

// 3. إضافة بلاغ عثور جديد
if ($method === 'POST') {
    $currentUser = requireAuth();

    $foundLocation = sanitizeInput($_POST['found_location'] ?? '');
    $foundDate     = sanitizeInput($_POST['found_date'] ?? date('Y-m-d'));
    $description   = sanitizeInput($_POST['description'] ?? '');

    // بيانات مواصفات السيارة التقريبية (اختيارية إن وجدت أو تم التعرف عليها)
    $type          = sanitizeInput($_POST['type'] ?? '');
    $make          = sanitizeInput($_POST['make'] ?? '');
    $model         = sanitizeInput($_POST['model'] ?? '');
    $year          = !empty($_POST['year']) ? (int)$_POST['year'] : 0;
    $color         = sanitizeInput($_POST['color'] ?? '');
    $plateNumber   = sanitizeInput($_POST['plate_number'] ?? '');
    $chassisNumber = sanitizeInput($_POST['chassis_number'] ?? '');

    if (empty($foundLocation) || empty($description)) {
        jsonError('يرجى تحديد موقع العثور التقريبي ووصف المركبة');
    }

    try {
        $db->beginTransaction();

        $vehicleId = null;
        // إذا قام المبلّغ بإدخال بعض مواصفات المركبة (مثل الماركة أو اللون أو اللوحة أو رقم الشاسيه)
        if (!empty($make) || !empty($color) || !empty($plateNumber) || !empty($chassisNumber)) {
            $vStmt = $db->prepare("
                INSERT INTO vehicles (type, make, model, year, color, plate_number, chassis_number)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $vStmt->execute([
                !empty($type) ? $type : 'غير محدد',
                !empty($make) ? $make : 'غير محدد',
                !empty($model) ? $model : 'غير محدد',
                $year > 0 ? $year : (int)date('Y'),
                !empty($color) ? $color : 'غير محدد',
                !empty($plateNumber) ? $plateNumber : null,
                !empty($chassisNumber) ? $chassisNumber : null
            ]);
            $vehicleId = (int)$db->lastInsertId();
        }

        // إنشاء بلاغ العثور
        $rStmt = $db->prepare("
            INSERT INTO found_reports (user_id, vehicle_id, found_location, found_date, description, status)
            VALUES (?, ?, ?, ?, ?, 'قيد المراجعة')
        ");
        $rStmt->execute([$currentUser['user_id'], $vehicleId, $foundLocation, $foundDate, $description]);
        $reportId = (int)$db->lastInsertId();

        // رفع الصور المصنفة (صورة عامة، صورة اللوحة، صورة الشاسيه) والمرفقات
        $uploadedFiles = [];

        // أ) صورة عامة للمركبة
        if (!empty($_FILES['photo_general']) && $_FILES['photo_general']['error'] === UPLOAD_ERR_OK) {
            $up = handleFileUpload($_FILES['photo_general'], 'found', $reportId, 'صورة عامة للمركبة');
            if ($up) $uploadedFiles[] = $up;
        }

        // ب) صورة اللوحة إن وجدت
        if (!empty($_FILES['photo_plate']) && $_FILES['photo_plate']['error'] === UPLOAD_ERR_OK) {
            $up = handleFileUpload($_FILES['photo_plate'], 'found', $reportId, 'صورة اللوحة');
            if ($up) $uploadedFiles[] = $up;
        }

        // ج) صورة رقم الشاسيه / مكان رقم الشاسيه
        if (!empty($_FILES['photo_chassis']) && $_FILES['photo_chassis']['error'] === UPLOAD_ERR_OK) {
            $up = handleFileUpload($_FILES['photo_chassis'], 'found', $reportId, 'صورة رقم الشاسيه');
            if ($up) $uploadedFiles[] = $up;
        }

        // د) مرفقات وصور إضافية
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
                        $uploadResult = handleFileUpload($singleFile, 'found', $reportId, 'مرفق / صورة إضافية');
                        if ($uploadResult) {
                            $uploadedFiles[] = $uploadResult;
                        }
                    }
                }
            } else if ($files['error'] === UPLOAD_ERR_OK) {
                $uploadResult = handleFileUpload($files, 'found', $reportId, 'مرفق / صورة إضافية');
                if ($uploadResult) {
                    $uploadedFiles[] = $uploadResult;
                }
            }
        }

        // إشعار تأكيد للمبلّغ
        $nStmt = $db->prepare("
            INSERT INTO notifications (user_id, message, is_read, related_report_type, related_report_id)
            VALUES (?, ?, 0, 'found', ?)
        ");
        $nStmt->execute([
            $currentUser['user_id'],
            "شكراً لمساهمتك! تم تسجيل بلاغ العثور بنجاح برقم #{$reportId} وسيتم مراجعته ومطابقته بواسطة إدارة النظام.",
            $reportId
        ]);

        $db->commit();
        CacheEngine::flush();

        jsonSuccess('تم تسجيل بلاغ العثور على المركبة بنجاح', [
            'report_id' => $reportId,
            'status'    => 'قيد المراجعة',
            'attachments_count' => count($uploadedFiles)
        ], 201);

    } catch (Exception $e) {
        $db->rollBack();
        jsonError('حدث خطأ أثناء حفظ البلاغ: ' . $e->getMessage(), 500);
    }
}
