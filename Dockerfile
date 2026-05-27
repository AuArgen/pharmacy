FROM php:8.3-apache

# SQLite + PDO драйвер
RUN apt-get update \
    && apt-get install -y --no-install-recommends libsqlite3-dev \
    && docker-php-ext-install pdo pdo_sqlite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Включаем mod_rewrite (на будущее, для красивых URL)
RUN a2enmod rewrite

# Папка для базы данных SQLite (монтируется как volume)
RUN mkdir -p /var/www/data \
    && chown -R www-data:www-data /var/www/data

WORKDIR /var/www/html

EXPOSE 80
