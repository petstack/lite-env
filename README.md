# Lite Env

A minimal and lightweight .env file parser for PHP with no dependencies.

## Description

Lite Env is a simple library for loading environment variables from .env files in PHP applications. It's designed to be lightweight and dependency-free, making it ideal for use in other libraries where minimizing dependencies is important.

> **Note:** This is a lightweight library primarily intended for developing other libraries. For production applications, consider using more powerful and feature-rich alternatives like [vlucas/phpdotenv](https://github.com/vlucas/phpdotenv) or [symfony/dotenv](https://github.com/symfony/dotenv).

## Features

- Zero dependencies
- Simple and intuitive API
- Support for variable interpolation (`${VAR}` or `$VAR` syntax)
- Type conversion (strings, integers, floats, booleans, null)
- Multiline values
- Quoted values (both single and double quotes)
- Comments support

## Installation

```bash
composer require petstack/lite-env
```

## Usage

### Basic Usage

```php
<?php

use PetStack\LiteEnv\Env;

// Load variables from .env file in the current directory
Env::load();

// Or specify a custom path
Env::load('/path/to/.env');

// Load multiple .env files
Env::loadMultiple('/path/to/.env', '/path/to/another/.env');

// Get an environment variable
$dbName = Env::get('DATABASE_NAME');

// Get with a default value if the variable doesn't exist
$port = Env::get('PORT', 3000);

// Check if a variable exists
if (Env::has('DEBUG')) {
    // Do something
}

// Get all loaded environment variable keys
$keys = Env::getAllKeys();
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

# Multiline value
MULTILINE_VALUE="line1
line2
line3"
```

### Type Conversion

Lite Env automatically converts values to appropriate PHP types:

- `true`, `(true)`, `TRUE` → `true` (boolean)
- `false`, `(false)`, `FALSE` → `false` (boolean)
- `null`, `(null)` → `null`
- `empty`, `(empty)`, `""`, `''` → `''` (empty string)
- Numeric values → integers or floats

## License

This library is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.