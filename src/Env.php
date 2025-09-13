<?php

declare(strict_types=1);

namespace PetStack\LiteEnv;

use RuntimeException;

final class Env
{
    private const array ENV_DEFAULT_PATHS = ['.env', '.env.local'];

    private const string VALID_KEY_PATTERN = '/^[A-Z_][A-Z0-9_]*$/';
    private const string VARIABLE_PATTERN_COMBINED = '/(?:\$\{(?>[A-Z_][A-Z0-9_]*)\})|(?:(?<!\\\\)\$(?>([A-Z_][A-Z0-9_]*)))/';

    private static array $cacheKeys = [];

    /**
     * Controls whether default .env files are loaded automatically.
     *
     * When set to false (default), the load() method will automatically load
     * .env and .env.local files from the current directory if they exist.
     * When set to true, only explicitly specified files are loaded.
     *
     * @var bool
     */
    public static bool $disableDefaultPaths = false;

    /**
     * Private constructor to prevent direct instantiation.
     *
     * The Env class is designed to work with static methods only.
     * This constructor ensures the class follows a static-only pattern.
     */
    private function __construct()
    {
    }

    /**
     * Loads environment variables from .env files.
     *
     * By default, this method loads .env and .env.local files from the current
     * directory (if they exist), followed by any explicitly specified files.
     * The loading order ensures that later files can override earlier ones.
     *
     * @param string ...$paths Zero or more paths to additional .env files to load
     * @return void
     * @throws RuntimeException If a specified file doesn't exist or cannot be read
     */
    public static function load(string ...$paths): void
    {
        $env = new Env();

        foreach (self::$disableDefaultPaths ? $paths : [...self::ENV_DEFAULT_PATHS, ...$paths] as $file) {
            if (\in_array($file, self::ENV_DEFAULT_PATHS, true) && !\is_file($file)) {
                continue;
            }

            if (!\is_file($file)) {
                throw new RuntimeException('The file "' . $file . '" does not exist');
            }
            if (!\is_readable($file)) {
                throw new RuntimeException('The file "' . $file . '" exists but cannot be read');
            }

            foreach ($env->parseFile($file) as $key => $value) {
                $env->setEnvironmentVariable($key, $value);
            }
        }
    }

    /**
     * Parses a .env file and yields key-value pairs.
     *
     * This method reads a .env file line by line and parses each line to extract
     * environment variable key-value pairs. It handles multiline values, quoted
     * strings, comments, and variable interpolation.
     *
     * @param string $file The path to the .env file to parse
     * @return \Generator<string, string> Yields key-value pairs of environment variables
     * @throws RuntimeException If the file cannot be opened
     */
    private function parseFile(string $file): \Generator
    {
        $stream = \fopen($file, 'r');
        if ($stream === false) {
            throw new RuntimeException('Failed to open file "' . $file . '"');
        }

        $inQuotes = false;
        $quoteChar = '';
        $value = '';
        $key = '';

        while (($line = \fgets($stream)) !== false) {
            $line = \str_replace(["\r\n", "\r"], "\n", $line);

            try {
                [
                    'inQuotes' => $inQuotes,
                    'quoteChar' => $quoteChar,
                    'value' => $value,
                    'key' => $key,
                    'shouldSetVariable' => $shouldSetVariable,
                ] = $this->processLine($line, $inQuotes, $quoteChar, $value, $key);
            } catch (RuntimeException $e) {
                \trigger_error(
                    'Syntax error in line "' . $line . "\": " . $e->getMessage(),
                    \E_USER_WARNING
                );

                continue;
            }

            if ($shouldSetVariable) {
                yield $key => $value;
            }
        }

        \fclose($stream);
    }

