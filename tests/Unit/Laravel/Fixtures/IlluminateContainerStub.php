<?php

declare(strict_types=1);

/**
 * A minimal stand-in for Illuminate\Container\Container, so LaravelIntegrationTest can
 * exercise the `class_exists()` branch of LaravelIntegration::shouldArm() without the
 * package (or its dev dependencies) ever depending on illuminate/*. Loaded once via
 * require_once — never autoloaded (its namespace does not match any PSR-4 prefix).
 */

namespace Illuminate\Container;

class Container
{
    /** What a test wants the arming subscriber to find as "the application" (see `getInstance()`). */
    public static ?object $instance = null;

    public static function getInstance(): object
    {
        return self::$instance ??= new self();
    }
}
