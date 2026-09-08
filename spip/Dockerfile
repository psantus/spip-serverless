# syntax=docker/dockerfile:1
#
# SPIP-serverless image (Bref v3, ARM, PHP 8.5) for AWS Lambda.
#
# SPIP core is NOT vendored in this repo — it is fetched at build time from the
# official archive (files.spip.net) at the pinned SPIP_VERSION below. Bump it in
# ONE place (this ARG, mirrored by the Makefile / CI) to upgrade SPIP.
#
#   docker build --platform linux/arm64 --build-arg SPIP_VERSION=4.4.22 -f spip/Dockerfile .

##############################################
# Stage: spip-core — fetch the official SPIP release
##############################################
FROM alpine:3.20 AS spip-core
ARG SPIP_VERSION=4.4.22
RUN apk add --no-cache curl unzip
WORKDIR /tmp/dl
RUN set -eux; \
    curl -fSL "https://files.spip.net/spip/archives/spip-v${SPIP_VERSION}.zip" -o spip.zip; \
    unzip -q spip.zip -d extracted; \
    # Archives extract either flat (ecrire/ at the root) or nested under spip/.
    if [ -d extracted/spip/ecrire ]; then SRC=extracted/spip; else SRC=extracted; fi; \
    [ -d "$SRC/ecrire" ] || { echo "Unexpected archive layout" >&2; exit 1; }; \
    mkdir -p /spip-src; \
    cp -a "$SRC/." /spip-src/; \
    grep -q "spip_version_branche = '${SPIP_VERSION}'" /spip-src/ecrire/inc_version.php \
      || { echo "Fetched core is not ${SPIP_VERSION}" >&2; exit 1; }

##############################################
# Stage: ext-pgsql — build native pgsql extension
##############################################
FROM bref/arm-build-php-85:3 AS ext-pgsql

WORKDIR ${PHP_BUILD_DIR}/ext/pgsql
RUN phpize
ENV EXT_CONFIGURE_OPTS="--with-pgsql"
RUN ./configure ${EXT_CONFIGURE_OPTS}
RUN make -j$(nproc) install
RUN cp "$(php -r 'echo ini_get("extension_dir");')/pgsql.so" /tmp/pgsql.so
RUN echo "extension=pgsql.so" > /tmp/ext-pgsql.ini
RUN mkdir -p /tmp/extension-libs \
    && cp /usr/lib64/libpq.so* /tmp/extension-libs/ 2>/dev/null || true

##############################################
# Stage: ext-gd — build GD extension
##############################################
FROM bref/arm-build-php-85:3 AS ext-gd

RUN set -ue \
    ; LD_LIBRARY_PATH= \
    ; dnf -y install libwebp-devel libpng-devel libjpeg-devel freetype-devel \
    ; dnf clean all

WORKDIR ${PHP_BUILD_DIR}/ext/gd
RUN phpize
RUN ./configure --with-freetype --enable-gd --with-jpeg --with-png --with-webp
RUN make -j$(nproc) install
RUN cp "$(php-config --extension-dir)/gd.so" /tmp/gd.so
RUN echo "extension=gd.so" > /tmp/ext-gd.ini
RUN php /bref/lib-copy/copy-dependencies.php /tmp/gd.so /tmp/extension-libs-gd

##############################################
# Stage: vendors — install PHP dependencies on top of SPIP core
##############################################
FROM composer:2 AS vendors

COPY --from=spip-core /spip-src/ /app/
COPY spip/scripts/shrink-vendor.sh /app/shrink-vendor.sh
WORKDIR /app

RUN composer config platform.php 8.5 \
    && composer require bref/bref:^3.0 bref/secrets-loader:^1 aws/aws-sdk-php:^3.0 \
       open-telemetry/sdk:^1 open-telemetry/exporter-otlp:^1 \
       --no-interaction --no-progress \
    && sh shrink-vendor.sh vendor

