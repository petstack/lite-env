<?php

declare(strict_types=1);

namespace PetStack\LiteEnv\Tests;

use PetStack\LiteEnv\Env;
use PHPUnit\Framework\TestCase;

/**
 * Behavior tests for immutable-by-default loading and the $overwriteExisting
 * opt-out (see docs/superpowers/specs/2026-06-15-immutable-env-override-design.md).
 */
class ImmutableEnvTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp();

        Env::$disableDefaultPaths = true;
        Env::$overwriteExisting = false;
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
     * A variable already present in the real environment (here simulated with a
     * raw putenv() that does not touch cacheKeys) must not be overwritten by a
     * .env file under the default immutable mode.
     */
    public function testPreExistingVariableIsNotOverwrittenByDefault(): void
    {
        \putenv('IMM_PRE=real');
        $path = $this->writeFixture('imm_pre.env', "IMM_PRE=fromfile\n");
        try {
            Env::load($path);
            self::assertSame('real', Env::get('IMM_PRE'));
        } finally {
            $this->removeFixture($path);
            \putenv('IMM_PRE');
        }
    }

    /**
     * With $overwriteExisting = true the old behavior returns: a .env value
     * overwrites a pre-existing real environment variable.
     */
    public function testOverwriteFlagOverridesPreExistingVariable(): void
    {
        \putenv('IMM_OVR=real');
        Env::$overwriteExisting = true;
        $path = $this->writeFixture('imm_ovr.env', "IMM_OVR=fromfile\n");
        try {
            Env::load($path);
            self::assertSame('fromfile', Env::get('IMM_OVR'));
        } finally {
            $this->removeFixture($path);
            \putenv('IMM_OVR');
        }
    }

    /**
     * Variant A: immutable mode protects only the real pre-existing environment.
     * A key first set by an earlier .env file is "ours" (tracked in cacheKeys)
     * and must still be overridden by a later file in the same load().
     */
    public function testLaterFileOverridesEarlierFileInImmutableMode(): void
    {
        $first = $this->writeFixture('imm_xf_a.env', "IMM_XF=first\n");
        $second = $this->writeFixture('imm_xf_b.env', "IMM_XF=second\n");
        try {
            Env::load($first, $second);
            self::assertSame('second', Env::get('IMM_XF'));
        } finally {
            $this->removeFixture($first);
            $this->removeFixture($second);
            \putenv('IMM_XF');
        }
    }

    /**
     * A variable that pre-exists only in $_ENV (not in getenv) is also protected
     * in immutable mode, because has() inspects $_ENV and $_SERVER too.
     */
    public function testPreExistingSuperglobalVariableIsProtected(): void
    {
        $_ENV['IMM_SG'] = 'fromenv';
        $path = $this->writeFixture('imm_sg.env', "IMM_SG=fromfile\n");
        try {
            Env::load($path);
            self::assertSame('fromenv', Env::get('IMM_SG'));
        } finally {
            $this->removeFixture($path);
            unset($_ENV['IMM_SG'], $_SERVER['IMM_SG']);
        }
    }

    /**
     * A key absent from the environment is set normally in immutable mode — the
     * guard must only block pre-existing variables, not every write.
     */
    public function testBrandNewVariableIsSetInImmutableMode(): void
    {
        $path = $this->writeFixture('imm_new.env', "IMM_NEW=value\n");
        try {
            Env::load($path);
            self::assertSame('value', Env::get('IMM_NEW'));
        } finally {
            $this->removeFixture($path);
            \putenv('IMM_NEW');
        }
    }
}
