<?php

declare(strict_types=1);

namespace PetStack\LiteEnv\Tests;

use PetStack\LiteEnv\Env;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests that reproduce bugs found after the v2.1.1 fixes.
 *
 * These tests are EXPECTED TO FAIL against the current implementation.
 * Each test encodes the correct behavior; when a bug is fixed the
 * corresponding test should turn green.
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
     * Bug #1: `KEY=0` must not become an empty string.
     *
     * Root cause: processLine() checks `empty($value)` to detect empty
     * values, but empty('0') is true in PHP, so the value '0' is replaced
     * with ''. With type conversion enabled the expected result is int 0
     * (consistent with PORT=3000 becoming int 3000).
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
     * Bug #2: the last variable in a file is processed twice.
     *
     * parseFile() yields the accumulated pair after the read loop to avoid
     * dropping an unterminated quoted value at EOF, but $key is never reset
     * after a successful yield inside the loop. The raw value of the last
     * variable is therefore expanded a second time, after the variable
     * itself has already been set. A self-reference makes this observable:
     * `B=$B!` must produce '!' ($B is undefined on the only legitimate
     * expansion pass), not '!!'.
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
     * Bug #3a: leading zeros must not be stripped by numeric conversion.
     *
     * is_numeric('01234') is true, so convertType() casts the value to
     * int 1234. Zip codes, phone numbers and numeric IDs with leading
     * zeros are silently corrupted. Only canonical numeric representations
     * should be converted; '01234' is not one.
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
     * Bug #3b: version-like values must not lose precision.
     *
     * '1.10' is cast to float 1.1, so the original text can no longer be
     * recovered ((string) 1.1 === '1.1'). A float cast is only safe when
     * it round-trips back to the original string.
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
     * Bug #3c: numeric strings beyond PHP_INT_MAX must not overflow.
     *
     * convertType() casts any dot-free numeric string to int. A 23-digit
     * ID emits "not representable as an int" warnings and silently becomes
     * PHP_INT_MAX.
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
     * Bug #4a: whitespace between `=` and the opening quote breaks
     * multiline values.
     *
     * processLine() trims the value to detect the opening quote, but then
     * re-slices the raw line with `substr($line, $equals + 2)`, assuming
     * the quote sits immediately after `=`. With `K= "line1` the slice
     * starts at the quote itself, the quote is mistaken for the closing
     * one, and the value collapses to ''.
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
     * Bug #4b: an opening quote alone on the first line must start a
     * multiline value, not produce an empty one.
     *
     * For `K="` the trimmed value is the single character '"'; its first
     * and last characters are the same quote, so processLine() treats it
     * as a complete quoted empty value. The remaining lines of the real
     * value then fail to parse and are lost.
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
     * Bug #5: an inline comment after the closing quote of a multiline
     * value prevents the quote from ever closing.
     *
     * handleQuotedValue() only closes the value when the quote is the last
     * non-whitespace character of the line. With `b" # comment` the parser
     * stays inQuotes and swallows the rest of the file, so following
     * variables disappear.
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
     * Bug #6: single-quoted values must not be interpolated.
     *
     * By dotenv convention single quotes mean a literal value. The quote
     * type is discarded before setEnvironmentVariable() runs, so
     * expandVariables() interpolates `'$HOME_X/y'` as if it were
     * double-quoted.
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
     * Bug #7: the `___ESCAPED_DOLLAR___` placeholder still collides with
     * user data.
     *
     * The early return in expandVariables() only protects values without
     * any `$` character. A value containing both the literal placeholder
     * and a variable reference goes through the replace cycle, and the
     * placeholder text is rewritten to `$` on the way out.
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
     * Bug #8: an explicitly requested file must throw when missing, even
     * if its name matches a default path.
     *
     * load() skips any missing file whose name appears in
     * ENV_DEFAULT_PATHS, without checking whether it was passed explicitly.
     * With default paths disabled, `Env::load('.env')` for a nonexistent
     * file returns silently instead of throwing, contradicting both the
     * docblock and the behavior for every other file name.
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
