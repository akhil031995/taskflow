# TaskFlow web image: PHP 8.2 + Apache.
FROM php:8.2-apache

# Install the PDO MySQL driver (used by src/config/db.php) and the mysql client
# (used by the 1-click backup endpoint as an optional fast path).
RUN apt-get update \
    && apt-get install -y --no-install-recommends default-mysql-client \
    && docker-php-ext-install pdo pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

# Clean URLs / .htaccess support.
RUN a2enmod rewrite

# Entrypoint runs DB migrations (creating the database if missing) on every
# container start, then hands off to Apache.
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Apache serves /var/www/html, which docker-compose mounts from ./src.
WORKDIR /var/www/html

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
