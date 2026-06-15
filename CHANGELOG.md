# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- `Env::$overwriteExisting` flag (default `false`): set it to `true` to restore the previous behavior where `.env` values overwrite variables that already exist in the environment

### Changed
- Existing environment variables are no longer overwritten by default: values already present in `getenv()`, `$_ENV` or `$_SERVER` before `Env::load()` runs are preserved, so the real environment (OS, container, CI) stays authoritative. A later `.env` file still overrides an earlier one within the same load

## [2.2.0] - 2026-06-11

### Fixed
- `KEY=0` no longer collapses into an empty string (`empty()` was used to detect empty values, and `empty('0')` is true)
- The last variable in a file is no longer parsed and interpolated twice when the file ends normally
- Integer conversion no longer strips leading zeros (`01234` stays a string) and no longer overflows past `PHP_INT_MAX` with a PHP warning; only canonical integers validated by `FILTER_VALIDATE_INT` are converted
- Float conversion no longer loses precision (`1.10` stays a string); values are converted only when the cast round-trips back to the original string
- Whitespace between `=` and the opening quote no longer breaks multiline values
- A lone opening quote at the end of a line now starts a multiline value instead of producing an empty one
- An inline comment after the closing quote of a multiline value no longer swallows the rest of the file
- Values containing the literal string `___ESCAPED_DOLLAR___` are no longer corrupted: the internal interpolation placeholder was removed and escaped dollar signs (`\$`) are handled by the interpolation pattern itself
- Explicitly passed paths named `.env` or `.env.local` now throw `RuntimeException` when missing instead of being silently skipped

### Changed
- Single-quoted values are treated as literals and are no longer interpolated, following dotenv convention
- Multiline quoted values now close at the first closing quote on a line; trailing content after it (e.g. an inline comment) is ignored
- Numeric type conversion only applies to canonical representations; non-canonical numeric strings (e.g. `01234`, `1.10`, `.5`, out-of-range integers) are kept as strings

### Technical Improvements
- Added regression test suite `tests/BugsV2Test.php` covering all of the above fixes

## [2.1.1] - 2026-04-24

### Added
- Benchmark CLI script for measuring `Env::load()` performance on generated `simple`, `mixed`, and `interpolation` scenarios or custom `.env` files, with timing and memory summaries

### Fixed
- Variable interpolation in `Env::load()` no longer throws `TypeError` when referenced variables are missing or have already been converted to typed PHP values

### Technical Improvements
- Added regression tests for interpolation with missing variables and already-typed values

## [2.0.0] - 2025-01-14

### Added
- Automatic loading of `.env` and `.env.local` files by default
- `$disableDefaultPaths` static property to control default file loading behavior
- Comprehensive PHPDoc documentation for all methods
- Support for proper type hints in `get()` method return type
- Enhanced error handling with more descriptive exception messages
- Private constructor to prevent instantiation (singleton-like behavior)

### Changed
- **[BREAKING]** Class is now `final` - cannot be extended
- **[BREAKING]** Removed `loadMultiple()` method - use `load(string ...$paths)` instead
- **[BREAKING]** `load()` method now accepts multiple paths via variadic parameters
- Refactored internal architecture to use object-oriented approach with generators
- Improved inline comment handling with proper whitespace trimming using `rtrim()`
- Enhanced quoted value parsing for single-line quoted values
- Better separation of concerns with dedicated methods for file parsing and processing
- Updated method signatures with proper type declarations
- Improved error messages format and consistency

### Fixed
- Trailing whitespace in values with inline comments now properly trimmed
- Better handling of quoted values that end on the same line
- Improved quote character detection and processing
- Fixed edge cases in environment variable parsing

### Technical Improvements
- Added PHP-CS-Fixer configuration for consistent code style
- Enhanced test coverage for new parsing improvements
- Better code organization with private methods and proper encapsulation
- Improved variable expansion logic
- More robust file handling and validation

### Migration Guide from v1.x to v2.0

#### Breaking Changes
1. **Class is now final**: If you were extending the `Env` class, this is no longer possible.
2. **Removed `loadMultiple()` method**:
   ```php
   // v1.x
   Env::loadMultiple('/path/to/.env', '/path/to/another/.env');

   // v2.0
   Env::load('/path/to/.env', '/path/to/another/.env');
   ```
3. **Default file loading**: The `load()` method now automatically loads `.env` and `.env.local` files by default:
   ```php
   // v2.0 - loads .env, .env.local, and custom.env
   Env::load('custom.env');

   // To disable default loading:
   Env::$disableDefaultPaths = true;
   Env::load('custom.env'); // Only loads custom.env
   ```

## [1.x] - Previous Versions
- Initial releases with basic .env file parsing functionality
- Support for variable interpolation
- Basic type conversion
- Multiline values support