    /**
     * Processes a single line from a .env file.
     *
     * This method parses a line from a .env file to extract key-value pairs.
     * It handles various formats including quoted values, empty values, and comments.
     * For multiline values, it tracks the state and continues processing in subsequent calls.
     *
     * @param string $line The line to process from the .env file
     * @param bool $inQuotes Whether the current processing state is inside a quoted value
     * @param string $quoteChar The quote character (single or double) if inside a quoted value
     * @param string $value The current value being built (for multiline values)
     * @param string $key The current key being processed
     * @return array{inQuotes: bool, quoteChar: string, value: string, key: string, shouldSetVariable: bool} Processing state and results
     * @throws RuntimeException If the line has invalid format
     */
    private function processLine(
        string $line,
        bool $inQuotes,
        string $quoteChar,
        string $value,
        string $key,
    ): array {
        if ($inQuotes) {
            return $this->handleQuotedValue($line, $quoteChar, $value, $key);
        }

        $firstChar = \ltrim($line)[0] ?? null;
        if ($firstChar === null || $firstChar === '#') {
            return [
                'inQuotes' => false,
                'quoteChar' => '',
                'value' => '',
                'key' => '',
                'shouldSetVariable' => false,
            ];
        }

        $equals = \strpos($line, '=');
        if ($equals === false) {
            throw new RuntimeException('Invalid line: ' . $line . ". Missing equals sign in line.");
        }

        $key = \trim(\substr($line, 0, $equals));
        if (!self::isValidKey($key)) {
            throw new RuntimeException(
                'Invalid key format: "' . $key . '". Keys must start with a letter or underscore and can only contain letters, numbers, and underscores.'
            );
        }

        $value = \trim(\substr($line, $equals + 1));
        if (empty($value)) {
            return [
                'inQuotes' => false,
                'quoteChar' => '',
                'value' => '',
                'key' => $key,
                'shouldSetVariable' => true,
            ];
        }

        if ($value[0] === '"' || $value[0] === "'") {
            $quoteChar = $value[0];
            $lastChar = $value[\strlen($value) - 1];

            if ($lastChar === $quoteChar) {
                return [
                    'inQuotes' => false,
                    'quoteChar' => '',
                    'value' => \substr($value, 1, -1),
                    'key' => $key,
                    'shouldSetVariable' => true,
                ];
            }

            $value = \substr($line, $equals + 2);

            if (\str_contains($value, $quoteChar)) {
                return [
                    'inQuotes' => false,
                    'quoteChar' => '',
                    'value' => \substr($value, 0, \strpos(\substr($line, $equals + 2), $quoteChar)),
                    'key' => $key,
                    'shouldSetVariable' => true,
                ];
            }

            return [
                'inQuotes' => true,
                'quoteChar' => $quoteChar,
                'value' => \substr($line, $equals + 2),
                'key' => $key,
                'shouldSetVariable' => false,
            ];
        }

        if (\str_contains($value, ' #')) {
            $value = \rtrim(\substr($value, 0, \strpos($value, ' #')));
        }

        return [
            'inQuotes' => false,
            'quoteChar' => '',
            'value' => $value,
            'key' => $key,
            'shouldSetVariable' => true,
        ];
    }

    /**
     * Handles processing of a quoted value that may span multiple lines.
     *
     * This method continues processing a quoted value from a previous line.
     * It checks if the current line contains the closing quote character and
     * either completes the value or continues building it.
     *
     * @param string $line The current line being processed
     * @param string $quoteChar The quote character (single or double) being used
     * @param string $value The value accumulated so far
     * @param string $key The environment variable key
     * @return array{inQuotes: bool, quoteChar: string, value: string, key: string, shouldSetVariable: bool} Updated processing state
     */
    private function handleQuotedValue(string $line, string $quoteChar, string $value, string $key): array
    {
        $rTrimLine = \rtrim($line);
        if (($rTrimLine[\strlen($rTrimLine) - 1] ?? null) !== $quoteChar) {
            return [
                'inQuotes' => true,
                'quoteChar' => $quoteChar,
                'value' => $value . $line,
                'key' => $key,
                'shouldSetVariable' => false,
            ];
        }

        return [
            'inQuotes' => false,
            'quoteChar' => '',
            'value' => $value . \substr($rTrimLine, 0, -1),
            'key' => $key,
            'shouldSetVariable' => true,
        ];
    }

