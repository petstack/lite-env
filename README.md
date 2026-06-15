# Lite Env

A minimal and lightweight .env file parser for PHP with no dependencies.

## Description

Lite Env is a simple library for loading environment variables from .env files in PHP applications. It's designed to be lightweight and dependency-free, making it ideal for use in other libraries where minimizing dependencies is important.

> **Note:** This is a lightweight library primarily intended for developing other libraries. For production applications, consider using more powerful and feature-rich alternatives like [vlucas/phpdotenv](https://github.com/vlucas/phpdotenv) or [symfony/dotenv](https://github.com/symfony/dotenv).

## Features

- Zero dependencies
- Simple and intuitive API
- **Automatic loading** of `.env` and `.env.local` files
- Support for variable interpolation (`${VAR}` or `$VAR` syntax; single-quoted values stay literal, `\$` escapes a dollar sign)
- Type conversion (strings, integers, floats, booleans, null)
- Loaded values exposed through `Env::get()`, `getenv()`, `$_ENV`, and `$_SERVER`
- Multiline values
- Quoted values (both single and double quotes)
- Inline comments support with proper whitespace handling (space or tab before `#`)
- **Multiple file loading** with single method call
- **Immutable by default** — variables already set in the real environment are never overwritten (opt out with `Env::$overwriteExisting`)
- Comprehensive error handling and validation

## Installation

```bash
composer require --dev petstack/lite-env
```

## Usage

### Basic Usage

```php
<?php

use PetStack\LiteEnv\Env;

// Load variables from .env and .env.local files automatically
Env::load();

// Load custom files along with default ones
Env::load('/path/to/custom.env');

// Load multiple custom files
Env::load('/path/to/.env.production', '/path/to/.env.secrets');

// Disable automatic loading of default files
Env::$disableDefaultPaths = true;
Env::load('/path/to/custom.env'); // Only loads custom.env

// By default, variables already present in the environment are preserved.
// Opt in to letting .env values overwrite them:
Env::$overwriteExisting = true;

// Get an environment variable
$dbName = Env::get('DATABASE_NAME');

// Get with a default value if the variable doesn't exist
// (also returned when the requested key has an invalid format)
$port = Env::get('PORT', 3000);

// Check if a variable exists
if (Env::has('DEBUG')) {
    // Do something
}

// Get all loaded environment variable keys
$keys = Env::getAllKeys();
```

### Automatic File Loading

By default, `Env::load()` will automatically load both `.env` and `.env.local` files if they exist in the current directory. The `.env.local` file takes precedence over `.env` for overlapping variables.

```php
// This will load .env, .env.local, and custom.env (in that order)
Env::load('custom.env');

// To load only specific files without defaults:
Env::$disableDefaultPaths = true;
Env::load('production.env', 'secrets.env');
```

### Example .env File

```
# Database configuration
DATABASE_NAME=myapp_db
DATABASE_USER=dbuser
DATABASE_PASS=secret

# Application settings
APP_ENV=development
DEBUG=true
PORT=3000

# Path configuration with variable interpolation
BASE_PATH=/var/www/app
LOG_PATH=${BASE_PATH}/logs
CACHE_PATH=$BASE_PATH/cache
LITERAL='$BASE_PATH is not expanded in single quotes'
PRICE="costs \$5"

# Values with inline comments (space or tab before `#`)
API_URL=https://api.example.com   # Production API endpoint
TIMEOUT=30	# Connection timeout in seconds

# Multiline value
MULTILINE_VALUE="line1
line2
line3"

# Quoted values with inner quotes
QUOTED_VALUE='He said "Hello World"'
JSON_CONFIG={"key": "value", "debug": true}
```

### Type Conversion

Lite Env automatically converts values to appropriate PHP types:

- `true`, `(true)`, `TRUE` → `true` (boolean)
- `false`, `(false)`, `FALSE` → `false` (boolean)
- `null`, `(null)` → `null`
- `empty`, `(empty)`, `""`, `''` → `''` (empty string)
- Numeric values → integers or floats

Only canonical numeric representations are converted: values with leading zeros (`01234`), trailing decimal zeros (`1.10`) or beyond the integer range are kept as strings, so zip codes, version numbers and long numeric IDs are never corrupted.

## Requirements

- PHP 8.3 or higher

## License

This library is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
