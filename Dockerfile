FROM php:8.2-apache

RUN docker-php-ext-install pdo_mysql

WORKDIR /var/www/html

COPY . /var/www/html
COPY docker/entrypoint.sh /usr/local/bin/lums-entrypoint
COPY docker/php.ini /usr/local/etc/php/conf.d/lums.ini
COPY docker/servername.conf /etc/apache2/conf-available/lums-servername.conf

RUN chmod +x /usr/local/bin/lums-entrypoint \
    && mkdir -p /var/www/html/storage/sessions \
    && chown -R www-data:www-data /var/www/html/storage \
    && a2enconf lums-servername

EXPOSE 80

ENTRYPOINT ["lums-entrypoint"]
CMD ["apache2-foreground"]
