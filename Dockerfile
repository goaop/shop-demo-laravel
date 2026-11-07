# Production-style image: Laravel's caches, the AOP cache, its preload script and the OPcache file cache are built at
# image build time. The container runs with PREBUILT_CACHE on a read-only cache and serves its first request hot.
FROM php:8.5-cli

# OPcache is part of PHP 8.5 (before 8.5: RUN docker-php-ext-install opcache)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip \
 && rm -rf /var/lib/apt/lists/*

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr
WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .

# 1. .env, code generation and an authoritative class map (package:discover runs here, before PREBUILT_CACHE is on).
#    The demo generates its APP_KEY at build time; a real application passes APP_KEY at runtime
RUN cp -n .env.example .env \
 && php bin/generate.php --if-missing \
 && composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
 && php artisan key:generate --force

# 2. The AOP cache first (with PREBUILT_CACHE only aop:warmup may run while the cache is incomplete), with the
#    opcache.preload script, then Laravel's caches: the cached configuration keeps GOAOP_FEATURES=64 (PREBUILT_CACHE)
RUN GOAOP_FEATURES=64 php artisan aop:warmup --preload --workers="$(nproc)" \
 && GOAOP_FEATURES=64 php artisan optimize

# 3. The OPcache file cache: the bridge's primer compiles the AOP cache and Composer's class map; the entry points,
#    the configuration and Laravel's bootstrap caches are passed with --entry. PHP keeps every script it compiles
#    in file_cache_only mode in memory: ~5,700 files need more than the default 128M
COPY docker/opcache.ini $PHP_INI_DIR/conf.d/zz-opcache.ini
RUN mkdir -p /var/cache/opcache \
 && php -d memory_limit=512M -d opcache.file_cache_only=1 -d opcache.file_cache_read_only=0 -d opcache.preload= \
        artisan aop:warmup-opcache --file-cache=/var/cache/opcache --entry=public/index.php --entry=artisan \
        $(find bootstrap config routes -name '*.php' -printf '--entry=%p ') \
 && chown -R www-data:www-data /var/cache/opcache storage bootstrap/cache \
 && chmod -R a-w /var/cache/opcache storage/framework/aop bootstrap/cache

USER www-data
EXPOSE 8080
# The built-in server keeps the demo self-contained; in production run PHP-FPM (or Octane) with the same php.ini
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
