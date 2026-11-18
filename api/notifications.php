<?php
/**
 * نقطة نهاية إدارة الإشعارات (Notifications API)
 * مطابقة لمخطط الفئات ومخطط النشاط
 */

require_once __DIR__ . '/../config/app.php';

header('Content-Type: application/json; charset=utf-8');

$currentUser = requireAuth();
$db = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $db->prepare("
        SELECT notification_id, message, send_date, is_read, related_report_type, related_report_id
        FROM notifications
        WHERE user_id = ?
        ORDER BY notification_id DESC
    ");
    $stmt->execute([$currentUser['user_id']]);
    $notifications = $stmt->fetchAll();

    $unreadCount = 0;
    foreach ($notifications as $n) {
        if (!$n['is_read']) {
            $unreadCount++;
        }
    }

    jsonSuccess('قائمة الإشعارات', [
        'unread_count'  => $unreadCount,
        'notifications' => $notifications
    ]);
}

if ($method === 'POST') {
    $notifId = (int)($_POST['notification_id'] ?? 0);
    $markAll = isset($_POST['mark_all']) && $_POST['mark_all'] == '1';

    if ($markAll) {
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $stmt->execute([$currentUser['user_id']]);
        jsonSuccess('تم تعيين جميع الإشعارات كمقروءة');
    } elseif ($notifId > 0) {
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?");
        $stmt->execute([$notifId, $currentUser['user_id']]);
        jsonSuccess('تم تحديث حالة الإشعار');
    } else {
        jsonError('بيانات غير صحيحة');
    }
}
