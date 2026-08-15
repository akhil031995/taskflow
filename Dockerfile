# TaskFlow web image - official PHP 8.4 + Apache (serves on port 80, like the
# rest of the homeserver-net stack, so the Caddy reverse proxy reaches it).
FROM php:8.4-apache

# PDO MySQL driver (config/db.php) + mysql client (backup/restore convenience).
RUN apt-get update \
    && apt-get install -y --no-install-recommends default-mysql-client \
    && docker-php-ext-install pdo pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

# Clean URLs + let our .htaccess (deny .sql/.md, DirectoryIndex) take effect.
RUN a2enmod rewrite \
    && printf '<Directory /var/www/html>\n    AllowOverride All\n    Require all granted\n</Directory>\n' \
        > /etc/apache2/conf-available/taskflow.conf \
    && a2enconf taskflow

# Entrypoint runs DB migrations (creating the database if missing) on every
# container start, then hands off to Apache on port 80.
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Apache serves /var/www/html, which docker-compose mounts from ./src.
WORKDIR /var/www/html

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