##############################################
# Stage: local — run with Apache for local dev (make run-local)
##############################################
FROM php:8.4-apache AS local

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libpq-dev libxml2-dev libsodium-dev libzip-dev zlib1g-dev; \
    docker-php-ext-install pgsql pdo_pgsql xml sodium zip opcache; \
    a2enmod rewrite; \
    rm -rf /var/lib/apt/lists/*

COPY --from=vendors /app/ /var/www/html/

COPY spip/overlay/ecrire/exec/install.php /var/www/html/ecrire/exec/install.php
COPY spip/overlay/ecrire/req/dsql.php /var/www/html/ecrire/req/dsql.php
COPY spip/overlay/config/connect.php /var/www/html/config/connect.php
COPY spip/overlay/config/mes_options.php /var/www/html/config/mes_options.php
COPY spip/scripts/bootstrap-db.php /var/www/html/scripts/bootstrap-db.php

RUN mkdir -p /var/www/html/plugins/auto /var/www/html/lib /var/www/html/squelettes \
             /var/www/html/tmp/{log,dump,upload,cache,sessions} /var/www/html/local \
    && chown -R www-data:www-data /var/www/html

RUN echo "PassEnv SPIP_DSQL_CLUSTER AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_SESSION_TOKEN AWS_REGION" \
    > /etc/apache2/conf-enabled/aws-env.conf

EXPOSE 80

##############################################
# Stage: lambda (default) — Bref v3 ARM PHP 8.5
##############################################
FROM bref/arm-php-85:3 AS lambda

# opcache.file_cache (php/conf.d/opcache.ini) points here; the dir must exist before
# any `php` runs during the build, else PHP aborts ("file_cache must be accessible").
RUN mkdir -p /bref/opcache

# ADOT collector Lambda extension (download via: make download-adot)
COPY spip/overlay/adot-collector /opt/extensions/collector
COPY spip/overlay/otel-collector-config.yaml /var/task/collector-config.yaml

COPY --from=ext-pgsql /tmp/pgsql.so /opt/bref/extensions/pgsql.so
COPY --from=ext-pgsql /tmp/ext-pgsql.ini /opt/bref/etc/php/conf.d/ext-pgsql.ini
COPY --from=ext-pgsql /tmp/extension-libs /opt/lib
COPY --from=ext-gd /tmp/gd.so /opt/bref/extensions/gd.so
COPY --from=ext-gd /tmp/ext-gd.ini /opt/bref/etc/php/conf.d/ext-gd.ini
COPY --from=ext-gd /tmp/extension-libs-gd /opt/lib

COPY --from=vendors /app/ /var/task/

# Overlays that replace core files (DSQL connection, install wizard, options, router).
COPY spip/overlay/ecrire/exec/install.php /var/task/ecrire/exec/install.php
COPY spip/overlay/ecrire/req/dsql.php /var/task/ecrire/req/dsql.php
COPY spip/overlay/ecrire/src/Chiffrer/SpipCles.php /var/task/ecrire/src/Chiffrer/SpipCles.php
COPY spip/overlay/config/connect.php /var/task/config/connect.php
COPY spip/overlay/config/mes_options_lambda.php /var/task/config/mes_options.php
COPY spip/overlay/php/prepend.php /var/task/php/prepend.php
COPY spip/overlay/router.php /var/task/router.php

# Our plugins (always active — placed in plugins-dist).
COPY spip/plugins/s3upload/ /var/task/plugins-dist/s3upload/
COPY spip/plugins/s3upload/exec/ /var/task/ecrire/exec/
RUN rm -rf /var/task/plugins/s3upload /var/task/plugins/sessions_dynamodb

# Third-party plugins (always active — in plugins-dist).
COPY spip/plugins-vendor/ /var/task/plugins-dist/

# Session storage override for DynamoDB (loaded via squelettes path, not plugins-dist).
COPY spip/plugins/sessions_dynamodb/inc/session.php /var/task/squelettes/inc/session.php
COPY spip/squelettes/lang/ /var/task/squelettes/lang/

# Drop SPIP standard plugins we don't want on Lambda (BigUpload uses local disk;
# we upload via presigned S3 URLs instead — plus stats/forums/etc. we don't run).
RUN rm -rf \
    /var/task/plugins-dist/bigup \
    /var/task/plugins-dist/forum \
    /var/task/plugins-dist/statistiques \
    /var/task/plugins-dist/compagnon \
    /var/task/plugins-dist/aide \
    /var/task/plugins-dist/sites \
    /var/task/plugins-dist/plan \
    /var/task/plugins-dist/dump

# PHP runtime configuration.
COPY spip/php/conf.d/ /var/task/php/conf.d/

# Patch core documents.php for S3 support (presigned uploads / S3-backed media).
COPY spip/scripts/patch-documents.php /tmp/patch-documents.php
RUN php /tmp/patch-documents.php /var/task/ecrire/inc/documents.php && rm /tmp/patch-documents.php

# One-shot admin scripts (run manually against a fresh DSQL cluster).
COPY spip/scripts/bootstrap-db.php /var/task/scripts/bootstrap-db.php
COPY spip/scripts/create-author.php /var/task/scripts/create-author.php

RUN echo 'auto_prepend_file=/var/task/php/prepend.php' > /opt/bref/etc/php/conf.d/spip-lambda.ini

# Bref subscriber: writes trace context to /tmp/xray_trace_id before each invocation.
COPY spip/overlay/bref_xray_subscriber.php /var/task/bref_xray_subscriber.php
RUN sed -i "s|public static \$files = array (|public static \$files = array (\n        'xray_sub' => __DIR__ . '/..' . '/../bref_xray_subscriber.php',|" /var/task/vendor/composer/autoload_static.php

RUN mkdir -p /var/task/plugins/auto /var/task/lib /var/task/squelettes \
    && rm -rf /var/task/local && ln -s /tmp/spip/local /var/task/local \
    && rm -rf /var/task/IMG && ln -s /tmp/spip/IMG /var/task/IMG

# Pre-warm the opcache file-cache into /bref/opcache (read at runtime, read-only).
COPY spip/scripts/compile-opcache.php /var/task/scripts/compile-opcache.php
COPY spip/php/opcache-build.ini /tmp/opcache-build.ini
RUN PHP_INI_SCAN_DIR="" php -c /tmp/opcache-build.ini /var/task/scripts/compile-opcache.php \
    && rm /tmp/opcache-build.ini \
    && chmod -R 755 /bref/opcache
