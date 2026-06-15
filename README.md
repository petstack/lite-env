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
- Multiline values
- Quoted values (both single and double quotes)
- Inline comments support with proper whitespace handling (space or tab before `#`)
- **Multiple file loading** with single method call
- **Immutable by default** — variables already set in the real environment are never overwritten (opt out with `Env::$overwriteExisting`)
- Comprehensive error handling and validation

## Installation

```bash
composer require petstack/lite-env
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

### New in v2.0: Automatic File Loading

By default, `Env::load()` will automatically load both `.env` and `.env.local` files if they exist in the current directory. The `.env.local` file takes precedence over `.env` for overlapping variables.

```php
// This will load .env, .env.local, and custom.env (in that order)
Env::load('custom.env');

// To load only specific files without defaults:
Env::$disableDefaultPaths = true;
Env::load('production.env', 'secrets.env');
```

### Existing environment variables

By default Lite Env does **not** overwrite variables that already exist in the
real environment (set by the OS, a container, or CI) — `.env` files only fill in
what is missing, and the real environment stays authoritative. A later `.env`
file still overrides an earlier one within the same load.

To restore the old behavior and let `.env` values overwrite existing variables:

```php
Env::$overwriteExisting = true;
Env::load();
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

## What's new in v2.2

- **`0` is preserved** — `KEY=0` no longer collapses into an empty string.
- **Safer numeric conversion** — only canonical numbers are converted to int/float; everything else stays a string (see Type Conversion above).
- **Single-quoted values are literal** — variable interpolation is skipped inside single quotes, following dotenv convention.
- **More robust quote parsing** — whitespace before an opening quote, a lone opening quote at the end of a line, and inline comments after a closing quote of a multiline value are now handled correctly.
- **Reliable `\$` escaping** — escaped dollar signs are handled by the interpolation pattern itself; values containing the former internal placeholder text are no longer corrupted.
- **Stricter file loading** — explicitly passed paths named `.env`/`.env.local` now throw when missing instead of being silently skipped.

## What's new in v2.1

- **`Env::get()` no longer throws** on an invalid key format — it returns the provided default value instead. This makes `get()` safe to call with arbitrary input.
- **Inline comments** can now be separated from the value by a tab (`\t#`), not only by a space (` #`).
- **EOF handling** — the last `KEY=VALUE` pair is now emitted correctly even if the file does not end with a newline.
- **Multiline quoted values** are now assembled correctly across lines.
- **`putenv()`** now receives the raw string value; the type-converted value is still available via `Env::get()`, `$_ENV` and `$_SERVER`.

## Version 2.0 Breaking Changes

If you're upgrading from v1.x, please note these breaking changes:

1. **Class is now final** - You cannot extend the `Env` class anymore
2. **Removed `loadMultiple()` method** - Use `load(string ...$paths)` instead:
   ```php
   // Old (v1.x)
   Env::loadMultiple('/path/to/.env', '/path/to/another.env');

   // New (v2.0)
   Env::load('/path/to/.env', '/path/to/another.env');
   ```
3. **Automatic default file loading** - `load()` now loads `.env` and `.env.local` by default

See [CHANGELOG.md](CHANGELOG.md) for detailed migration guide.

## Requirements

- PHP 8.3 or higher

## License

This library is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
