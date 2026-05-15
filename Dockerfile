# ============================================
# DOCKERFILE — KTCM E-Vote on Railway
# PHP 8.2 + Apache
# ============================================

FROM php:8.2-apache

# Enable Apache mod_rewrite for .htaccess
RUN a2enmod rewrite headers

# Install MySQL extension
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Set working directory
WORKDIR /var/www/html

# Copy all project files
COPY . /var/www/html/

# Set correct permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Allow .htaccess overrides
RUN echo '<Directory /var/www/html>\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/ktcm.conf \
    && a2enconf ktcm

# Expose port 80
EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]
