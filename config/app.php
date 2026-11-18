<?php
/**
 * إعدادات التطبيق والدوال المساعدة العامة ونظام التخزين المؤقت (Performance & Caching)
 * Application Helpers, Session Management & File/HTTP Cache System
 */

$sessionsDir = dirname(__DIR__) . '/sessions';
if (!is_dir($sessionsDir)) {
    @mkdir($sessionsDir, 0777, true);
}
@ini_set('session.save_path', $sessionsDir);
@session_save_path($sessionsDir);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

// تفعيل ضغط الاستجابات لتقليل حجم البيانات المنقولة وتسريع التصفح
if (!ob_get_level() && !headers_sent() && extension_loaded('zlib')) {
    @ob_start('ob_gzhandler');
}

/**
 * إرسال استجابة JSON مع دعم ETag و 304 Not Modified
 */
function jsonResponse(array $data, int $statusCode = 200, ?string $etag = null): void {
    if ($etag !== null && isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        http_response_code(304);
        header('ETag: ' . $etag);
        header('Cache-Control: public, max-age=30, stale-while-revalidate=60');
        exit;
    }

    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    if ($etag !== null) {
        header('ETag: ' . $etag);
        header('Cache-Control: public, max-age=30, stale-while-revalidate=60');
    } else {
        header('Cache-Control: no-cache, must-revalidate');
    }

    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function jsonError(string $message, int $statusCode = 400, array $extra = []): void {
    jsonResponse(array_merge([
        'success' => false,
        'error'   => $message
    ], $extra), $statusCode);
}

function jsonSuccess(string $message, array $data = [], int $statusCode = 200, ?string $etag = null): void {
    jsonResponse(array_merge([
        'success' => true,
        'message' => $message
    ], $data), $statusCode, $etag);
}

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function currentUser(): ?array {
    if (!isLoggedIn()) {
        return null;
    }
    return [
        'user_id'      => $_SESSION['user_id'],
        'full_name'    => $_SESSION['full_name'] ?? '',
        'email'        => $_SESSION['email'] ?? '',
        'phone_number' => $_SESSION['phone_number'] ?? '',
        'role'         => $_SESSION['role'] ?? 'user'
    ];
}

function isAdmin(): bool {
    return isLoggedIn() && (($_SESSION['role'] ?? '') === 'admin');
}

function requireAuth(): array {
    if (!isLoggedIn()) {
        jsonError('يجب تسجيل الدخول أولاً للوصول إلى هذه الخدمة', 401);
    }
    return currentUser();
}

function requireAdmin(): array {
    $user = requireAuth();
    if ($user['role'] !== 'admin') {
        jsonError('غير مصرح لك: هذه العملية مخصصة لمدير النظام فقط', 403);
    }
    return $user;
}

function sanitizeInput(?string $input): string {
    if ($input === null) return '';
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * نظام التخزين المؤقت السريع (High-Performance Cache Engine)
 */
class CacheEngine {
    private static function getDir(): string {
        $dir = dirname(__DIR__) . '/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir;
    }

    public static function get(string $key, int $ttl = 45): ?array {
        $file = self::getDir() . '/' . md5($key) . '.cache';
        if (file_exists($file)) {
            $mtime = filemtime($file);
            if ((time() - $mtime) < $ttl) {
                $content = @file_get_contents($file);
                if ($content) {
                    $json = json_decode($content, true);
                    if (is_array($json)) {
                        return $json;
                    }
                }
            } else {
                @unlink($file);
            }
        }
        return null;
    }

    public static function set(string $key, array $data): void {
        $file = self::getDir() . '/' . md5($key) . '.cache';
        @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    public static function flush(): void {
        $files = glob(self::getDir() . '/*.cache');
        if ($files) {
            foreach ($files as $f) {
                @unlink($f);
            }
        }
    }
}

/**
 * معالجة رفع الملفات والمستندات مع تصنيف نوع الصورة/المرفق
 */
function handleFileUpload(array $file, string $reportType, int $reportId, string $tag = 'عامة'): ?array {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf', 'webp'];
    $fileInfo = pathinfo($file['name']);
    $extension = strtolower($fileInfo['extension'] ?? '');

    if (!in_array($extension, $allowedExtensions)) {
        return null;
    }

    $uploadDir = dirname(__DIR__) . '/uploads/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }

    $newFileName = 'att_' . $reportType . '_' . $reportId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
    $targetPath = $uploadDir . $newFileName;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO attachments (report_type, report_id, file_name, file_type, file_path, attachment_tag)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $reportType,
            $reportId,
            $file['name'],
            $file['type'] ?? $extension,
            'uploads/' . $newFileName,
            $tag
        ]);
        
        // تفريغ الكاش لتحديث نتائج البحث
        CacheEngine::flush();

        return [
            'file_name'      => $file['name'],
            'file_path'      => 'uploads/' . $newFileName,
            'attachment_tag' => $tag
        ];
    }

    return null;
}
