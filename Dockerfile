# Use PHP 8.2 CLI as the base image (matches new minimum requirement)
FROM php:8.2-cli

# Install system dependencies
# git, zip/unzip for composer
# nodejs, npm for npm support (optional, could assume runner has them)
# libonig-dev for mbstring php extension
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    zip \
    unzip \
    libonig-dev \
    # Install Node.js and npm (example using nodesource setup)
    && curl -fsSL https://deb.nodesource.com/setup_lts.x | bash - \
    && apt-get install -y nodejs \
    && rm -rf /var/lib/apt/lists/*

# Install required PHP extensions (adjust based on actual needs)
# json is usually built-in, mbstring, curl needed by Guzzle/Symfony
RUN docker-php-ext-install mbstring curl

# Install Composer globally
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy application files (including the new run-action.php)
COPY . /app

# Install composer dependencies (production mode)
# This will now include monolog based on the updated composer.json
# Removed --ignore-platform-reqs as the lock file should be generated correctly now.
RUN composer install --no-dev --optimize-autoloader

# Make entrypoint script executable
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Set the entrypoint
ENTRYPOINT ["/entrypoint.sh"] 