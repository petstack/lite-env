<?php

declare(strict_types=1);

namespace PetStack\LiteEnv;

use RuntimeException;

class Env
{
    private const ENV_DEFAULT_PATH = '.env';
    private const VALID_KEY_PATTERN = '/^[A-Z_][A-Z0-9_]*$/';
    private const VARIABLE_PATTERN_COMBINED = '/(?:\$\{(?>[A-Z_][A-Z0-9_]*)\})|(?:(?<!\\\\)\$(?>([A-Z_][A-Z0-9_]*)))/';

    private static array $cacheKeys = [];

    /**
     * Loads environment variables from multiple .env files.
     *
     * This method accepts multiple file paths or arrays of file paths and loads
     * environment variables from each file by calling the load() method.
     *
     * @param mixed ...$paths One or more file paths or arrays of file paths to .env files
     * @return void
     */
    public static function loadMultiple(...$paths): void
    {
        foreach ($paths as $path) {
            if (\is_array($path)) {
                self::loadMultiple(...$path);
            } else {
                self::load($path);
            }
        }
    }

    /**
     * Loads environment variables from a .env file.
     *
     * This method reads a .env file line by line, processes each line to extract
     * key-value pairs, and sets them as environment variables. It handles quoted values,
     * multiline values, and variable interpolation.
     *
     * @param string $path Path to the .env file (defaults to '.env')
     * @return void
     * @throws RuntimeException If the file doesn't exist, can't be read, or can't be opened
     */
    public static function load(string $path = self::ENV_DEFAULT_PATH): void
    {
        self::validateFile($path);

        $file = \fopen($path, 'r');
        if ($file === false) {
            throw new RuntimeException(\sprintf('Failed to open file "%s"', $path));
        }

        $inQuotes = false;
        $quoteChar = '';
        $value = '';
        $key = '';

        while (($line = \fgets($file)) !== false) {
            $line = \str_replace(["\r\n", "\r"], "\n", $line);

            try {
                [
                    'inQuotes' => $inQuotes,
                    'quoteChar' => $quoteChar,
                    'value' => $value,
                    'key' => $key,
                    'shouldSetVariable' => $shouldSetVariable,
                ] = self::processLine($line, $inQuotes, $quoteChar, $value, $key);
            } catch (RuntimeException $e) {
                \trigger_error(
                    sprintf("Syntax error in line \"%s\": %s", $line, $e->getMessage()),
                    \E_USER_WARNING
                );

                continue;
            }

            if ($shouldSetVariable) {
                self::setEnvironmentVariable($key, $value);
            }
        }

        \fclose($file);
    }

