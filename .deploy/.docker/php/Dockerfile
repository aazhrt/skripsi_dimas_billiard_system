FROM debian:bookworm-slim

RUN apt-get update && apt-get install -y curl gnupg2 ca-certificates --no-install-recommends \
  && echo "deb https://packages.sury.org/php/ bookworm main" > /etc/apt/sources.list.d/sury.list \
  && curl -fsSL https://packages.sury.org/php/apt.gpg | gpg --dearmor -o /etc/apt/trusted.gpg.d/sury.gpg \
  && apt-get update \
  && apt-get install -y \
    php8.2-fpm \
    php8.2-mysql \
    php8.2-zip \
    php8.2-intl \
    php8.2-mbstring \
    php8.2-xml \
    php8.2-curl \
    php8.2-bcmath \
    php8.2-gd \
    php8.2-pdo \
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

RUN sed -i 's|listen = /run/php/php8.2-fpm.sock|listen = 0.0.0.0:9000|' /etc/php/8.2/fpm/pool.d/www.conf \
  && sed -i '/^listen.allowed_clients/s/^/;/' /etc/php/8.2/fpm/pool.d/www.conf

EXPOSE 9000
CMD ["php-fpm8.2", "-F"]
WORKDIR /var/www
