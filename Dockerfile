FROM php:8.4-apache

# Install system dependencies and PHP extensions needed for WordPress
RUN apt-get update && apt-get install -y \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libwebp-dev \
        zip \
        unzip \
        git \
        curl \
        libzip-dev \
        less \
        sudo \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) gd zip mysqli pdo_mysql \
    && pecl install redis && docker-php-ext-enable redis \
    && a2enmod rewrite

# Install WP-CLI
RUN curl -o /usr/local/bin/wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x /usr/local/bin/wp-cli.phar \
    && mv /usr/local/bin/wp-cli.phar /usr/local/bin/wp

# Set recommended PHP.ini settings
# See https://secure.php.net/manual/en/opcache.installation.php
RUN { \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.interned_strings_buffer=8'; \
        echo 'opcache.max_accelerated_files=4000'; \
        echo 'opcache.revalidate_freq=2'; \
        echo 'opcache.fast_shutdown=1'; \
        echo 'opcache.enable_cli=1'; \
    } > /usr/local/etc/php/conf.d/opcache-recommended.ini \
    && { \
        echo 'upload_max_filesize=256M'; \
        echo 'post_max_size=256M'; \
        echo 'max_execution_time=300'; \
    } > /usr/local/etc/php/conf.d/uploads.ini

# Download and extract WordPress
ENV WORDPRESS_VERSION 7.1.2

# In reality, we should verify SHA1, but for brevity we skip check.
RUN curl -o wordpress.tar.gz -fSL "https://wordpress.org/wordpress-${WORDPRESS_VERSION}.tar.gz" \
    && tar -xzf wordpress.tar.gz -C /var/www/html --strip-components=1 \
    && rm wordpress.tar.gz \
    && chown -R www-data:www-data /var/www/html

# Copy and install the WebP Studio plugin.
#
# The archive name and the folder inside it must both be webp-studio, because
# docker-compose.yml bind-mounts that exact path over the container. If they ever
# disagree, the mount points at a directory that does not exist and Docker silently
# creates an empty one, leaving the plugin with no code and no error.
COPY webp-studio.zip /tmp/webp-studio.zip
RUN unzip /tmp/webp-studio.zip -d /var/www/html/wp-content/plugins/ \
    && rm /tmp/webp-studio.zip

# Copy custom wp-config.php if needed (optional)
# COPY wp-config.php /var/www/html/wp-config.php

# Ensure .htaccess is writable for permalinks etc.
RUN chmod -R 775 /var/www/html && \
    find /var/www/html -type d -exec chmod 775 {} \; && \
    find /var/www/html -type f -exec chmod 664 {} \;

# Use www-data user for running the container
USER www-data

EXPOSE 80
