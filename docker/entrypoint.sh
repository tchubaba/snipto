#!/bin/bash
set -e

# Ensure .env exists
if [ ! -f .env ]; then
    echo "Creating .env from .env.example..."
    cp .env.example .env
fi

# Default APP_ENV to production if not set
APP_ENV=${APP_ENV:-production}

if [ "$APP_ENV" = "local" ]; then
    echo "Running in DEVELOPMENT mode..."
    
    echo "Installing dependencies..."
    composer install --no-interaction
    
    if ! grep -q "APP_KEY=base64" .env; then
        echo "Generating application key..."
        php artisan key:generate
    fi
    
    echo "Generating IDE helper files..."
    php artisan ide-helper:generate || true
    
    echo "Installing and starting Node.js assets..."
    npm install
    npm run dev &
    
    # Initialize GrumPHP
    php ./vendor/bin/grumphp git:init || true
else
    echo "Running in PRODUCTION mode..."
    
    # In a standalone image, dependencies and assets are already built.
    # We only need to ensure the APP_KEY is set if it's passed via environment.
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
fi

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

# Start background workers
echo "Starting queue worker..."
php artisan queue:listen --tries=1 &

# Default APP_PORT to 8080 if not set
PORT=${APP_PORT:-8080}

# Start the application
echo "Starting PHP server on port $PORT..."
exec php artisan serve --host=0.0.0.0 --port=$PORT
