# syntax=docker/dockerfile:1

# --- Smart Route Planner — Docker-образ ---
#
# Один этап (single-stage) достаточно: проекту не нужен composer install
# (см. bootstrap.php — собственный PSR-4-автозагрузчик) и нет шага сборки
# фронтенда (обычный JS/CSS, без сборщиков). Multi-stage здесь добавил бы
# сложности без реальной выгоды.
#
# Образ на базе официального php:8.3-apache — Apache + mod_php, простой и
# предсказуемый выбор для проекта такого размера (не php-fpm+nginx, чтобы
# не плодить два процесса и общий volume для php-fpm.sock).
FROM php:8.3-apache

# --- Системные зависимости для расширений PHP ---
# libonig-dev нужен для mbstring (используется в FileCache/NominatimGeocoder
# для регистронезависимого поиска по кэшу городов), libcurl4-openssl-dev —
# для curl (все внешние API: Nominatim/OSRM/Overpass/Open-Meteo/LLM).
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libonig-dev \
        libcurl4-openssl-dev \
    && docker-php-ext-install -j"$(nproc)" mbstring curl \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# --- Document root -> public/ ---
# Веб-часть приложения специально вынесена в public/, чтобы src/, bin/,
# tests/, var/ не были напрямую доступны по URL (см. docs/setup_guide.md).
# Apache по умолчанию смотрит в /var/www/html — переопределяем на public/.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e "s!/var/www/html!\${APACHE_DOCUMENT_ROOT}!g" \
        /etc/apache2/sites-available/*.conf \
        /etc/apache2/apache2.conf \
        /etc/apache2/conf-available/*.conf

WORKDIR /var/www/html

# Сначала только код — слои Docker кэшируются по содержимому, и
# пересборка образа при правках кода не требует заново ставить apt-пакеты.
COPY . .

# var/ должна быть доступна на запись процессу Apache (www-data): туда
# пишутся geocode-кэш, состояние rate limiter'а и (при "живом" дообучении
# через api/learn.php) веса MLP-модели.
RUN mkdir -p var/geocache var/ratelimit \
    && chown -R www-data:www-data var \
    && chmod -R 775 var

EXPOSE 80

# Даёт Docker/оркестратору (docker ps, docker compose, Kubernetes-подобные
# системы) знать, жив ли контейнер по-настоящему, а не просто "процесс не
# упал". Бьёт в /api/health.php изнутри контейнера — используем php-cli с
# потоковой обёрткой http, чтобы не тащить в образ отдельно curl CLI (curl
# уже стоит как PHP-расширение, а не как утилита командной строки).
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD php -r "exit(@file_get_contents('http://localhost/api/health.php') === false ? 1 : 0);"

# apache2-foreground — стандартный CMD официального образа php:apache,
# наследуется автоматически; явно не переопределяем.
