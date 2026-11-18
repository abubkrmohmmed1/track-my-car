# استخدام صورة PHP الرسمية مع خادم Apache
FROM php:8.2-apache

# تثبيت ملحقات PDO و MySQL
RUN docker-php-ext-install pdo pdo_mysql

# تفعيل وحدة التوجيه mod_rewrite
RUN a2enmod rewrite

# تفعيل AllowOverride لدعم التوجيه
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# نسخ ملفات المشروع إلى مسار الويب الافتراضي
COPY . /var/www/html/

# ضبط أذونات المجلدات للمرفقات والجلسات والكاش وقاعدة البيانات
RUN mkdir -p /var/www/html/uploads /var/www/html/sessions /var/www/html/cache /var/www/html/database && \
    chown -R www-data:www-data /var/www/html/uploads /var/www/html/sessions /var/www/html/cache /var/www/html/database && \
    chmod -R 775 /var/www/html/uploads /var/www/html/sessions /var/www/html/cache /var/www/html/database

# المنفذ الافتراضي للويب
EXPOSE 80

CMD ["apache2-foreground"]
