FROM php:8.4-cli

WORKDIR /var/www

# Install required packages
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        procps \
        unzip \
        libpq-dev \
        libzip-dev \
        nodejs \
        npm \
        cron \
    && docker-php-ext-install pdo_pgsql zip pcntl sockets \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY . .

# Add crontab and schedule the Laravel scheduler
RUN chmod +x /var/www/docker-start.sh \
    && echo "* * * * * root cd /var/www && /usr/local/bin/php artisan schedule:run 1>/dev/null 2>> /var/www/storage/logs/scheduler.log" > /etc/cron.d/laravel \
    && chmod 0644 /etc/cron.d/laravel \
    && crontab /etc/cron.d/laravel

EXPOSE 8000
EXPOSE 8080

CMD ["sh", "/var/www/docker-start.sh"]
