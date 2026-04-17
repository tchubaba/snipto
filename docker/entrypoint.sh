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
    
else
    echo "Running in PRODUCTION mode..."
    
    echo "Installing dependencies (optimized)..."
    composer install --no-dev --optimize-autoloader --no-interaction --quiet
    
    if ! grep -q "APP_KEY=base64" .env; then
        echo "Generating application key..."
        php artisan key:generate
    fi
    
    echo "Caching configuration and routes..."
    php artisan optimize
    php artisan view:cache
    
    echo "Building assets..."
    rm -f public/hot
    npm install
    npm run build
fi

# Wait for database to be ready
echo "Waiting for database migration..."
until php artisan migrate --force; do
    sleep 1
done

# Initialize GrumPHP in dev mode
if [ "$APP_ENV" = "local" ]; then
    php ./vendor/bin/grumphp git:init || true
fi

# Start background workers
echo "Starting queue worker..."
php artisan queue:listen --tries=1 &

# Default APP_PORT to 8080 if not set
PORT=${APP_PORT:-8080}

# Start the application
echo "Starting PHP server on port $PORT..."
php artisan serve --host=0.0.0.0 --port=$PORT
