#!/bin/bash
set -e

# Ensure .env exists
if [ ! -f .env ]; then
    echo "Creating .env from .env.example..."
    cp .env.example .env
fi

# Default APP_ENV to production if not set
APP_ENV=${APP_ENV:-production}

echo "Running in PRODUCTION mode (PHP-FPM)..."

# Stale hot file from a prior dev session would route asset URLs to the Vite dev server.
if [ -f public/hot ]; then
    echo "Removing stale Vite hot file..."
    rm -f public/hot
fi

# The marker file is dropped during the prod build inside vendor/. When running without
# a bind mount (e.g., Docker Hub image), the marker is visible — so we skip install/build.
# With a local bind mount, the marker is hidden and we rebuild from source.
if [ -f vendor/.snipto-baked ]; then
    echo "Image is pre-baked; skipping composer install and asset build."
else
    echo "Installing PHP dependencies..."
    composer install --no-dev --optimize-autoloader --no-interaction

    if [ ! -d public/build ] || [ -z "$(ls -A public/build 2>/dev/null)" ]; then
        echo "Building frontend assets..."
        npm install
        npm run build
    fi
fi

if ! grep -q "APP_KEY=base64" .env; then
    if [ -n "$APP_KEY" ]; then
         echo "Using APP_KEY from environment."
         sed -i "s|APP_KEY=.*|APP_KEY=${APP_KEY}|" .env
    else
        echo "Generating application key..."
        php artisan key:generate
    fi
fi

echo "Caching configuration and routes..."
php artisan optimize
php artisan view:cache

# Handle SQLite initialization if needed
if [ "$DB_CONNECTION" = "sqlite" ]; then
    if [ ! -f "$DB_DATABASE" ]; then
        echo "Initializing SQLite database at $DB_DATABASE..."
        mkdir -p "$(dirname "$DB_DATABASE")"
        touch "$DB_DATABASE"
    fi
fi

# Wait for database to be ready and run migrations
echo "Running migrations..."
until php artisan migrate --force; do
    echo "Migration failed, retrying in 2 seconds..."
    sleep 2
done

# Start PHP-FPM
echo "Starting PHP-FPM..."
exec php-fpm