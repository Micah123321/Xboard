#!/bin/sh
set -eu

# Preserve the existing PHP 8.2/Swoole build configuration while upstream
# container tags are still waiting for the official security release.
test "$PHP_VERSION" = '8.2.35'
test "$PHP_SHA256" = '5737b98601fe5ab1b8c5db0c82ed3158acb9283ed3081ffe2faffb9ac81455e6'
case "$(/usr/local/bin/php -n -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')" in
    8.2) ;;
    *) echo 'The source upgrade supports only the pinned PHP 8.2 base.' >&2; exit 1 ;;
esac

configure_options=$(/usr/local/bin/php-config --configure-options)
apk add --no-cache --virtual .xboard-php-build-deps \
    $PHPIZE_DEPS argon2-dev curl-dev libxml2-dev linux-headers \
    gnu-libiconv-dev oniguruma-dev openssl-dev readline-dev libsodium-dev sqlite-dev zlib-dev xz
curl --fail --show-error --location --retry 3 \
    "https://www.php.net/distributions/php-$PHP_VERSION.tar.xz" -o /tmp/xboard-php.tar.xz
printf '%s  %s\n' "$PHP_SHA256" /tmp/xboard-php.tar.xz | sha256sum -c -

# docker-php-ext-install must also compile against the patched PHP headers.
cp /tmp/xboard-php.tar.xz /usr/src/php.tar.xz
rm -rf /usr/src/php
mkdir -p /usr/src/php
tar -xJf /tmp/xboard-php.tar.xz -C /usr/src/php --strip-components=1
cd /usr/src/php
export CFLAGS="$PHP_CFLAGS" CPPFLAGS="$PHP_CPPFLAGS" LDFLAGS="$PHP_LDFLAGS"
# Options come from the pinned, trusted base image, not build arguments or input.
eval "./configure $configure_options"
make -j2
make install
/usr/local/bin/php -n /usr/local/bin/xboard-check-php
cd /
rm -rf /usr/src/php /tmp/xboard-php.tar.xz
apk add --no-cache --virtual .xboard-php-runtime-deps gnu-libiconv-libs
apk del .xboard-php-build-deps
/usr/local/bin/php -r 'if (!extension_loaded("swoole")) { fwrite(STDERR, "Swoole did not load after the PHP upgrade.\n"); exit(1); } echo "PHP ", PHP_VERSION, "; Swoole ", phpversion("swoole"), PHP_EOL;'
