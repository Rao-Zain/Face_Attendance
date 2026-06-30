FROM php:8.2-apache

# Install PHP extensions needed for MySQL (PDO)
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Enable Apache mod_rewrite (often needed for clean URLs)
RUN a2enmod rewrite

# Set working directory to Apache's web root
WORKDIR /var/www/html

# Copy all project files into the container
COPY . .

# Install Composer dependencies inside the container
RUN composer install --no-dev --optimize-autoloader

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html

# Apache listens on port 80 by default, but Render needs a specific port
ENV PORT=10000
RUN sed -i 's/80/${PORT}/g' /etc/apache2/ports.conf /etc/apache2/sites-enabled/000-default.conf

EXPOSE 10000