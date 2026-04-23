#!/usr/bin/env php
<?php

declare(strict_types=1);

use PetStack\LiteEnv\Env;

require \dirname(__DIR__) . '/vendor/autoload.php';

const DEFAULT_ITERATIONS = 10;
const DEFAULT_WARMUP = 2;
const DEFAULT_LINES = 10000;
const DEFAULT_SCENARIO = 'mixed';

main($argv);

/**
 * @param array<int, string> $argv
 */
function main(array $argv): void
{
    $options = parseOptions($argv);

    if ($options['worker']) {
        runWorker($options);
        return;
    }

    $file = $options['file'];
    $generatedFile = false;

    if ($file === null) {
        $file = createFixture($options['scenario'], $options['lines']);
        $generatedFile = true;
    }

    $scriptPath = __FILE__;

    try {
        $warmupResults = [];
        for ($iteration = 0; $iteration < $options['warmup']; $iteration++) {
            $warmupResults[] = runWorkerProcess($scriptPath, $file);
        }

        $results = [];
        for ($iteration = 0; $iteration < $options['iterations']; $iteration++) {
            $results[] = runWorkerProcess($scriptPath, $file);
        }

        printReport(
            $file,
            $generatedFile,
            $options['scenario'],
            $options['iterations'],
            $options['warmup'],
            $results,
            $warmupResults
        );
    } finally {
        if ($generatedFile && \is_file($file)) {
            \unlink($file);
        }
    }
}

/**
 * @param array<int, string> $argv
 * @return array{
 *     file: ?string,
 *     iterations: int,
 *     lines: int,
 *     scenario: string,
 *     warmup: int,
 *     worker: bool
 * }
 */
function parseOptions(array $argv): array
{
    $options = [
        'file' => null,
        'iterations' => DEFAULT_ITERATIONS,
        'lines' => DEFAULT_LINES,
        'scenario' => DEFAULT_SCENARIO,
        'warmup' => DEFAULT_WARMUP,
        'worker' => false,
    ];

    foreach (\array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            printUsage();
            exit(0);
        }

        if ($arg === '--worker') {
            $options['worker'] = true;
            continue;
        }

        if (!\str_starts_with($arg, '--') || !\str_contains($arg, '=')) {
            throw new InvalidArgumentException('Unsupported argument: ' . $arg);
        }

        [$name, $value] = \explode('=', \substr($arg, 2), 2);

        switch ($name) {
            case 'file':
                $options['file'] = $value;
                break;

            case 'iterations':
                $options['iterations'] = requirePositiveInt($name, $value);
                break;

            case 'lines':
                $options['lines'] = requirePositiveInt($name, $value);
                break;

            case 'scenario':
                $scenarios = ['simple', 'mixed', 'interpolation'];
                if (!\in_array($value, $scenarios, true)) {
                    throw new InvalidArgumentException(
                        'Unsupported scenario "' . $value . '". Allowed: ' . \implode(', ', $scenarios)
                    );
                }

                $options['scenario'] = $value;
                break;

            case 'warmup':
                $options['warmup'] = requireNonNegativeInt($name, $value);
                break;

            default:
                throw new InvalidArgumentException('Unknown option: --' . $name);
        }
    }

    if ($options['file'] !== null && !\is_file($options['file'])) {
        throw new InvalidArgumentException('Benchmark file not found: ' . $options['file']);
    }

    return $options;
}

function printUsage(): void
{
    $usage = <<<TXT
Usage:
  php bench/benchmark.php [--file=/path/to/.env] [--iterations=10] [--warmup=2]
                          [--lines=10000] [--scenario=mixed]

Options:
  --file         Benchmark an existing .env file instead of generating one
  --iterations   Number of measured runs (default: 10)
  --warmup       Number of warmup runs excluded from the summary (default: 2)
  --lines        Number of generated logical entries when --file is omitted
  --scenario     Generated fixture type: simple, mixed, interpolation
  --help         Show this help
TXT;

    echo $usage . PHP_EOL;
}

function requirePositiveInt(string $name, string $value): int
{
    if (!\ctype_digit($value) || $value === '0') {
        throw new InvalidArgumentException('--' . $name . ' must be a positive integer');
    }

    return (int) $value;
}

