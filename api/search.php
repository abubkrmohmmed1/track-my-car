<?php
/**
 * محرك البحث فائق السرعة مع دعم التخزين المؤقت و ETag (Cached Fast Vehicle Search API)
 */

require_once __DIR__ . '/../config/app.php';

$q             = sanitizeInput($_GET['q'] ?? '');
$plateNumber   = sanitizeInput($_GET['plate_number'] ?? '');
$chassisNumber = sanitizeInput($_GET['chassis_number'] ?? '');
$make          = sanitizeInput($_GET['make'] ?? '');
$model         = sanitizeInput($_GET['model'] ?? '');
$color         = sanitizeInput($_GET['color'] ?? '');
$reportType    = sanitizeInput($_GET['type'] ?? 'all'); // 'all', 'lost', 'found'

// توليد مفتاح الكاش للطلب
$cacheKey = 'search_' . md5(serialize([$q, $plateNumber, $chassisNumber, $make, $model, $color, $reportType]));

// التحقق من وجود النتائج في الكاش السريع
$cachedData = CacheEngine::get($cacheKey, 60);
if ($cachedData !== null) {
    $etag = md5(serialize($cachedData));
    jsonSuccess('نتائج البحث (من الكاش السريع)', $cachedData, 200, $etag);
}

$db = Database::getConnection();
$results = [];

// 1. البحث في بلاغات الفقدان
if ($reportType === 'all' || $reportType === 'lost') {
    $sqlLost = "
        SELECT 
            'lost' AS report_type,
            lr.report_id,
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
            v.chassis_number
        FROM lost_reports lr
        JOIN vehicles v ON lr.vehicle_id = v.vehicle_id
        WHERE lr.status IN ('منشور', 'يوجد تطابق محتمل')
    ";
    $paramsLost = [];

    if (!empty($plateNumber)) {
        $sqlLost .= " AND v.plate_number LIKE ?";
        $paramsLost[] = "%{$plateNumber}%";
    }
    if (!empty($chassisNumber)) {
        $sqlLost .= " AND v.chassis_number LIKE ?";
        $paramsLost[] = "%{$chassisNumber}%";
    }
    if (!empty($make)) {
        $sqlLost .= " AND v.make LIKE ?";
        $paramsLost[] = "%{$make}%";
    }
    if (!empty($model)) {
        $sqlLost .= " AND v.model LIKE ?";
        $paramsLost[] = "%{$model}%";
    }
    if (!empty($color)) {
        $sqlLost .= " AND v.color LIKE ?";
        $paramsLost[] = "%{$color}%";
    }
    if (!empty($q)) {
        $sqlLost .= " AND (v.plate_number LIKE ? OR v.chassis_number LIKE ? OR v.make LIKE ? OR v.model LIKE ? OR v.color LIKE ? OR lr.description LIKE ? OR lr.last_seen_location LIKE ?)";
        $likeQ = "%{$q}%";
        array_push($paramsLost, $likeQ, $likeQ, $likeQ, $likeQ, $likeQ, $likeQ, $likeQ);
    }

    $stmtLost = $db->prepare($sqlLost . " ORDER BY lr.report_id DESC LIMIT 40");
    $stmtLost->execute($paramsLost);
    $lostRows = $stmtLost->fetchAll();

    foreach ($lostRows as &$r) {
        $img = $db->prepare("SELECT file_path, attachment_tag FROM attachments WHERE report_type = 'lost' AND report_id = ? LIMIT 1");
        $img->execute([$r['report_id']]);
        $row = $img->fetch();
        $r['thumbnail'] = $row ? $row['file_path'] : null;
        $r['thumbnail_tag'] = $row ? $row['attachment_tag'] : null;
        $results[] = $r;
    }
}

// 2. البحث في بلاغات العثور
if ($reportType === 'all' || $reportType === 'found') {
    $sqlFound = "
        SELECT 
            'found' AS report_type,
            fr.report_id,
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
            v.chassis_number
        FROM found_reports fr
        LEFT JOIN vehicles v ON fr.vehicle_id = v.vehicle_id
        WHERE fr.status IN ('منشور', 'يوجد تطابق محتمل')
    ";
    $paramsFound = [];

    if (!empty($plateNumber)) {
        $sqlFound .= " AND v.plate_number LIKE ?";
        $paramsFound[] = "%{$plateNumber}%";
    }
    if (!empty($chassisNumber)) {
        $sqlFound .= " AND v.chassis_number LIKE ?";
        $paramsFound[] = "%{$chassisNumber}%";
    }
    if (!empty($make)) {
        $sqlFound .= " AND v.make LIKE ?";
        $paramsFound[] = "%{$make}%";
    }
    if (!empty($model)) {
        $sqlFound .= " AND v.model LIKE ?";
        $paramsFound[] = "%{$model}%";
    }
    if (!empty($color)) {
        $sqlFound .= " AND v.color LIKE ?";
        $paramsFound[] = "%{$color}%";
    }
    if (!empty($q)) {
        $sqlFound .= " AND (v.plate_number LIKE ? OR v.chassis_number LIKE ? OR v.make LIKE ? OR v.model LIKE ? OR v.color LIKE ? OR fr.description LIKE ? OR fr.found_location LIKE ?)";
        $likeQ = "%{$q}%";
        array_push($paramsFound, $likeQ, $likeQ, $likeQ, $likeQ, $likeQ, $likeQ, $likeQ);
    }

    $stmtFound = $db->prepare($sqlFound . " ORDER BY fr.report_id DESC LIMIT 40");
    $stmtFound->execute($paramsFound);
    $foundRows = $stmtFound->fetchAll();

    foreach ($foundRows as &$r) {
        $img = $db->prepare("SELECT file_path, attachment_tag FROM attachments WHERE report_type = 'found' AND report_id = ? LIMIT 1");
        $img->execute([$r['report_id']]);
        $row = $img->fetch();
        $r['thumbnail'] = $row ? $row['file_path'] : null;
        $r['thumbnail_tag'] = $row ? $row['attachment_tag'] : null;
        $results[] = $r;
    }
}

$responsePayload = [
    'count'   => count($results),
    'results' => $results
];

// حفظ في الكاش السريع
CacheEngine::set($cacheKey, $responsePayload);
$etag = md5(serialize($responsePayload));

jsonSuccess('نتائج البحث', $responsePayload, 200, $etag);
