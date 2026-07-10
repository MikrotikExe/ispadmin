FROM php:8.3-apache

# DB rozsirenia: pdo_sqlite (default) + pdo_mysql (volitelne)
RUN apt-get update \
 && apt-get install -y --no-install-recommends libsqlite3-dev \
 && docker-php-ext-install pdo_mysql pdo_sqlite ftp \
 && a2enmod rewrite \
 && rm -rf /var/lib/apt/lists/*

# DocumentRoot smeruje na public/ (config.php a lib/ ostanu mimo webu)
COPY docker/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY docker/ports.conf /etc/apache2/ports.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
