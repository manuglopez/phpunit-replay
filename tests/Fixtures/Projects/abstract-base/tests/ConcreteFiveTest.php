<?php

declare(strict_types=1);

namespace App\Tests;

/**
 * Adds no test method of its own, same as `ConcreteOneTest`/`ConcreteTwoTest` — but its
 * inherited `testAlphaDoublesVarious()` is data-provider-driven, checking that a dataset
 * does not change which class `TestMethodFile::of()` resolves (`className()` is the same
 * running class for every dataset repetition of the same method).
 */
final class ConcreteFiveTest extends SweepBaseTest
{
}
