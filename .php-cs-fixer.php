<?php
/**
 * PHP CS Fixer Configuration
 *
 * This file configures the PHP CS Fixer tool to enforce coding standards
 * across the project. It sets up rules based on PSR-12 standards and
 * specifies which directories and files should be checked.
 *
 * Usage:
 * - Run `vendor/bin/php-cs-fixer fix` to automatically fix coding standards
 * - Run with `--dry-run` flag to see what would be fixed without making changes
 * - Run with `--diff` flag to see the changes that would be made
 *
 * @see https://github.com/FriendsOfPHP/PHP-CS-Fixer
 */

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

$config = new PhpCsFixer\Config();
return $config
    ->setRules([
        '@PSR12' => true,
    ])
    ->setFinder($finder);
