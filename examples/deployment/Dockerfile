FROM php:8.5-cli-alpine AS runtime
RUN apk add --no-cache icu-libs unzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev \
    && docker-php-ext-install -j2 intl pcntl \
    && apk del .build-deps
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV ENVIRONMENT=production
ENV SLACK_JOB_STORE_PATH=.thunder-bolt/state/jobs/queue.sqlite
ENV SLACK_CONTEXT_STORE=sqlite
ENV SLACK_CONTEXT_STORE_PATH=.thunder-bolt/state/conversations.sqlite
ENV SLACK_INSTALLATION_STORE_PATH=.thunder-bolt/state/oauth
WORKDIR /opt/thunder
COPY composer.json composer.lock ./
COPY src ./src
COPY bin ./bin
COPY stubs ./stubs
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress \
    && php bin/bolt new work/app --transport=socket --auth=token --no-interaction \
    && cd work/app && composer install --no-dev --no-interaction --prefer-dist --no-progress
COPY examples/deployment/app ./work/app/app
COPY examples/deployment/config ./work/app/config
RUN addgroup -g 10001 bolt && adduser -D -u 10001 -G bolt bolt \
    && mkdir -p /opt/thunder/work/app/.thunder-bolt/state \
    && chown -R bolt:bolt /opt/thunder/work/app/.thunder-bolt \
    && chmod 0700 /opt/thunder/work/app/.thunder-bolt /opt/thunder/work/app/.thunder-bolt/state
USER bolt
WORKDIR /opt/thunder/work/app
CMD ["php", "bolt", "jobs:work"]
