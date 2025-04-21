# Use PHP 8.2 CLI as the base image (matches new minimum requirement)
FROM php:8.2-cli

# Install system dependencies
# git, zip/unzip for composer
# nodejs, npm for npm support (optional, could assume runner has them)
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    zip \
    unzip \
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

# Copy application files
COPY . /app

# Install composer dependencies (production mode)
# Use --ignore-platform-reqs initially if lock file causes issues, then refine
RUN composer install --no-dev --optimize-autoloader --ignore-platform-reqs
# Alternatively, regenerate lock file inside docker build based on php 8.2?
# RUN composer update --no-dev --optimize-autoloader

# Make entrypoint script executable
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Set the entrypoint
ENTRYPOINT ["/entrypoint.sh"] 