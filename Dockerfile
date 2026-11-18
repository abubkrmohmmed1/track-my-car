# استخدام صورة PHP الرسمية مع خادم Apache
FROM php:8.2-apache

# تثبيت ملحقات PDO و MySQL
RUN docker-php-ext-install pdo pdo_mysql

# تفعيل وحدة التوجيه mod_rewrite
RUN a2enmod rewrite

# نسخ ملفات المشروع إلى مسار الويب الافتراضي
COPY . /var/www/html/

# ضبط أذونات المجلدات للمرفقات والجلسات والكاش
RUN mkdir -p /var/www/html/uploads /var/www/html/sessions /var/www/html/cache && \
    chown -R www-data:www-data /var/www/html/uploads /var/www/html/sessions /var/www/html/cache && \
    chmod -R 775 /var/www/html/uploads /var/www/html/sessions /var/www/html/cache

# المنفذ الافتراضي للويب
EXPOSE 80

CMD ["apache2-foreground"]
