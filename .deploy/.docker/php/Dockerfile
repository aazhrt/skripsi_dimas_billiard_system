FROM debian:bookworm-slim

RUN apt-get update && apt-get install -y curl gnupg2 ca-certificates --no-install-recommends \
  && echo "deb https://packages.sury.org/php/ bookworm main" > /etc/apt/sources.list.d/sury.list \
  && curl -fsSL https://packages.sury.org/php/apt.gpg | gpg --dearmor -o /etc/apt/trusted.gpg.d/sury.gpg \
  && apt-get update \
  && apt-get install -y \
    php8.3-fpm \
    php8.3-mysql \
    php8.3-zip \
    php8.3-intl \
    php8.3-mbstring \
    php8.3-xml \
    php8.3-curl \
    php8.3-bcmath \
    php8.3-gd \
    php8.3-pdo \
    default-mysql-client git \
    --no-install-recommends \
  && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
  && apt-get install -y nodejs --no-install-recommends \
  && npm install -g npm \
  && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
  && php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
  && php -r "unlink('composer-setup.php');"

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/composer \
    PATH=$PATH:/composer/vendor/bin

RUN git config --global --add safe.directory /var/www

RUN sed -i 's|listen = /run/php/php8.3-fpm.sock|listen = 0.0.0.0:9000|' /etc/php/8.3/fpm/pool.d/www.conf \
  && sed -i '/^listen.allowed_clients/s/^/;/' /etc/php/8.3/fpm/pool.d/www.conf

EXPOSE 9000
CMD ["php-fpm8.3", "-F"]
WORKDIR /var/www