function requireNonNegativeInt(string $name, string $value): int
{
    if (!\ctype_digit($value)) {
        throw new InvalidArgumentException('--' . $name . ' must be a non-negative integer');
    }

    return (int) $value;
}

/**
 * @return array{
 *     duration_ns: int,
 *     memory_peak_real_delta_bytes: int,
 *     loaded_keys: int,
 *     memory_delta_bytes: int,
 *     memory_end_bytes: int,
 *     memory_peak_delta_bytes: int,
 *     memory_peak_bytes: int,
 *     memory_real_delta_bytes: int,
 *     memory_real_end_bytes: int,
 *     memory_real_peak_bytes: int,
 *     memory_real_start_bytes: int,
 *     memory_start_bytes: int
 * }
 */
function runWorkerProcess(string $scriptPath, string $file): array
{
    $command = \sprintf(
        '%s %s --worker --file=%s',
        \escapeshellarg(PHP_BINARY),
        \escapeshellarg($scriptPath),
        \escapeshellarg($file)
    );

    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = \proc_open($command, $descriptors, $pipes, \dirname(__DIR__));
    if (!\is_resource($process)) {
        throw new RuntimeException('Failed to start benchmark worker process');
    }

    $stdout = (string) \stream_get_contents($pipes[1]);
    \fclose($pipes[1]);

    $stderr = (string) \stream_get_contents($pipes[2]);
    \fclose($pipes[2]);

    $exitCode = \proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException(
            "Benchmark worker failed with exit code {$exitCode}.\n" . \trim($stderr)
        );
    }

    $result = \json_decode($stdout, true);
    if (!\is_array($result)) {
        throw new RuntimeException('Invalid worker output: ' . $stdout);
    }

    return $result;
}

/**
 * @param array{
 *     file: ?string,
 *     iterations: int,
 *     lines: int,
 *     scenario: string,
 *     warmup: int,
 *     worker: bool
 * } $options
 */
function runWorker(array $options): void
{
    $file = $options['file'];
    if ($file === null) {
        throw new InvalidArgumentException('--file is required in worker mode');
    }

    resetEnvState();

    if (\function_exists('gc_collect_cycles')) {
        \gc_collect_cycles();
    }

    if (\function_exists('gc_mem_caches')) {
        \gc_mem_caches();
    }

    if (\function_exists('memory_reset_peak_usage')) {
        \memory_reset_peak_usage();
    }

    $startRealMemory = \memory_get_usage(false);
    $startMemory = \memory_get_usage(true);
    $start = \hrtime(true);

    Env::load($file);

    $duration = \hrtime(true) - $start;
    $endRealMemory = \memory_get_usage(false);
    $endMemory = \memory_get_usage(true);
    $peakRealMemory = \memory_get_peak_usage(false);
    $peakMemory = \memory_get_peak_usage(true);

    echo \json_encode([
        'duration_ns' => $duration,
        'loaded_keys' => \count(Env::getAllKeys()),
        'memory_delta_bytes' => \max(0, $endMemory - $startMemory),
        'memory_end_bytes' => $endMemory,
        'memory_peak_delta_bytes' => \max(0, $peakMemory - $startMemory),
        'memory_peak_bytes' => $peakMemory,
        'memory_peak_real_delta_bytes' => \max(0, $peakRealMemory - $startRealMemory),
        'memory_real_delta_bytes' => \max(0, $endRealMemory - $startRealMemory),
        'memory_real_end_bytes' => $endRealMemory,
        'memory_real_peak_bytes' => $peakRealMemory,
        'memory_real_start_bytes' => $startRealMemory,
        'memory_start_bytes' => $startMemory,
    ], \JSON_THROW_ON_ERROR);
}

function resetEnvState(): void
{
    Env::$disableDefaultPaths = true;

    foreach (Env::getAllKeys() as $key) {
        \putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }

    $reflection = new ReflectionClass(Env::class);
    $cacheProperty = $reflection->getProperty('cacheKeys');
    if (\PHP_VERSION_ID < 80500) {
        $cacheProperty->setAccessible(true);
    }
    $cacheProperty->setValue(null, []);
}