    /**
     * Validates that a file exists and is readable.
     *
     * This method checks if the specified file exists and is readable before
     * attempting to load environment variables from it.
     *
     * @param string $path Path to the file to validate
     * @return void
     * @throws RuntimeException If the file doesn't exist or can't be read
     */
    private static function validateFile(string $path): void
    {
        if (!\is_file($path)) {
            throw new RuntimeException(\sprintf('The file "%s" does not exist', $path));
        }
        if (!\is_readable($path)) {
            throw new RuntimeException(\sprintf('The file "%s" exists but cannot be read', $path));
        }
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
    private static function processLine(
        string $line,
        bool $inQuotes,
        string $quoteChar,
        string $value,
        string $key
    ): array {
        if ($inQuotes) {
            return self::handleQuotedValue($line, $quoteChar, $value, $key);
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
            throw new RuntimeException(\sprintf("Invalid line: %s. Missing equals sign in line.", $line));
        }

        $key = \trim(\substr($line, 0, $equals));
        if (!self::isValidKey($key)) {
            throw new RuntimeException(
                \sprintf(
                    'Invalid key format: "%s". Keys must start with a letter or underscore and can only contain letters, numbers, and underscores.',
                    $key
                )
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
                $value = \substr($value, 1, -1);
                return [
                    'inQuotes' => false,
                    'quoteChar' => '',
                    'value' => $value,
                    'key' => $key,
                    'shouldSetVariable' => true,
                ];
            }

            if (\str_contains($value, ' #')) {
                $value = \substr($value, 1, \strpos($value, ' #') - 2);
                return [
                    'inQuotes' => false,
                    'quoteChar' => '',
                    'value' => $value,
                    'key' => $key,
                    'shouldSetVariable' => true,
                ];
            }

            $value = \substr($line, $equals + 2);

            return [
                'inQuotes' => true,
                'quoteChar' => $quoteChar,
                'value' => $value,
                'key' => $key,
                'shouldSetVariable' => false,
            ];
        }

        if (\str_contains($value, ' #')) {
            $value = \substr($value, 0, \strpos($value, ' #'));
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
    private static function handleQuotedValue(string $line, string $quoteChar, string $value, string $key): array
    {
        $rTrimLine = \rtrim($line);
        if (($rTrimLine[\strlen($rTrimLine) - 1] ?? null) !== $quoteChar) {
            $value .= $line;
            return [
                'inQuotes' => true,
                'quoteChar' => $quoteChar,
                'value' => $value,
                'key' => $key,
                'shouldSetVariable' => false,
            ];
        }

        $value .= \substr($rTrimLine, 0, -1);
        return [
            'inQuotes' => false,
            'quoteChar' => '',
            'value' => $value,
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
    private static function setEnvironmentVariable(string $key, string $value): void
    {
        self::$cacheKeys[$key] = true;
        $value = self::expandVariables($value);
        $value = self::convertType($value);
        \putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    /**
     * Retrieves the value of an environment variable.
     *
     * This method gets the value of an environment variable from the environment.
     * It checks various sources (getenv, $_ENV, $_SERVER) and returns the value
     * with the appropriate type conversion. If the variable doesn't exist,
     * it returns the provided default value.
     *
     * @param string $key The environment variable name to retrieve
     * @param mixed $default The default value to return if the variable doesn't exist
     * @return mixed The value of the environment variable or the default value
     * @throws RuntimeException If the key format is invalid
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (!self::isValidKey($key)) {
            throw new RuntimeException(
                \sprintf(
                    'Invalid key format: "%s". Keys must start with a letter or underscore and can only contain letters, numbers, and underscores.',
                    $key
                )
            );
        }

        // If not in cache, try to get from environment
        $value = \getenv($key);

        // If not found in getenv, try $_ENV and $_SERVER
        if ($value === false) {
            if (isset($_ENV[$key])) {
                $value = $_ENV[$key];
            } elseif (isset($_SERVER[$key])) {
                $value = $_SERVER[$key];
            } else {
                return $default;
            }
        }

        if (\is_string($value)) {
            return self::convertType($value);
        }

        return $value;
    }

    /**
     * Returns all environment variable keys that have been loaded.
     *
     * This method returns an array of all environment variable keys that
     * have been loaded or set during the current execution.
     *
     * @return array<int, string> Array of environment variable keys
     */
    public static function getAllKeys(): array
    {
        return \array_keys(self::$cacheKeys);
    }

    /**
     * Validates if a string is a valid environment variable key.
     *
     * This method checks if a string conforms to the standard format for
     * environment variable keys: must start with a letter or underscore
     * and can only contain uppercase letters, numbers, and underscores.
     *
     * @param string $key The key to validate
     * @return bool True if the key is valid, false otherwise
     */
    private static function isValidKey(string $key): bool
    {
        return \preg_match(self::VALID_KEY_PATTERN, $key) === 1;
    }

    /**
     * Expands environment variable references within a string.
     *
     * This method replaces references to environment variables in the format
     * $VAR or ${VAR} with their actual values. It handles escaped dollar signs
     * and preserves them in the output.
     *
     * @param string $value The string containing potential variable references
     * @return string The string with all variable references expanded
     */
    private static function expandVariables(string $value): string
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
     * Converts a string value to an appropriate PHP type.
     *
     * This method converts string values to their appropriate PHP types based on content:
     * - "true" or "(true)" to boolean true
     * - "false" or "(false)" to boolean false
     * - "null" or "(null)" to null
     * - "empty", "(empty)", "", or '' to empty string
     * - Numeric strings to int or float
     * - All other strings remain as strings
     *
     * @param string $value The string value to convert
     * @return mixed The converted value with the appropriate PHP type
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

    /**
     * Checks if an environment variable exists.
     *
     * This method checks if an environment variable exists by looking in
     * the internal cache and various environment sources (getenv, $_ENV, $_SERVER).
     *
     * @param string $key The environment variable name to check
     * @return bool True if the environment variable exists, false otherwise
     */
    public static function has(string $key): bool
    {
        return isset(self::$cacheKeys[$key])
            || \getenv($key) !== false
            || isset($_ENV[$key])
            || isset($_SERVER[$key]);
    }
}
