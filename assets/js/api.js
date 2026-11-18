/**
 * مكتبة التواصل مع واجهة البرمجة (TMC API Client) مع نظام التخزين المؤقت العميل والـ Debouncing
 */

const API = {
    // كاش محلي في الذاكرة لتسريع استجابة البحث الفوري
    _searchCache: new Map(),

    // دالة Debounce لمنع إرسال طلبات متكررة أثناء الكتابة
    debounce(func, wait = 300) {
        let timeout;
        return function (...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), wait);
        };
    },

    // التحقق من الجلسة والمستخدم الحالي
    async getCurrentUser() {
        try {
            const res = await fetch('/api/auth.php?action=me');
            const data = await res.json();
            return data.success ? data.user : null;
        } catch (e) {
            return null;
        }
    },

    // تسجيل الدخول
    async login(email, password) {
        const res = await fetch('/api/auth.php?action=login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, password })
        });
        return await res.json();
    },

    // إنشاء حساب جديد
    async register(fullName, phoneNumber, email, password) {
        const res = await fetch('/api/auth.php?action=register', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                full_name: fullName,
                phone_number: phoneNumber,
                email: email,
                password: password
            })
        });
        return await res.json();
    },

    // تسجيل الخروج
    async logout() {
        const res = await fetch('/api/auth.php?action=logout', { method: 'POST' });
        const data = await res.json();
        if (data.success) {
            window.location.href = '/pages/login.html';
        }
    },

    // جلب الإشعارات
    async getNotifications() {
        try {
            const res = await fetch('/api/notifications.php');
            return await res.json();
        } catch (e) {
            return { success: false, notifications: [], unread_count: 0 };
        }
    },

    // تعليم الإشعارات كمقروءة
    async markNotificationsAsRead() {
        const formData = new FormData();
        formData.append('mark_all', '1');
        const res = await fetch('/api/notifications.php', {
            method: 'POST',
            body: formData
        });
        return await res.json();
    },

    // البحث السريع في المركبات مع التخزين المؤقت المحلي (Memory Caching)
    async searchVehicles(params = {}, bypassCache = false) {
        const query = new URLSearchParams(params).toString();
        const cacheKey = 'search_' + query;

        // فحص الكاش المحلي العميل
        if (!bypassCache && this._searchCache.has(cacheKey)) {
            const cached = this._searchCache.get(cacheKey);
            // إذا كان الكاش أقل من 30 ثانية، أرجعه فوراً (0ms)
            if (Date.now() - cached.timestamp < 30000) {
                return cached.data;
            }
        }

        try {
            const res = await fetch(`/api/search.php?${query}`);
            const data = await res.json();
            if (data.success) {
                this._searchCache.set(cacheKey, {
                    timestamp: Date.now(),
                    data: data
                });
            }
            return data;
        } catch (e) {
            return { success: false, results: [], count: 0, error: 'تعذر الاتصال بالخادم' };
        }
    },

    // تفريغ كاش البحث عند إضافة بلاغ جديد
    clearSearchCache() {
        this._searchCache.clear();
    },

    // تقديم بلاغ فقدان
    async submitLostReport(formData) {
        const res = await fetch('/api/lost_reports.php', {
            method: 'POST',
            body: formData
        });
        this.clearSearchCache();
        return await res.json();
    },

    // تقديم بلاغ عثور
    async submitFoundReport(formData) {
        const res = await fetch('/api/found_reports.php', {
            method: 'POST',
            body: formData
        });
        this.clearSearchCache();
        return await res.json();
    },

    // تحديث شريط التنقل بناءً على حالة تسجيل الدخول
    async updateNavbar() {
        const user = await this.getCurrentUser();
        const navActions = document.getElementById('nav-actions');
        const navLinks = document.getElementById('nav-links');
        if (!navActions) return;

        if (user) {
            // إضافة رابط لوحة الإدارة للمدير فقط
            if (user.role === 'admin' && navLinks && !document.getElementById('nav-admin-link')) {
                const adminLi = document.createElement('li');
                adminLi.id = 'nav-admin-link';
                adminLi.innerHTML = `<a href="/pages/admin.html" class="nav-admin-btn">⚙️ لوحة الإدارة</a>`;
                navLinks.appendChild(adminLi);
            }

            // جلب الإشعارات غير المقروءة
            let notifBadge = '';
            try {
                const notifData = await this.getNotifications();
                if (notifData.unread_count > 0) {
                    notifBadge = `<span class="badge badge-lost pulse" style="font-size:0.75rem; padding:2px 7px; margin-right:6px;">${notifData.unread_count}</span>`;
                }
            } catch (err) {}

            navActions.innerHTML = `
                <a href="/pages/my_reports.html" class="btn btn-outline btn-sm">
                    👤 ${user.full_name} ${notifBadge}
                </a>
                <button onclick="API.logout()" class="btn btn-outline btn-sm" title="تسجيل الخروج">خروج</button>
            `;
        } else {
            navActions.innerHTML = `
                <a href="/pages/login.html" class="btn btn-outline btn-sm">تسجيل الدخول</a>
                <a href="/pages/register.html" class="btn btn-primary btn-sm">حساب جديد</a>
            `;
        }
    }
};

document.addEventListener('DOMContentLoaded', () => {
    API.updateNavbar();
});
