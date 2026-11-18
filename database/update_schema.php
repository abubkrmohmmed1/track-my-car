<?php
/**
 * سكريبت تحديث بنية الجداول لإضافة رقم الشاسيه وتصنيف الصور
 */

require_once __DIR__ . '/../config/app.php';

$db = Database::getConnection();
$driver = Database::getDriver();

echo "جارٍ تحديث قاعدة البيانات ($driver)...\n";

try {
    $db->exec("ALTER TABLE vehicles ADD COLUMN chassis_number VARCHAR(100) NULL");
    echo "[✓] تم إضافة عمود `chassis_number` في جدول `vehicles` بنجاح.\n";
} catch (Exception $e) {
    echo "[i] عمود `chassis_number` موجود مسبقاً أو حدث تجاوز: " . $e->getMessage() . "\n";
}

try {
    $db->exec("ALTER TABLE attachments ADD COLUMN attachment_tag VARCHAR(50) DEFAULT 'عامة'");
    echo "[✓] تم إضافة عمود `attachment_tag` في جدول `attachments` بنجاح.\n";
} catch (Exception $e) {
    echo "[i] عمود `attachment_tag` موجود مسبقاً أو حدث تجاوز: " . $e->getMessage() . "\n";
}

// تحديث بيانات تجريبية لرقم الشاسيه للمركبات الموجودة
$db->exec("UPDATE vehicles SET chassis_number = 'JT2AE20V8J0123456' WHERE vehicle_id IN (1, 3)");
$db->exec("UPDATE vehicles SET chassis_number = 'KMHJ381EPNU654321' WHERE vehicle_id = 2");

echo "[✓] اكتمل التحديث بنجاح!\n";
