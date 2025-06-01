<?php

declare(strict_types=1);

namespace PetStack\LiteEnv;

use RuntimeException;

class Env
{
    private const ENV_DEFAULT_PATH = '.env';
    private const VALID_KEY_PATTERN = '/^[a-zA-Z_]\w*$/';
    private const VARIABLE_PATTERN_COMBINED = '/(?:\$\{(?>[A-Z_][A-Z0-9_]*)\})|(?:(?<!\\\\)\$(?>([A-Z_][A-Z0-9_]*)))/';

    private static array $cacheKeys = [];

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

    private static function validateFile(string $path): void
    {
        if (!\is_file($path)) {
            throw new RuntimeException(\sprintf('The file "%s" does not exist', $path));
        }
        if (!\is_readable($path)) {
            throw new RuntimeException(\sprintf('The file "%s" exists but cannot be read', $path));
        }
    }

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

    private static function setEnvironmentVariable(string $key, string $value): void
    {
        self::$cacheKeys[$key] = true;
        $value = self::expandVariables($value);
        $value = self::convertType($value);
        \putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

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

    public static function getAllKeys(): array
    {
        return \array_keys(self::$cacheKeys);
    }

    private static function isValidKey(string $key): bool
    {
        return \preg_match(self::VALID_KEY_PATTERN, $key) === 1;
    }

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

    public static function has(string $key): bool
    {
        return isset(self::$cacheKeys[$key])
            || \getenv($key) !== false
            || isset($_ENV[$key])
            || isset($_SERVER[$key]);
    }
}
