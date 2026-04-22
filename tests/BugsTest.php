<?php

declare(strict_types=1);

namespace PetStack\LiteEnv\Tests;

use PetStack\LiteEnv\Env;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests that reproduce known bugs in the Env class.
 *
 * These tests are EXPECTED TO FAIL against the current implementation.
 * Each test encodes the correct behavior; when a bug is fixed the
 * corresponding test should turn green.
 */
class BugsTest extends TestCase
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
     * Bug #1a: `DEBUG=true` must yield boolean true, not int 1.
     *
     * Root cause: setEnvironmentVariable runs convertType(), then casts
     * through putenv() (which stringifies true → "1"), and get() runs
     * convertType() a second time on the string "1".
     */
    public function testBooleanTrueShouldStayBoolean(): void
    {
        $path = $this->writeFixture('bug_bool_true.env', "DEBUG=true\n");
        try {
            Env::load($path);
            self::assertTrue(Env::get('DEBUG'));
        } finally {
            $this->removeFixture($path);
        }
    }

    /**
     * Bug #1b: `IS_PROD=false` must yield boolean false, not empty string.
     */
    public function testBooleanFalseShouldStayBoolean(): void
    {
        $path = $this->writeFixture('bug_bool_false.env', "IS_PROD=false\n");
        try {
            Env::load($path);
            self::assertFalse(Env::get('IS_PROD'));
        } finally {
            $this->removeFixture($path);
        }
    }

    /**
     * Bug #1c: `MISSING=null` must yield null, not empty string.
     */
    public function testNullShouldStayNull(): void
    {
        $path = $this->writeFixture('bug_null.env', "MISSING=null\n");
        try {
            Env::load($path);
            self::assertNull(Env::get('MISSING'));
        } finally {
            $this->removeFixture($path);
        }
    }

    /**
     * Bug #2: $_ENV and Env::get() must agree on value and type.
     *
     * setEnvironmentVariable writes the typed value into $_ENV but a
     * stringified version into putenv(). get() reads from getenv() first,
     * so the two sources return different values for the same key.
     */
    public function testEnvSuperglobalAndEnvGetMustAgree(): void
    {
        $path = $this->writeFixture('bug_sources.env', "FLAG=true\n");
        try {
            Env::load($path);
            self::assertSame($_ENV['FLAG'], Env::get('FLAG'));
        } finally {
            $this->removeFixture($path);
        }
    }

    /**
     * Bug #3: a quote left open at EOF silently drops the whole variable.
     *
     * Multiline quoted values are legitimate, so a parser without lookahead
     * can't know mid-file that a quote will never close. But when EOF is
     * reached while still inQuotes, the accumulated value is never yielded
     * and the key vanishes without a trace. Acceptable resolutions:
     * either load() throws, or the key is exposed with whatever was
     * accumulated. Silent data loss is not acceptable.
     */
    public function testUnterminatedQuoteAtEofMustNotBeSilentlyDropped(): void
    {
        $path = $this->writeFixture(
            'bug_unterminated_quote.env',
            "BROKEN=\"value_never_closed\n"
        );
        try {
            try {
                Env::load($path);
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
                return;
            }

            self::assertTrue(
                Env::has('BROKEN'),
                'BROKEN must not be silently dropped when its quote is never closed'
            );
        } finally {
            $this->removeFixture($path);
        }
    }

    /**
     * Bug #4: expandVariables uses `___ESCAPED_DOLLAR___` as a temporary
     * placeholder and then rewrites every occurrence back to `$`. A user
     * value that happens to contain this literal string is corrupted.
     */
    public function testMagicPlaceholderCollisionInValues(): void
    {
        $path = $this->writeFixture('bug_placeholder.env', "RAW=___ESCAPED_DOLLAR___\n");
        try {
            Env::load($path);
            self::assertSame('___ESCAPED_DOLLAR___', Env::get('RAW'));
        } finally {
            $this->removeFixture($path);
        }
    }

    /**
     * Bug #5: inline-comment stripping only handles a space before `#`.
     * A tab (or any non-space whitespace) before `#` leaves the comment
     * inside the value.
     */
    public function testTabBeforeInlineCommentMustBeStripped(): void
    {
        $path = $this->writeFixture('bug_tab_comment.env', "FOO=bar\t# comment\n");
        try {
            Env::load($path);
            self::assertSame('bar', Env::get('FOO'));
        } finally {
            $this->removeFixture($path);
        }
    }

    /**
     * Bug #6: get() throws RuntimeException for keys that fail the
     * name-format regex. For a read operation the caller should simply
     * receive the default value, consistent with has() returning false
     * for the same keys.
     */
    public function testGetWithInvalidKeyShouldReturnDefaultInsteadOfThrowing(): void
    {
        self::assertSame('fallback', Env::get('bad-key', 'fallback'));
    }
}