function createFixture(string $scenario, int $lines): string
{
    $path = \sprintf(
        '%s/lite-env-bench-%s-%d.env',
        \sys_get_temp_dir(),
        $scenario,
        \getmypid()
    );

    \file_put_contents($path, buildFixtureContents($scenario, $lines));

    return $path;
}

function buildFixtureContents(string $scenario, int $lines): string
{
    return match ($scenario) {
        'simple' => buildSimpleFixture($lines),
        'interpolation' => buildInterpolationFixture($lines),
        default => buildMixedFixture($lines),
    };
}

function buildSimpleFixture(int $lines): string
{
    $rows = [];

    for ($i = 0; $i < $lines; $i++) {
        $rows[] = 'KEY_' . $i . '=value_' . $i;
    }

    return \implode("\n", $rows) . "\n";
}

function buildInterpolationFixture(int $lines): string
{
    $rows = [];

    for ($i = 0; $i < $lines; $i++) {
        $baseKey = 'BASE_' . $i;
        $pathKey = 'PATH_' . $i;

        $rows[] = $baseKey . '=/srv/app/' . $i;
        $rows[] = $pathKey . '=${' . $baseKey . '}/cache/file_' . $i;
    }

    return \implode("\n", $rows) . "\n";
}

function buildMixedFixture(int $lines): string
{
    $rows = [];

    for ($i = 0; $i < $lines; $i++) {
        $baseKey = 'BASE_' . $i;
        $copyKey = 'COPY_' . $i;
        $quoteKey = 'QUOTE_' . $i;
        $commentKey = 'COMMENT_' . $i;
        $multiKey = 'MULTI_' . $i;

        $rows[] = $baseKey . '=/srv/app/' . $i;
        $rows[] = $copyKey . '=${' . $baseKey . '}/var/cache';
        $rows[] = $quoteKey . '="value ' . $i . ' with spaces"';
        $rows[] = $commentKey . '=value_' . $i . ' # inline comment';
        $rows[] = $multiKey . '="line_' . $i;
        $rows[] = 'line_' . $i . '_continued"';
    }

    return \implode("\n", $rows) . "\n";
}

/**
 * @param array<int, array{
 *     duration_ns: int,
 *     memory_peak_real_delta_bytes: int,
 *     loaded_keys: int,
 *     memory_delta_bytes: int,
 *     memory_end_bytes: int,
 *     memory_peak_delta_bytes: int,
 *     memory_peak_bytes: int,
 *     memory_real_delta_bytes: int,
 *     memory_real_end_bytes: int,
 *     memory_real_peak_bytes: int,
 *     memory_real_start_bytes: int,
 *     memory_start_bytes: int
 * }> $results
 * @param array<int, array{
 *     duration_ns: int,
 *     memory_peak_real_delta_bytes: int,
 *     loaded_keys: int,
 *     memory_delta_bytes: int,
 *     memory_end_bytes: int,
 *     memory_peak_delta_bytes: int,
 *     memory_peak_bytes: int,
 *     memory_real_delta_bytes: int,
 *     memory_real_end_bytes: int,
 *     memory_real_peak_bytes: int,
 *     memory_real_start_bytes: int,
 *     memory_start_bytes: int
 * }> $warmupResults
 */
