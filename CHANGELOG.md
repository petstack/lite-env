# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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