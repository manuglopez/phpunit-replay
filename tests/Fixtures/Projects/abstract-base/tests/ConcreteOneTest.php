<?php

declare(strict_types=1);

namespace App\Tests;

/**
 * Adds no test method of its own: every test PHPUnit runs against this class is inherited
 * from `SweepBaseTest`, so `TestMethod::file()` resolves to the ABSTRACT base's file for
 * every one of them rather than to this one.
 */
final class ConcreteOneTest extends SweepBaseTest
{
}