function printReport(
    string $file,
    bool $generatedFile,
    string $scenario,
    int $iterations,
    int $warmup,
    array $results,
    array $warmupResults,
): void {
    $fileSize = \filesize($file);
    if ($fileSize === false) {
        throw new RuntimeException('Failed to read benchmark file size: ' . $file);
    }

    echo 'Benchmark target: ' . $file . PHP_EOL;
    echo 'Generated file: ' . ($generatedFile ? 'yes' : 'no') . PHP_EOL;
    echo 'Scenario: ' . $scenario . PHP_EOL;
    echo 'File size: ' . formatBytes($fileSize) . PHP_EOL;
    echo 'Warmup runs: ' . $warmup . PHP_EOL;
    echo 'Measured runs: ' . $iterations . PHP_EOL;
    echo PHP_EOL;

    if ($warmupResults !== []) {
        echo 'Warmup:' . PHP_EOL;
        foreach ($warmupResults as $index => $result) {
            printf(
                "  #%d  time=%s  peak_used=%s  peak_real=%s  keys=%d\n",
                $index + 1,
                formatDuration($result['duration_ns']),
                formatBytes($result['memory_peak_real_delta_bytes']),
                formatBytes($result['memory_peak_delta_bytes']),
                $result['loaded_keys']
            );
        }
        echo PHP_EOL;
    }

    echo "Runs:\n";
    foreach ($results as $index => $result) {
        printf(
            "  #%d  time=%s  used=%s  peak_used=%s  real=%s  peak_real=%s  keys=%d\n",
            $index + 1,
            formatDuration($result['duration_ns']),
            formatBytes($result['memory_real_delta_bytes']),
            formatBytes($result['memory_peak_real_delta_bytes']),
            formatBytes($result['memory_delta_bytes']),
            formatBytes($result['memory_peak_delta_bytes']),
            $result['loaded_keys']
        );
    }

    echo PHP_EOL;
    echo 'Summary:' . PHP_EOL;
    echo '  time avg:  ' . formatDuration((int) average(\array_column($results, 'duration_ns'))) . PHP_EOL;
    echo '  time min:  ' . formatDuration(\min(\array_column($results, 'duration_ns'))) . PHP_EOL;
    echo '  time max:  ' . formatDuration(\max(\array_column($results, 'duration_ns'))) . PHP_EOL;
    echo '  used avg:  ' . formatBytes((int) average(\array_column($results, 'memory_real_delta_bytes'))) . PHP_EOL;
    echo '  used min:  ' . formatBytes(\min(\array_column($results, 'memory_real_delta_bytes'))) . PHP_EOL;
    echo '  used max:  ' . formatBytes(\max(\array_column($results, 'memory_real_delta_bytes'))) . PHP_EOL;
    echo '  peak used avg:  ' . formatBytes((int) average(\array_column($results, 'memory_peak_real_delta_bytes'))) . PHP_EOL;
    echo '  peak used min:  ' . formatBytes(\min(\array_column($results, 'memory_peak_real_delta_bytes'))) . PHP_EOL;
    echo '  peak used max:  ' . formatBytes(\max(\array_column($results, 'memory_peak_real_delta_bytes'))) . PHP_EOL;
    echo '  real avg:  ' . formatBytes((int) average(\array_column($results, 'memory_delta_bytes'))) . PHP_EOL;
    echo '  real min:  ' . formatBytes(\min(\array_column($results, 'memory_delta_bytes'))) . PHP_EOL;
    echo '  real max:  ' . formatBytes(\max(\array_column($results, 'memory_delta_bytes'))) . PHP_EOL;
    echo '  peak real avg:  ' . formatBytes((int) average(\array_column($results, 'memory_peak_delta_bytes'))) . PHP_EOL;
    echo '  peak real min:  ' . formatBytes(\min(\array_column($results, 'memory_peak_delta_bytes'))) . PHP_EOL;
    echo '  peak real max:  ' . formatBytes(\max(\array_column($results, 'memory_peak_delta_bytes'))) . PHP_EOL;
    echo '  keys avg:  ' . number_format(average(\array_column($results, 'loaded_keys')), 2, '.', '') . PHP_EOL;
}

/**
 * @param array<int, int> $values
 */
function average(array $values): float
{
    return \array_sum($values) / \count($values);
}

function formatDuration(int $nanoseconds): string
{
    if ($nanoseconds < 1_000) {
        return $nanoseconds . ' ns';
    }

    if ($nanoseconds < 1_000_000) {
        return \number_format($nanoseconds / 1_000, 2, '.', '') . ' us';
    }

    if ($nanoseconds < 1_000_000_000) {
        return \number_format($nanoseconds / 1_000_000, 2, '.', '') . ' ms';
    }

    return \number_format($nanoseconds / 1_000_000_000, 2, '.', '') . ' s';
}

function formatBytes(int $bytes): string
{
    $units = ['B', 'KiB', 'MiB', 'GiB'];
    $value = (float) $bytes;
    $unit = 0;

    while ($value >= 1024 && $unit < \count($units) - 1) {
        $value /= 1024;
        $unit++;
    }

    return \number_format($value, $unit === 0 ? 0 : 2, '.', '') . ' ' . $units[$unit];
}
