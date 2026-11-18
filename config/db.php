<?php
/**
 * نظام Track My Car (TMC)
 * ملف الاتصال بقاعدة البيانات (PDO Database Connection)
 */

define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'tmc_db');
define('DB_USER', 'root');
define('DB_PASS', '');

class Database {
    private static ?PDO $instance = null;
    private static string $driverUsed = '';

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            // المحاولة الأولى: الاتصال بقاعدة بيانات MySQL المعتمدة في النظام
            try {
                $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
                $options = [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ];
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
                self::$driverUsed = 'mysql';
            } catch (PDOException $e) {
                // إذا لم تكن قاعدة بيانات MySQL منشأة بعد، نجرب الاتصال بدون تحديد اسم القاعدة لإنشائها
                try {
                    $dsnRoot = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=utf8mb4";
                    $pdoRoot = new PDO($dsnRoot, DB_USER, DB_PASS);
                    $pdoRoot->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
                    self::$instance = new PDO("mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    ]);
                    self::$driverUsed = 'mysql';
                } catch (PDOException $e2) {
                    // في حال كان خادم MySQL متوقفاً محلياً، نستخدم SQLite محلياً لضمان عمل التطبيق والتجربة فوراً دون توقف
                    $sqlitePath = dirname(__DIR__) . '/database/tmc.sqlite';
                    self::$instance = new PDO("sqlite:" . $sqlitePath, null, null, [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    ]);
                    self::$driverUsed = 'sqlite';
                    self::initSqliteTables(self::$instance);
                }
            }
        }
        return self::$instance;
    }

    public static function getDriver(): string {
        return self::$driverUsed;
    }

    private static function initSqliteTables(PDO $db): void {
        // إنشاء الجداول تلقائياً لـ SQLite عند أول تشغيل
        $sql = "
        CREATE TABLE IF NOT EXISTS users (
            user_id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name TEXT NOT NULL,
            phone_number TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT DEFAULT 'user',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS vehicles (
            vehicle_id INTEGER PRIMARY KEY AUTOINCREMENT,
            type TEXT NOT NULL,
            make TEXT NOT NULL,
            model TEXT NOT NULL,
            year INTEGER NOT NULL,
            color TEXT NOT NULL,
            plate_number TEXT NULL,
            chassis_number TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS lost_reports (
            report_id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            vehicle_id INTEGER NOT NULL,
            last_seen_location TEXT NOT NULL,
            loss_date DATE NOT NULL,
            description TEXT NULL,
            status TEXT DEFAULT 'قيد المراجعة',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
            FOREIGN KEY (vehicle_id) REFERENCES vehicles(vehicle_id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS found_reports (
            report_id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            vehicle_id INTEGER NULL,
            found_location TEXT NOT NULL,
            found_date DATE NOT NULL,
            description TEXT NULL,
            status TEXT DEFAULT 'قيد المراجعة',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
            FOREIGN KEY (vehicle_id) REFERENCES vehicles(vehicle_id) ON DELETE SET NULL
        );

        CREATE TABLE IF NOT EXISTS attachments (
            attachment_id INTEGER PRIMARY KEY AUTOINCREMENT,
            report_type TEXT NOT NULL,
            report_id INTEGER NOT NULL,
            file_name TEXT NOT NULL,
            file_type TEXT NOT NULL,
            file_path TEXT NOT NULL,
            attachment_tag TEXT DEFAULT 'عامة',
            upload_date DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS notifications (
            notification_id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            message TEXT NOT NULL,
            send_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            is_read INTEGER DEFAULT 0,
            related_report_type TEXT NULL,
            related_report_id INTEGER NULL,
            FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS location_access_requests (
            request_id INTEGER PRIMARY KEY AUTOINCREMENT,
            lost_report_id INTEGER NOT NULL,
            found_report_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            status TEXT DEFAULT 'معلق',
            payment_status TEXT DEFAULT 'تم الدفع',
            admin_notes TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
        );
        ";
        $db->exec($sql);

        // إدراج بيانات تجريبية إذا كان الجدول فارغاً
        $count = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($count == 0) {
            $defaultHash = password_hash('password123', PASSWORD_BCRYPT);
            $db->exec("
                INSERT INTO users (user_id, full_name, phone_number, email, password_hash, role) VALUES
                (1, 'مدير النظام (Admin)', '0912345678', 'admin@tmc.sd', '$defaultHash', 'admin'),
                (2, 'محمد أحمد عبد الله', '0922223344', 'mohammed@example.com', '$defaultHash', 'user'),
                (3, 'عثمان علي إبراهيم', '0955556677', 'osman@example.com', '$defaultHash', 'user');

                INSERT INTO vehicles (vehicle_id, type, make, model, year, color, plate_number) VALUES
                (1, 'صالون', 'تويوتا', 'كورولا', 2018, 'أبيض', 'خ 2 - 45890'),
                (2, 'دفع رباعي', 'هيونداي', 'توسان', 2021, 'رمادي', 'خ 7 - 12345'),
                (3, 'صالون', 'تويوتا', 'كورولا', 2018, 'أبيض', 'خ 2 - 45890');

                INSERT INTO lost_reports (report_id, user_id, vehicle_id, last_seen_location, loss_date, description, status) VALUES
                (1, 2, 1, 'الخرطوم - شارع الستين بالقرب من تقاطع الرياض', '2026-10-15', 'تويوتا كورولا بيضاء يوجد كسر بسيط في المرآة الجانبية اليمنى وعليها تظليل خفيف', 'يوجد تطابق محتمل');

                INSERT INTO found_reports (report_id, user_id, vehicle_id, found_location, found_date, description, status) VALUES
                (1, 3, 3, 'أم درمان - منطقة الثورة الحارة التاسعة بالقرب من المسجد الكبير', '2026-10-20', 'سيارة متوقفة منذ عدة أيام بدون حركة، تطابق مواصفات الكورولا البيضاء وتحمل نفس اللوحة', 'يوجد تطابق محتمل');

                INSERT INTO notifications (user_id, message, is_read, related_report_type, related_report_id) VALUES
                (2, 'تم العثور على تطابق محتمل للبلاغ رقم #1 الخاص بمركبتك تويوتا كورولا. يرجى مراجعة إدارة النظام لتنسيق الإجراءات.', 0, 'lost', 1);
            ");
        }
    }
}
