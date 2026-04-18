FROM php:8.3-fpm

USER root

# Arguments defined in docker-compose.yml
# Defaults provided for production/CI builds where these aren't passed
ARG user=snipto
ARG uid=1000

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
    procps

# Clear cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip intl

# Install Xdebug (conditionally)
ARG INSTALL_XDEBUG=false
RUN if [ "$INSTALL_XDEBUG" = "true" ]; then \
    pecl install xdebug && docker-php-ext-enable xdebug; \
fi

# Install Node.js (Vite needs it)
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Create system user to run Composer and Artisan Commands
# We use a more robust check and avoid deleting existing system users if possible
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

# Set working directory
WORKDIR /var/www/html

# Copy entrypoint script and make it executable
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

USER $user

ENTRYPOINT ["entrypoint.sh"]
