<?php

declare(strict_types=1);

namespace App\Tests;

/**
 * Adds no test method of its own: every test PHPUnit runs against this class is inherited
 * from `SweepScenario`, whose file does not match the configured test suffix at all.
 */
final class ConcreteThreeTest extends SweepScenario
{
}
