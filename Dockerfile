FROM php:8.5.12-cli

RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini && \
        sed -i -e "s/^ *memory_limit.*/memory_limit = 4G/g" /usr/local/etc/php/php.ini

RUN curl -fL --output /tmp/pie.phar https://github.com/php/pie/releases/latest/download/pie.phar \
      && mv /tmp/pie.phar /usr/local/bin/pie \
      && chmod +x /usr/local/bin/pie

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libtool libzip-dev unzip \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

RUN pie install pecl/zip
RUN docker-php-ext-enable zip

RUN pie install xdebug/xdebug \
    && docker-php-ext-enable xdebug \
    && echo "xdebug.mode=off" > /usr/local/etc/php/conf.d/99-xdebug-mode.ini

RUN curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php \
    && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && rm /tmp/composer-setup.php