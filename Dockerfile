#
# Composer
#
FROM composer:2.0.8 as composer

#
# PHP
#
FROM php:8.0.1-cli-alpine3.12 as php-raw

ENV APP_DIR=/opt/app
ENV PATH=${APP_DIR}/bin:${APP_DIR}/vendor/bin:${PATH}

WORKDIR ${APP_DIR}

#
# > PHP EXTENSIONS
#
ENV PHP_EXT_DIR /usr/local/lib/php/extensions/no-debug-non-zts-20200930
RUN set -ex \
    && if [ `pear config-get ext_dir` != ${PHP_EXT_DIR} ]; then echo PHP_EXT_DIR must be `pear config-get ext_dir` && exit 1; fi

FROM php-raw AS php-build
RUN --mount=type=cache,target=/var/cache/apk \
    set -ex \
    && apk add --update-cache \
        $PHPIZE_DEPS

FROM php-build AS php-ext-zip
RUN --mount=type=cache,target=/var/cache/apk \
    set -ex \
    && apk add \
        libzip-dev \
    && docker-php-ext-install zip

FROM php-build AS php-ext-pcntl
RUN set -ex \
    && docker-php-ext-install pcntl

FROM php-build AS php-ext-sockets
RUN set -ex \
    && docker-php-ext-install sockets

FROM php-build AS php-ext-xdebug
RUN set -ex \
    && pecl install xdebug

FROM php-build AS php-ext-pcov
RUN set -ex \
    && pecl install pcov

FROM php-build AS php-ext-buffer
ENV EXT_BUFFER_VERSION 0.1.0
RUN set -ex \
    && curl -L https://github.com/phpinnacle/ext-buffer/archive/${EXT_BUFFER_VERSION}.tar.gz | tar xz \
    && cd ext-buffer-${EXT_BUFFER_VERSION} \
    && phpize && ./configure && make && make install

FROM php-build AS php-ext-snappy
ENV EXT_SNAPPY_VERSION 0.2.1
RUN --mount=type=cache,target=/var/cache/apk \
    set -ex \
    && apk add snappy-dev \
    && curl -L https://github.com/kjdev/php-ext-snappy/archive/${EXT_SNAPPY_VERSION}.tar.gz | tar xz \
    && cd php-ext-snappy-${EXT_SNAPPY_VERSION} \
    && ls -al \
    && phpize && ./configure --with-snappy-includedir=/usr && make && make install
#
# < PHP EXTENSIONS
#

FROM php-raw AS php
COPY --from=php-ext-zip ${PHP_EXT_DIR}/zip.so ${PHP_EXT_DIR}/
COPY --from=php-ext-pcntl ${PHP_EXT_DIR}/pcntl.so ${PHP_EXT_DIR}/
COPY --from=php-ext-sockets ${PHP_EXT_DIR}/sockets.so ${PHP_EXT_DIR}/
COPY --from=php-ext-xdebug ${PHP_EXT_DIR}/xdebug.so ${PHP_EXT_DIR}/
COPY --from=php-ext-pcov ${PHP_EXT_DIR}/pcov.so ${PHP_EXT_DIR}/
COPY --from=php-ext-buffer ${PHP_EXT_DIR}/buffer.so ${PHP_EXT_DIR}/
COPY --from=php-ext-snappy ${PHP_EXT_DIR}/snappy.so ${PHP_EXT_DIR}/

RUN --mount=type=cache,target=/var/cache/apk \
    set -ex \
    && apk add \
        # composer
        git \
        # ext-zip
        libzip \
        snappy \
    && docker-php-ext-enable \
        buffer \
        pcntl \
        pcov \
        snappy \
        sockets \
        zip

ENV COMPOSER_ALLOW_SUPERUSER 1
ENV COMPOSER_MEMORY_LIMIT -1
COPY --from=composer /usr/bin/composer /usr/bin/composer
