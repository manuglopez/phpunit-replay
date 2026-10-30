<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Laravel;

use Manuglopez\Replay\Laravel\TranslationTracker;
use Manuglopez\Replay\Record\Recorder;
use Manuglopez\Replay\Tests\Unit\Record\FakeCoverageDriver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * The package does not depend on `illuminate/*`: where `Illuminate\Translation\Translator` does
 * not exist, the tracker neither arms anything nor marks the test untraceable (there is no
 * translator to trace). Own file and a separate process: the stand-in translator the other
 * tracker tests load can never be unloaded.
 */
final class TranslationTrackerWithoutIlluminateTest extends TestCase
{
    #[Group('AC-I1-e')]
    #[RunInSeparateProcess]
    public function test_without_the_translation_package_nothing_is_armed_or_verified(): void
    {
        self::assertFalse(class_exists('Illuminate\\Translation\\Translator', false));

        $recorder = new Recorder(new FakeCoverageDriver([[]]));
        $recorder->beginTest('/project/tests/FooTest.php');
        $app = new \stdClass();

        TranslationTracker::arm($app, $recorder);
        TranslationTracker::verify($app, $recorder);
        $recorder->endTest();

        self::assertSame([], $recorder->perTestTranslations());
    }
}
