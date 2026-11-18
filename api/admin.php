<?php
/**
 * نقطة نهاية لوحة تحكم مدير النظام (Admin API)
 * إدارة المستخدمين، مراجعة البلاغات، محرك المطابقة والتحقق
 */

require_once __DIR__ . '/../config/app.php';

header('Content-Type: application/json; charset=utf-8');

// حماية نقطة النهاية: مقتصرة فقط على مدير النظام
$adminUser = requireAdmin();
$db = Database::getConnection();

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

switch ($action) {
    // 1. الإحصائيات العامة للنظام
    case 'stats':
        $totalLost  = $db->query("SELECT COUNT(*) FROM lost_reports")->fetchColumn();
        $totalFound = $db->query("SELECT COUNT(*) FROM found_reports")->fetchColumn();
        $pendingLost = $db->query("SELECT COUNT(*) FROM lost_reports WHERE status = 'قيد المراجعة'")->fetchColumn();
        $pendingFound = $db->query("SELECT COUNT(*) FROM found_reports WHERE status = 'قيد المراجعة'")->fetchColumn();
        $matched = $db->query("SELECT COUNT(*) FROM lost_reports WHERE status = 'يوجد تطابق محتمل'")->fetchColumn();
        $totalUsers = $db->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn();

        jsonSuccess('إحصائيات النظام', [
            'stats' => [
                'total_lost'     => (int)$totalLost,
                'total_found'    => (int)$totalFound,
                'pending_lost'   => (int)$pendingLost,
                'pending_found'  => (int)$pendingFound,
                'total_pending'  => (int)($pendingLost + $pendingFound),
                'matched'        => (int)$matched,
                'total_users'    => (int)$totalUsers
            ]
        ]);
        break;

    // 2. جلب جميع البلاغات للمراجعة
    case 'reports':
        $type   = $_GET['type'] ?? 'all'; // 'lost', 'found', 'all'
        $status = $_GET['status'] ?? '';

        $reports = [];

        if ($type === 'all' || $type === 'lost') {
            $sql = "
                SELECT 
                    'lost' AS report_type,
                    lr.report_id,
                    lr.user_id,
                    lr.last_seen_location AS location,
                    lr.loss_date AS report_date,
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
                    u.phone_number AS reporter_phone,
                    u.email AS reporter_email
                FROM lost_reports lr
                JOIN vehicles v ON lr.vehicle_id = v.vehicle_id
                JOIN users u ON lr.user_id = u.user_id
            ";
            $params = [];
            if (!empty($status)) {
                $sql .= " WHERE lr.status = ?";
                $params[] = $status;
            }
            $sql .= " ORDER BY lr.report_id DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $reports = array_merge($reports, $stmt->fetchAll());
        }

        if ($type === 'all' || $type === 'found') {
            $sql = "
                SELECT 
                    'found' AS report_type,
                    fr.report_id,
                    fr.user_id,
                    fr.found_location AS location,
                    fr.found_date AS report_date,
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
                    u.full_name AS reporter_name,
                    u.phone_number AS reporter_phone,
                    u.email AS reporter_email
                FROM found_reports fr
                LEFT JOIN vehicles v ON fr.vehicle_id = v.vehicle_id
                JOIN users u ON fr.user_id = u.user_id
            ";
            $params = [];
            if (!empty($status)) {
                $sql .= " WHERE fr.status = ?";
                $params[] = $status;
            }
            $sql .= " ORDER BY fr.report_id DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $reports = array_merge($reports, $stmt->fetchAll());
        }

        // جلب الصور مع التصنيف
        foreach ($reports as &$r) {
            $img = $db->prepare("SELECT file_path, attachment_tag FROM attachments WHERE report_type = ? AND report_id = ?");
            $img->execute([$r['report_type'], $r['report_id']]);
            $r['attachments'] = $img->fetchAll();
        }

        jsonSuccess('قائمة البلاغات', ['reports' => $reports]);
        break;

    // 3. تحديث حالة بلاغ (مع إشعار المستخدم تلقائياً)
    case 'update_status':
        if ($method !== 'POST') {
            jsonError('طريقة الطلب غير صحيحة', 405);
        }

        $reportType = sanitizeInput($_POST['report_type'] ?? '');
        $reportId   = (int)($_POST['report_id'] ?? 0);
        $newStatus  = sanitizeInput($_POST['status'] ?? '');
        $adminNotes = sanitizeInput($_POST['notes'] ?? '');

        $allowedStatuses = ['قيد المراجعة', 'منشور', 'يوجد تطابق محتمل', 'يتطلب استكمال البيانات', 'مغلق'];
        if (!in_array($newStatus, $allowedStatuses)) {
            jsonError('حالة البلاغ المحددة غير صحيحة');
        }

        if ($reportType === 'lost') {
            $stmt = $db->prepare("SELECT user_id FROM lost_reports WHERE report_id = ?");
            $stmt->execute([$reportId]);
            $report = $stmt->fetch();
            if (!$report) jsonError('البلاغ غير موجود', 404);

            $updateStmt = $db->prepare("UPDATE lost_reports SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE report_id = ?");
            $updateStmt->execute([$newStatus, $reportId]);
        } elseif ($reportType === 'found') {
            $stmt = $db->prepare("SELECT user_id FROM found_reports WHERE report_id = ?");
            $stmt->execute([$reportId]);
            $report = $stmt->fetch();
            if (!$report) jsonError('البلاغ غير موجود', 404);

            $updateStmt = $db->prepare("UPDATE found_reports SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE report_id = ?");
            $updateStmt->execute([$newStatus, $reportId]);
        } else {
            jsonError('نوع البلاغ غير صحيح');
        }

        // إرسال إشعار لصاحب البلاغ
        $message = "تم تحديث حالة بلاغك #{$reportId} إلى '{$newStatus}'.";
        if (!empty($adminNotes)) {
            $message .= " ملاحظة الإدارة: " . $adminNotes;
        }

        $notifStmt = $db->prepare("
            INSERT INTO notifications (user_id, message, is_read, related_report_type, related_report_id)
            VALUES (?, ?, 0, ?, ?)
        ");
        $notifStmt->execute([$report['user_id'], $message, $reportType, $reportId]);

        CacheEngine::flush();

        jsonSuccess('تم تحديث حالة البلاغ بنجاح وإرسال الإشعار للمستخدم', [
            'report_id' => $reportId,
            'status'    => $newStatus
        ]);
        break;

    // 4. محرك فحص واقتراح التطابقات بين بلاغات الفقدان وبلاغات العثور (Matching Engine)
    case 'find_matches':
        $lostReportId = (int)($_GET['lost_report_id'] ?? 0);
        if ($lostReportId <= 0) {
            jsonError('يرجى تحديد رقم بلاغ الفقدان للبحث عن تطابقات له');
        }

        // جلب بيانات المركبة المفقودة
        $lostStmt = $db->prepare("
            SELECT lr.*, v.make, v.model, v.color, v.year, v.plate_number, v.chassis_number, v.type AS vehicle_type
            FROM lost_reports lr
            JOIN vehicles v ON lr.vehicle_id = v.vehicle_id
            WHERE lr.report_id = ?
        ");
        $lostStmt->execute([$lostReportId]);
        $lost = $lostStmt->fetch();

        if (!$lost) {
            jsonError('بلاغ الفقدان غير موجود', 404);
        }

        // جلب جميع بلاغات العثور النشطة لمطابقتها
        $foundStmt = $db->query("
            SELECT fr.*, v.make, v.model, v.color, v.year, v.plate_number, v.chassis_number, v.type AS vehicle_type,
                   u.full_name AS finder_name, u.phone_number AS finder_phone
            FROM found_reports fr
            LEFT JOIN vehicles v ON fr.vehicle_id = v.vehicle_id
            JOIN users u ON fr.user_id = u.user_id
            WHERE fr.status NOT IN ('مغلق')
        ");
        $foundReports = $foundStmt->fetchAll();

        $candidates = [];

        foreach ($foundReports as $f) {
            $score = 0;
            $reasons = [];

            // 1. فحص رقم الشاسيه / الهيكل (أقوى تطابق قطعي حتى في حال نزع اللوحات = 85 نقطة)
            if (!empty($lost['chassis_number']) && !empty($f['chassis_number'])) {
                $cleanLostChassis  = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $lost['chassis_number']));
                $cleanFoundChassis = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $f['chassis_number']));

                if ($cleanLostChassis === $cleanFoundChassis) {
                    $score += 85;
                    $reasons[] = '⚡ تطابق تام في رقم الشاسيه (دليل قاطع حتى لو تم نزع اللوحات)';
                } elseif (strlen($cleanLostChassis) >= 6 && strlen($cleanFoundChassis) >= 6 && 
                          substr($cleanLostChassis, -6) === substr($cleanFoundChassis, -6)) {
                    $score += 65;
                    $reasons[] = '⚡ تطابق الأرقام التسلسلية الستة الأخيرة من رقم الشاسيه';
                }
            }

            // 2. فحص رقم اللوحة (تطابق تام = 60 نقطة)
            if (!empty($lost['plate_number']) && !empty($f['plate_number'])) {
                $cleanLostPlate  = preg_replace('/\s+/', '', $lost['plate_number']);
                $cleanFoundPlate = preg_replace('/\s+/', '', $f['plate_number']);
                if ($cleanLostPlate === $cleanFoundPlate) {
                    $score += 60;
                    $reasons[] = 'تطابق تام في رقم اللوحة';
                }
            }

            // 3. فحص الشركة المصنعة (تطابق = 20 نقطة)
            if (!empty($lost['make']) && !empty($f['make']) && mb_strtolower($lost['make']) === mb_strtolower($f['make'])) {
                $score += 20;
                $reasons[] = 'تطابق الشركة المصنعة (' . $lost['make'] . ')';
            }

            // 4. فحص الطراز (تطابق = 20 نقطة)
            if (!empty($lost['model']) && !empty($f['model']) && mb_strtolower($lost['model']) === mb_strtolower($f['model'])) {
                $score += 20;
                $reasons[] = 'تطابق الطراز (' . $lost['model'] . ')';
            }

            // 5. فحص اللون (تطابق = 15 نقطة)
            if (!empty($lost['color']) && !empty($f['color']) && mb_stripos($f['color'], $lost['color']) !== false) {
                $score += 15;
                $reasons[] = 'تطابق اللون (' . $lost['color'] . ')';
            }

            // 6. فحص سنة الصنع (تطابق = 10 نقاط)
            if (!empty($lost['year']) && !empty($f['year']) && abs($lost['year'] - $f['year']) <= 1) {
                $score += 10;
                $reasons[] = 'تقارب أو تطابق سنة الصنع (' . $f['year'] . ')';
            }

            if ($score >= 30) {
                $img = $db->prepare("SELECT file_path, attachment_tag FROM attachments WHERE report_type = 'found' AND report_id = ?");
                $img->execute([$f['report_id']]);
                $f['attachments'] = $img->fetchAll();
                $f['match_score'] = min(100, $score);
                $f['match_reasons'] = $reasons;
                $candidates[] = $f;
            }
        }

        // ترتيب المرشحين حسب نسبة التطابق تنازلياً
        usort($candidates, function($a, $b) {
            return $b['match_score'] <=> $a['match_score'];
        });

        jsonSuccess('نتائج محرك الفحص والمطابقة', [
            'lost_report' => $lost,
            'candidates'  => $candidates
        ]);
        break;

    // 5. اعتماد تطابق بين بلاغ فقد وبلاغ عثور
    case 'confirm_match':
        if ($method !== 'POST') {
            jsonError('طريقة الطلب غير صحيحة', 405);
        }

        $lostReportId  = (int)($_POST['lost_report_id'] ?? 0);
        $foundReportId = (int)($_POST['found_report_id'] ?? 0);

        if (!$lostReportId || !$foundReportId) {
            jsonError('يرجى تحديد رقمي بلاغ الفقد وبلاغ العثور');
        }

        $lostStmt = $db->prepare("SELECT lr.*, v.make, v.model FROM lost_reports lr JOIN vehicles v ON lr.vehicle_id = v.vehicle_id WHERE lr.report_id = ?");
        $lostStmt->execute([$lostReportId]);
        $lost = $lostStmt->fetch();

        $foundStmt = $db->prepare("SELECT * FROM found_reports WHERE report_id = ?");
        $foundStmt->execute([$foundReportId]);
        $found = $foundStmt->fetch();

        if (!$lost || !$found) {
            jsonError('أحد البلاغين غير موجود', 404);
        }

        $db->beginTransaction();
        try {
            // تحديث حالة البلاغين إلى 'يوجد تطابق محتمل'
            $db->prepare("UPDATE lost_reports SET status = 'يوجد تطابق محتمل' WHERE report_id = ?")->execute([$lostReportId]);
            $db->prepare("UPDATE found_reports SET status = 'يوجد تطابق محتمل' WHERE report_id = ?")->execute([$foundReportId]);

            // إشعار لصاحب السيارة الفاقد
            $msgOwner = "بشرى سارة: تم العثور على تطابق محتمل لمركبتك {$lost['make']} {$lost['model']} مع بلاغ عثور رقم #{$foundReportId}. يرجى مراجعة إدارة النظام لاستكمال التحقق وتنسيق التواصل.";
            $db->prepare("INSERT INTO notifications (user_id, message, is_read, related_report_type, related_report_id) VALUES (?, ?, 0, 'lost', ?)")
               ->execute([$lost['user_id'], $msgOwner, $lostReportId]);

            // إشعار للمبلّغ الذي عثر عليها
            $msgFinder = "تمت مطابقة بلاغ العثور رقم #{$foundReportId} بنجاح مع سيارة مفقودة مبلغ عنها. سيتم التنسيق معك قريباً.";
            $db->prepare("INSERT INTO notifications (user_id, message, is_read, related_report_type, related_report_id) VALUES (?, ?, 0, 'found', ?)")
               ->execute([$found['user_id'], $msgFinder, $foundReportId]);

            $db->commit();
            CacheEngine::flush();
            jsonSuccess('تم تأكيد التطابق بنجاح وتحديث البلاغات وإشعار الأطراف المعنية');
        } catch (Exception $e) {
            $db->rollBack();
            jsonError('خطأ أثناء تأكيد التطابق: ' . $e->getMessage(), 500);
        }
        break;

    // 6. إدارة المستخدمين (Class Diagram: إدارة المستخدمين())
    case 'users':
        $usersStmt = $db->query("
            SELECT 
                u.user_id,
                u.full_name,
                u.phone_number,
                u.email,
                u.role,
                u.created_at,
                (SELECT COUNT(*) FROM lost_reports WHERE user_id = u.user_id) AS lost_count,
                (SELECT COUNT(*) FROM found_reports WHERE user_id = u.user_id) AS found_count
            FROM users u
            ORDER BY u.user_id ASC
        ");
        $users = $usersStmt->fetchAll();
        jsonSuccess('قائمة المستخدمين', ['users' => $users]);
        break;

    default:
        jsonError('إجراء إدارة غير معروف', 404);
}
