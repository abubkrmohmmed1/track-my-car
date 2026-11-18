<?php
/**
 * سكريبت تحسين أداء قاعدة البيانات وإنشاء الفهارس (Performance & Indexing Optimization)
 */

require_once __DIR__ . '/../config/app.php';

$db = Database::getConnection();
$driver = Database::getDriver();

echo "جارٍ تحسين أداء وفهارس قاعدة البيانات ($driver)...\n";

$indexes = [
    "CREATE INDEX IF NOT EXISTS idx_v_plate ON vehicles (plate_number)",
    "CREATE INDEX IF NOT EXISTS idx_v_chassis ON vehicles (chassis_number)",
    "CREATE INDEX IF NOT EXISTS idx_v_make_model ON vehicles (make, model)",
    "CREATE INDEX IF NOT EXISTS idx_v_color ON vehicles (color)",
    "CREATE INDEX IF NOT EXISTS idx_lr_status ON lost_reports (status)",
    "CREATE INDEX IF NOT EXISTS idx_fr_status ON found_reports (status)",
    "CREATE INDEX IF NOT EXISTS idx_lr_user ON lost_reports (user_id)",
    "CREATE INDEX IF NOT EXISTS idx_fr_user ON found_reports (user_id)",
    "CREATE INDEX IF NOT EXISTS idx_att_report ON attachments (report_type, report_id)",
    "CREATE INDEX IF NOT EXISTS idx_notif_user_read ON notifications (user_id, is_read)"
];

foreach ($indexes as $sql) {
    try {
        $db->exec($sql);
        echo "[✓] تم تفعيل الفهرس: " . preg_replace('/CREATE INDEX IF NOT EXISTS (\w+).*/', '$1', $sql) . "\n";
    } catch (Exception $e) {
        echo "[i] ملاحظة: " . $e->getMessage() . "\n";
    }
}

// إنشاء مجلد الكاش السريع
$cacheDir = dirname(__DIR__) . '/cache';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0777, true);
    echo "[✓] تم إنشاء مجلد الكاش السريع (`/cache`).\n";
}

echo "\n[✓] اكتملت عمليات تسريع وتحسين قاعدة البيانات بنجاح!\n";
