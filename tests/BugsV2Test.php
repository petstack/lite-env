<?php

declare(strict_types=1);

namespace PetStack\LiteEnv\Tests;

use PetStack\LiteEnv\Env;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Regression tests for the parser and type-conversion bugs fixed in v2.2.0.
 *
 * Each test was written against the buggy v2.1.1 implementation and encodes
 * the correct behavior; the docblocks describe the original root causes.
 */
class BugsV2Test extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp();

        Env::$disableDefaultPaths = true;
        $this->fixtureDir = __DIR__ . '/fixture';

        foreach (Env::getAllKeys() as $key) {
            \putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        $reflectionClass = new \ReflectionClass(Env::class);
        $cacheProperty = $reflectionClass->getProperty('cacheKeys');
        $cacheProperty->setValue(null, []);

        \error_reporting(\E_ALL & ~\E_USER_WARNING);
    }

    private function writeFixture(string $name, string $contents): string
    {
        $path = $this->fixtureDir . '/' . $name;
        \file_put_contents($path, $contents);
        return $path;
    }

    private function removeFixture(string $path): void
    {
        if (\file_exists($path)) {
            \unlink($path);
        }
    }

    /**
     * Bug #1 (fixed): `KEY=0` became an empty string.
     *
     * Root cause: processLine() used `empty($value)` to detect empty values,
     * but empty('0') is true in PHP, so the value '0' was replaced with ''.
     * With type conversion enabled the correct result is int 0 (consistent
     * with PORT=3000 becoming int 3000).
     */
    public function testZeroValueMustNotBecomeEmptyString(): void
    {
        $path = $this->writeFixture('bug2_zero.env', "ZERO=0\n");
        try {
            Env::load($path);
            self::assertSame(0, Env::get('ZERO'));
        } finally {
            $this->removeFixture($path);
        }
    }

    /**
     * Bug #2 (fixed): the last variable in a file was processed twice.
     *
     * parseFile() yields the accumulated pair after the read loop to avoid
     * dropping an unterminated quoted value at EOF, but $key was not reset
     * after a successful yield inside the loop, so the raw value of the last
     * variable was expanded a second time after the variable itself had
     * already been set. A self-reference makes this observable: `B=$B!`
     * must produce '!' ($B is undefined on the only legitimate expansion
     * pass), not '!!'.
     */
    public function testLastVariableMustNotBeYieldedTwice(): void
    {
        $path = $this->writeFixture('bug2_double_yield.env', "A=x\nB=\$B!\n");
        try {
            Env::load($path);
            self::assertSame('!', Env::get('B'));
        } finally {
            $this->removeFixture($path);
        }
    }

    /**
     * Bug #3a (fixed): leading zeros were stripped by numeric conversion.
     *
     * is_numeric('01234') is true, so convertType() cast the value to
     * int 1234, silently corrupting zip codes, phone numbers and numeric
     * IDs with leading zeros. Integer conversion now only applies to
     * canonical values validated by FILTER_VALIDATE_INT.
     */
    public function testLeadingZerosMustBePreserved(): void
    {
        $path = $this->writeFixture('bug2_leading_zeros.env', "ZIP=01234\n");
        try {
            Env::load($path);
            self::assertSame('01234', Env::get('ZIP'));
        } finally {
            $this->removeFixture($path);
        }
    }

    /**
     * Bug #3b (fixed): version-like values lost precision.
     *
     * '1.10' was cast to float 1.1, so the original text could no longer be
     * recovered ((string) 1.1 === '1.1'). Float conversion now only applies
     * when the cast survives a round trip back to the original string.
     */
    public function testVersionLikeValueMustNotLosePrecision(): void
    {
        $path = $this->writeFixture('bug2_version.env', "VERSION=1.10\n");
        try {
            Env::load($path);
            self::assertSame('1.10', Env::get('VERSION'));
        } finally {
            $this->removeFixture($path);
        }
    }

    /**
     * Bug #3c (fixed): numeric strings beyond PHP_INT_MAX overflowed.
     *
     * convertType() cast any dot-free numeric string to int, so a 23-digit
     * ID emitted "not representable as an int" warnings and silently became
     * PHP_INT_MAX. Such values are now kept as strings.
     */
    public function testHugeNumericValueMustNotOverflow(): void
    {
        $path = $this->writeFixture('bug2_big_number.env', "ID=12345678901234567890123\n");
        try {
            Env::load($path);
            self::assertSame('12345678901234567890123', Env::get('ID'));
        } finally {
            $this->removeFixture($path);
        }
    }

    /**
     * Bug #4a (fixed): whitespace between `=` and the opening quote broke
     * multiline values.
     *
     * processLine() trimmed the value to detect the opening quote, but then
     * re-sliced the raw line with `substr($line, $equals + 2)`, assuming the
     * quote sat immediately after `=`. With `K= "line1` the slice started at
     * the quote itself, the quote was mistaken for the closing one, and the
     * value collapsed to ''. The slice now starts after the actual position
     * of the opening quote.
     */
    public function testWhitespaceBeforeOpeningQuoteMustNotBreakMultiline(): void
    {
        $path = $this->writeFixture('bug2_space_quote.env', "K= \"line1\nline2\"\n");
        try {
            Env::load($path);
            self::assertSame("line1\nline2", Env::get('K'));
        } finally {
            $this->removeFixture($path);
        }
    }

    /**
     * Bug #4b (fixed): an opening quote alone on the first line produced an
     * empty value instead of starting a multiline one.
     *
     * For `K="` the trimmed value is the single character '"'; its first and
     * last characters are the same quote, so processLine() treated it as a
     * complete quoted empty value and the remaining lines of the real value
     * were lost. The same-line-close branch now requires a length above 1.
     */
    public function testOpeningQuoteAloneMustStartMultilineValue(): void
    {
        $path = $this->writeFixture('bug2_lone_quote.env', "K=\"\nrest\"\n");
        try {
            Env::load($path);
            self::assertSame("\nrest", Env::get('K'));
        } finally {
            $this->removeFixture($path);
        }
    }

    /**
     * Bug #5 (fixed): an inline comment after the closing quote of a
     * multiline value prevented the quote from ever closing.
     *
     * handleQuotedValue() only closed the value when the quote was the last
     * non-whitespace character of the line, so with `b" # comment` the
     * parser stayed inQuotes and swallowed the rest of the file. The value
     * now closes at the first occurrence of the quote character, consistent
     * with the single-line parsing path.
     */
    public function testCommentAfterClosingQuoteMustNotSwallowRestOfFile(): void
    {
        $path = $this->writeFixture(
            'bug2_multiline_comment.env',
            "K=\"a\nb\" # comment\nNEXT=1\n"
        );
        try {
            Env::load($path);
            self::assertSame("a\nb", Env::get('K'));
            self::assertTrue(
                Env::has('NEXT'),
                'NEXT must not be swallowed into the preceding multiline value'
            );
        } finally {
            $this->removeFixture($path);
        }
    }

    /**
     * Bug #6 (fixed): single-quoted values were interpolated.
     *
     * By dotenv convention single quotes mean a literal value, but the quote
     * type was discarded before setEnvironmentVariable() ran, so
     * `'$HOME_X/y'` was expanded as if it were double-quoted. The parser now
     * passes a raw flag along with each value and interpolation is skipped
     * for single-quoted ones.
     */
    public function testSingleQuotedValuesMustNotInterpolate(): void
    {
        $path = $this->writeFixture(
            'bug2_single_quote_interp.env',
            "HOME_X=/root\nS='\$HOME_X/y'\n"
        );
        try {
            Env::load($path);
            self::assertSame('$HOME_X/y', Env::get('S'));
        } finally {
            $this->removeFixture($path);
        }
    }

    /**
     * Bug #7 (fixed): the `___ESCAPED_DOLLAR___` placeholder collided with
     * user data.
     *
     * expandVariables() replaced `\$` with a magic placeholder string before
     * interpolation and rewrote every occurrence back to `$` afterwards, so
     * a value containing both the literal placeholder and a variable
     * reference was corrupted. The placeholder was removed entirely; escaped
     * dollar signs are now matched and unescaped by the interpolation regex
     * itself.
     */
    public function testPlaceholderCollisionWithVariableReference(): void
    {
        $path = $this->writeFixture(
            'bug2_placeholder.env',
            "P=___ESCAPED_DOLLAR___\$MISSING_REF\n"
        );
        try {
            Env::load($path);
            self::assertSame('___ESCAPED_DOLLAR___', Env::get('P'));
        } finally {
            $this->removeFixture($path);
        }
    }

    /**
     * Bug #8 (fixed): an explicitly requested file whose name matched a
     * default path was silently skipped when missing.
     *
     * load() skipped any missing file listed in ENV_DEFAULT_PATHS without
     * checking whether it was passed explicitly, so with default paths
     * disabled `Env::load('.env')` for a nonexistent file returned silently
     * instead of throwing. Default paths are now tracked separately from
     * explicit arguments, and only the former are optional.
     */
    public function testExplicitlyPassedDefaultPathMustThrowWhenMissing(): void
    {
        $tempDir = \sys_get_temp_dir() . '/lite_env_bug2_' . \uniqid();
        \mkdir($tempDir);
        $cwd = \getcwd();
        \chdir($tempDir);

        try {
            $this->expectException(RuntimeException::class);
            Env::load('.env');
        } finally {
            \chdir($cwd);
            \rmdir($tempDir);
        }
    }
}
