# Throwaway test runner for the Arco monorepo: unit tests, runtime (node) tests and PHPStan run
# here, never on the host, so a test that deletes files can only damage this container.
# Built and run by bin/test-docker (composer test:docker). Not part of any published package.
FROM php:8.4.26-cli-alpine3.24@sha256:37c8c64177612b72e7f9d2c00481739c166a3708bea27c1cfd27fea1291d82d1

# memory_limit matches a typical host php.ini (the image default of 128M is too small).
# zip: ext-zip (composer.json); pcntl: console runtime signal handling.
# curl (+ busybox sh, sha256sum, cut): the console bootstrap tests. nodejs: composer test:runtime.
RUN apk add --no-cache curl nodejs libzip \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS libzip-dev \
 && docker-php-ext-install -j"$(nproc)" zip pcntl \
 && apk del .build-deps \
 && rm -rf /tmp/* /usr/src/php* \
 && echo 'memory_limit=512M' > "$PHP_INI_DIR/conf.d/arco-test.ini"

# The container has no network. PsySH's weekly manual update check (RemoteShell leaves it on) would
# otherwise wait ~5 s on DNS in every RemoteShellTest shell; a global PsySH config turns it off.
RUN mkdir -p /etc/xdg/psysh \
 && echo "<?php return ['updateCheck' => 'never', 'updateManualCheck' => 'never'];" > /etc/xdg/psysh/config.php

# Mount point of the arco-test-cache volume (PHPStan result cache); a new volume copies this mode.
RUN mkdir -m 1777 /cache

ENV HOME=/tmp \
    COMPOSER_HOME=/tmp/composer
WORKDIR /app
