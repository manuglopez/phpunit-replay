<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit;

use PHPUnit\Framework\TestCase;

/**
 * Convenience base class for projects that would rather extend than compose: exactly
 * `PHPUnit\Framework\TestCase` plus the {@see Replayable} trait, nothing else. Projects
 * that already have their own base `TestCase` should `use Replayable;` there instead
 * (SPEC.md §3.2).
 *
 * It is also what lets static analysis check the trait inside a real class context —
 * `parent::invokeTestMethod()` only resolves once a trait has a using class.
 */
abstract class ReplayableTestCase extends TestCase
{
    use Replayable;
}
