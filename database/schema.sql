-- ============================================================
-- نظام Track My Car (TMC) - قاعدة البيانات المعتمدة
-- مطابق لمخطط الفئات (Class Diagram) ومتطلبات الفصل الثالث
-- ============================================================

CREATE DATABASE IF NOT EXISTS `tmc_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `tmc_db`;

-- 1. جدول المستخدمين (المستخدم / مدير النظام)
CREATE TABLE IF NOT EXISTS `users` (
    `user_id` INT AUTO_INCREMENT PRIMARY KEY,
    `full_name` VARCHAR(150) NOT NULL,
    `phone_number` VARCHAR(50) NOT NULL,
    `email` VARCHAR(150) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('user', 'admin') DEFAULT 'user',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. جدول المركبات
CREATE TABLE IF NOT EXISTS `vehicles` (
    `vehicle_id` INT AUTO_INCREMENT PRIMARY KEY,
    `type` VARCHAR(100) NOT NULL,           -- صالون، بوكس، دفع رباعي، حافلة، إلخ
    `make` VARCHAR(100) NOT NULL,           -- الشركة المصنعة: تويوتا، هيونداي، كيا، إلخ
    `model` VARCHAR(100) NOT NULL,          -- الطراز: كورولا، أكسنت، توسان، لاندكروزر
    `year` INT NOT NULL,                    -- سنة الصنع
    `color` VARCHAR(50) NOT NULL,           -- اللون الأساسي
    `plate_number` VARCHAR(50) NULL,        -- رقم اللوحة (قد يكون غير معروف في بلاغات العثور)
    `chassis_number` VARCHAR(100) NULL,     -- رقم الشاسيه / الهيكل (هام جداً في حال نزع اللوحات)
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_chassis` (`chassis_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. جدول بلاغات الفقدان
CREATE TABLE IF NOT EXISTS `lost_reports` (
    `report_id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `vehicle_id` INT NOT NULL,
    `last_seen_location` VARCHAR(255) NOT NULL,  -- آخر موقع شوهدت فيه المركبة
    `loss_date` DATE NOT NULL,                   -- تاريخ الفقدان
    `description` TEXT NULL,                     -- وصف تفصيلي وعلامات مميزة
    `status` VARCHAR(50) NOT NULL DEFAULT 'قيد المراجعة', -- قيد المراجعة، منشور، يوجد تطابق محتمل، يتطلب استكمال البيانات، مغلق
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE,
    FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`vehicle_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. جدول بلاغات العثور
CREATE TABLE IF NOT EXISTS `found_reports` (
    `report_id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `vehicle_id` INT NULL,                       -- المركبة المرتبطة بالوصف
    `found_location` VARCHAR(255) NOT NULL,      -- موقع العثور (المدينة / الحي / المنطقة)
    `found_date` DATE NOT NULL,                  -- تاريخ العثور
    `description` TEXT NULL,                     -- وصف الحالة التي عثر عليها
    `status` VARCHAR(50) NOT NULL DEFAULT 'قيد المراجعة',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE,
    FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`vehicle_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. جدول المرفقات (الصور والمستندات)
CREATE TABLE IF NOT EXISTS `attachments` (
    `attachment_id` INT AUTO_INCREMENT PRIMARY KEY,
    `report_type` ENUM('lost', 'found') NOT NULL,
    `report_id` INT NOT NULL,
    `file_name` VARCHAR(255) NOT NULL,
    `file_type` VARCHAR(100) NOT NULL,
    `file_path` VARCHAR(255) NOT NULL,
    `attachment_tag` VARCHAR(50) DEFAULT 'عامة', -- 'صورة عامة للمركبة', 'صورة اللوحة', 'صورة رقم الشاسيه', 'مستند إثبات'
    `upload_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_report` (`report_type`, `report_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. جدول الإشعارات
CREATE TABLE IF NOT EXISTS `notifications` (
    `notification_id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `message` TEXT NOT NULL,
    `send_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `is_read` TINYINT(1) DEFAULT 0,
    `related_report_type` VARCHAR(20) NULL,
    `related_report_id` INT NULL,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. جدول طلبات التحقق وعرض الموقع (المذكور في وثيقة التحليل 3-16)
CREATE TABLE IF NOT EXISTS `location_access_requests` (
    `request_id` INT AUTO_INCREMENT PRIMARY KEY,
    `lost_report_id` INT NOT NULL,
    `found_report_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `status` ENUM('معلق', 'مكتمل', 'مرفوض') DEFAULT 'معلق',
    `payment_status` ENUM('معلق', 'تم الدفع', 'معفى') DEFAULT 'تم الدفع',
    `admin_notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- بيانات أولية وتجريبية (Initial Seed Data)
-- كلمة المرور الافتراضية للمدير والمستخدمين: password123
-- الهاش: $2y$10$89L7yF3u3/eA1e52f5hSqe2gW4Fh901b8Lphb.gD.1h60n5Uj7XmO
-- ============================================================

INSERT INTO `users` (`user_id`, `full_name`, `phone_number`, `email`, `password_hash`, `role`) VALUES
(1, 'مدير النظام (Admin)', '0912345678', 'admin@tmc.sd', '$2y$10$wW5gQ76Bw1Z3z15qZ1L68ecQ8/0Uo0W9c1.pYx8U7L36L01N4e4G2', 'admin'),
(2, 'محمد أحمد عبد الله', '0922223344', 'mohammed@example.com', '$2y$10$wW5gQ76Bw1Z3z15qZ1L68ecQ8/0Uo0W9c1.pYx8U7L36L01N4e4G2', 'user'),
(3, 'عثمان علي إبراهيم', '0955556677', 'osman@example.com', '$2y$10$wW5gQ76Bw1Z3z15qZ1L68ecQ8/0Uo0W9c1.pYx8U7L36L01N4e4G2', 'user');

INSERT INTO `vehicles` (`vehicle_id`, `type`, `make`, `model`, `year`, `color`, `plate_number`, `chassis_number`) VALUES
(1, 'صالون', 'تويوتا', 'كورولا', 2018, 'أبيض', 'خ 2 - 45890', 'JT2AE20V8J0123456'),
(2, 'دفع رباعي', 'هيونداي', 'توسان', 2021, 'رمادي', 'خ 7 - 12345', 'KMHJ381EPNU654321'),
(3, 'صالون', 'تويوتا', 'كورولا', 2018, 'أبيض', 'خ 2 - 45890', 'JT2AE20V8J0123456'); -- سيارة عثر عليها مطابقة للبلاغ الأول

INSERT INTO `lost_reports` (`report_id`, `user_id`, `vehicle_id`, `last_seen_location`, `loss_date`, `description`, `status`) VALUES
(1, 2, 1, 'الخرطوم - شارع الستين بالقرب من تقاطع الرياض', '2026-10-15', 'تويوتا كورولا بيضاء يوجد كسر بسيط في المرآة الجانبية اليمنى وعليها تظليل خفيف', 'يوجد تطابق محتمل');

INSERT INTO `found_reports` (`report_id`, `user_id`, `vehicle_id`, `found_location`, `found_date`, `description`, `status`) VALUES
(1, 3, 3, 'أم درمان - منطقة الثورة الحارة التاسعة بالقرب من المسجد الكبير', '2026-10-20', 'سيارة متوقفة منذ عدة أيام بدون حركة، تطابق مواصفات الكورولا البيضاء وتحمل نفس اللوحة', 'يوجد تطابق محتمل');

INSERT INTO `notifications` (`user_id`, `message`, `is_read`, `related_report_type`, `related_report_id`) VALUES
(2, 'تم العثور على تطابق محتمل للبلاغ رقم #1 الخاص بمركبتك تويوتا كورولا. يرجى مراجعة إدارة النظام لتنسيق الإجراءات.', 0, 'lost', 1);
