FROM php:8.5-cli

USER root

# Arguments defined in docker-compose.yml
# Defaults provided for standalone/CI builds
ARG user=snipto
ARG uid=1000
ARG APP_ENV=production

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libzip-dev \
    libicu-dev \
    gnupg \
    procps \
    autoconf \
    g++ \
    make

# Clear cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip intl

# Install Xdebug if in local environment
RUN if [ "$APP_ENV" = "local" ]; then \
    pecl install xdebug && \
    docker-php-ext-enable xdebug; \
fi

# Install Node.js (needed for building assets)
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Create system user to run Composer and Artisan Commands
# Robust check to prevent build failure if UID exists
RUN if [ "$uid" != "0" ]; then \
    if getent passwd "$uid" > /dev/null; then \
        existing_user=$(getent passwd "$uid" | cut -d: -f1); \
        if [ "$existing_user" != "$user" ]; then \
            userdel -f "$existing_user"; \
        fi; \
    fi; \
    if ! getent passwd "$user" > /dev/null; then \
        useradd -G sudo,www-data -u "$uid" -d /home/"$user" "$user"; \
    fi; \
fi

RUN mkdir -p /home/$user/.composer && \
    chown -R $user:$user /home/$user

# Pre-create /data so a mounted named volume inherits $user ownership.
# Without this, Docker creates the mountpoint as root:root and SQLite init fails.
RUN mkdir -p /data && chown $user:$user /data

# Set working directory
WORKDIR /var/www/html

# Copy project files and set ownership
COPY --chown=$user:$user . .

# Install dependencies and build assets based on APP_ENV.
# Production (incl. Docker Hub image): bake deps + built assets into the image for fast startup.
# Local (development): skip — the bind mount hides image contents; entrypoint handles install on boot.
USER $user
RUN if [ "$APP_ENV" = "local" ]; then \
        echo "Local/development build — deferring composer install and asset build to entrypoint."; \
    else \
        composer install --no-dev --optimize-autoloader --no-interaction \
        && npm install \
        && npm run build \
        && rm -rf node_modules \
        && touch vendor/.snipto-baked; \
    fi

# Copy entrypoint script and make it executable
USER root
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

USER $user

ENTRYPOINT ["entrypoint.sh"]
