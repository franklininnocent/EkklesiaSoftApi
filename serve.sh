#!/bin/bash

# Laravel serve script that suppresses the PHP_CLI_SERVER_WORKERS warning
# Usage: ./serve.sh [options]

# Temporarily unset PHP_CLI_SERVER_WORKERS to avoid warning
# (The warning occurs when this env var is set but --no-reload is not used)
unset PHP_CLI_SERVER_WORKERS

# Run serve command with all passed arguments
php artisan serve "$@"