    /**
     * Sets an environment variable in all relevant places.
     *
     * This method sets an environment variable using PHP's putenv() function
     * and also updates the $_ENV and $_SERVER superglobals. It expands any
     * variable references and converts the value to the appropriate type.
     *
     * @param string $key The environment variable name
     * @param string $value The environment variable value (before processing)
     * @return void
     */
    private function setEnvironmentVariable(string $key, string $value): void
    {
        self::$cacheKeys[$key] = true;
        $value = $this->expandVariables($value);
        $value = self::convertType($value);
        \putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    /**
     * Expands variable references within a value string.
     *
     * This method processes variable interpolation by replacing references
     * like ${VAR_NAME} or $VAR_NAME with their actual values from the environment.
     * It handles escaped dollar signs (\$) by preserving them as literal characters.
     *
     * @param string $value The value string that may contain variable references
     * @return string The value with all variable references expanded
     */
    private function expandVariables(string $value): string
    {
        // Handle escaped dollar signs first
        $value = \str_replace('\\$', '___ESCAPED_DOLLAR___', $value);

        // Extract variable names from the matches
        $value = \preg_replace_callback(
            self::VARIABLE_PATTERN_COMBINED,
            static function (array $matches): string {
                // Determine if it's a braced or simple variable
                if (\str_starts_with($matches[0], '${')) {
                    // Extract variable name from ${VAR} format
                    $varName = \substr($matches[0], 2, -1);
                } else {
                    // Extract variable name from $VAR format
                    $varName = $matches[1] ?? '';
                }

                return self::get($varName);
            },
            $value
        );

        // Restore escaped dollar signs
        return \str_replace('___ESCAPED_DOLLAR___', '$', $value);
    }

    /**
     * Checks if an environment variable exists.
     *
     * This method checks for the existence of an environment variable in multiple
     * locations: the internal cache, PHP's getenv(), $_ENV, and $_SERVER superglobals.
     *
     * @param string $key The environment variable name to check
     * @return bool True if the variable exists, false otherwise
     */
    public static function has(string $key): bool
    {
        return isset(self::$cacheKeys[$key])
            || \getenv($key) !== false
            || isset($_ENV[$key])
            || isset($_SERVER[$key]);
    }

    /**
     * Retrieves an environment variable value.
     *
     * This method attempts to retrieve an environment variable from multiple sources
     * in the following order: getenv(), $_ENV, $_SERVER. If the variable is found
     * as a string, it's automatically converted to the appropriate PHP type.
     *
     * @param string $key The environment variable name
     * @param string|int|float|bool|null $default The default value to return if variable doesn't exist
     * @return string|int|float|bool|null The environment variable value or default
     * @throws RuntimeException If the key format is invalid
     */
    public static function get(string $key, string|int|float|bool|null $default = null): string|int|float|bool|null
    {
        if (!self::isValidKey($key)) {
            throw new RuntimeException('Invalid key format: "' . $key . '". Keys must start with a letter or underscore and can only contain letters, numbers, and underscores.');
        }

        // Try to get from environment
        $value = \getenv($key);

        // If not found in getenv, try $_ENV and $_SERVER
        if ($value === false) {
            $value = match (true) {
                isset($_ENV[$key]) => $_ENV[$key],
                isset($_SERVER[$key]) => $_SERVER[$key],
                default => $default,
            };
        }

        if (\is_string($value)) {
            return self::convertType($value);
        }

        return $value;
    }

    /**
     * Returns all environment variable keys that were loaded from files.
     *
     * This method returns an array of all environment variable names that
     * were successfully loaded and cached by this library. Note that this
     * only includes variables loaded through this class, not all environment variables.
     *
     * @return array<string> Array of environment variable names
     */
    public static function getAllKeys(): array
    {
        return \array_keys(self::$cacheKeys);
    }

    /**
     * Validates an environment variable key format.
     *
     * Environment variable keys must follow specific naming conventions:
     * - Must start with a letter (A-Z) or underscore (_)
     * - Can contain only uppercase letters, numbers, and underscores
     * - Must not be empty
     *
     * @param string $key The environment variable key to validate
     * @return bool True if the key format is valid, false otherwise
     */
    private static function isValidKey(string $key): bool
    {
        return \preg_match(self::VALID_KEY_PATTERN, $key) === 1;
    }

    /**
     * Converts a string value to its appropriate PHP type.
     *
     * This method performs automatic type conversion for common value patterns:
     * - 'true', '(true)' become boolean true
     * - 'false', '(false)' become boolean false
     * - 'null', '(null)' become null
     * - 'empty', '(empty)', '""', "''" become empty string
     * - Numeric strings become integers or floats
     * - All other values remain as strings
     *
     * @param string $value The string value to convert
     * @return mixed The value converted to its appropriate PHP type
     */
    private static function convertType(string $value): mixed
    {
        return match (\strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)', '""', "''" => '',
            default => \is_numeric($value)
                ? (\str_contains($value, '.') ? (float) $value : (int) $value)
                : $value,
        };
    }
}
