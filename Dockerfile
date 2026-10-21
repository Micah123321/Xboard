# syntax=docker/dockerfile:1.7

# Pin the existing Swoole/Alpine ABI and rebuild PHP from its verified release.
FROM phpswoole/swoole:php8.2-alpine@sha256:98a3bbe5d35a169125fcf5581ade5a0befc55a3d797177ca66ca185eaf4045c4 AS php-runtime
ENV PHP_VERSION=8.2.35 \
    PHP_SHA256=5737b98601fe5ab1b8c5db0c82ed3158acb9283ed3081ffe2faffb9ac81455e6
COPY .docker/php/check-runtime.php /usr/local/bin/xboard-check-php
COPY .docker/php/smoke-runtime.php /usr/local/bin/xboard-smoke-php
COPY .docker/php/runtime-entrypoint.sh /usr/local/bin/xboard-runtime-entrypoint
COPY .docker/php/upgrade-runtime.sh /usr/local/bin/xboard-upgrade-php
COPY .docker/php/zz-xboard-security.ini /usr/local/etc/php/conf.d/zz-xboard-security.ini
RUN chmod +x /usr/local/bin/xboard-runtime-entrypoint \
    && sh /usr/local/bin/xboard-upgrade-php

FROM php-runtime AS app

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# Install PHP extensions one by one with lower optimization level for ARM64 compatibility
RUN --mount=type=cache,target=/var/cache/apk,sharing=locked \
    CFLAGS="-O0" install-php-extensions pcntl && \
    CFLAGS="-O0 -g0" install-php-extensions bcmath && \
    install-php-extensions zip && \
    install-php-extensions redis && \
    apk add --update-cache --no-progress shadow sqlite mysql-client mysql-dev mariadb-connector-c git patch supervisor redis caddy && \
    addgroup -S -g 1000 www && adduser -S -G www -u 1000 www && \
    (getent group redis || addgroup -S redis) && \
    (getent passwd redis || adduser -S -G redis -H -h /data redis) && \
    mkdir -p /data && \
    chown redis:redis /data

WORKDIR /www

ENV COMPOSER_ALLOW_SUPERUSER=1

COPY composer.json composer.lock ./

RUN --mount=type=cache,target=/tmp/composer-cache,sharing=locked \
    COMPOSER_CACHE_DIR=/tmp/composer-cache composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --no-security-blocking \
    --no-scripts \
    --no-autoloader

COPY . /www

COPY .docker /
COPY .docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY .docker/caddy/Caddyfile /etc/caddy/Caddyfile
COPY .docker/php/zz-xboard.ini /usr/local/etc/php/conf.d/zz-xboard.ini

RUN php /usr/local/bin/xboard-smoke-php \
    && composer dump-autoload --no-dev --optimize --no-interaction \
    && php artisan storage:link
    
ENV ENABLE_WEB=true \
    ENABLE_HORIZON=true \
    ENABLE_REDIS=true \
    ENABLE_SCHEDULE=true \
    ENABLE_WS_SERVER=true \
    ENABLE_CADDY=true

EXPOSE 7001
COPY .docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/usr/local/bin/xboard-runtime-entrypoint", "/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"] 
