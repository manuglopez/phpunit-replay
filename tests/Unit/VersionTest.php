<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit;

use Composer\InstalledVersions;
use Manuglopez\Replay\Version;
use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase
{
    public function test_it_reports_what_composer_reports_for_this_package(): void
    {
        // The whole point of the class: the value is read from the installed-versions map,
        // not written down here. Asserting equality with Composer's own answer is what makes
        // a future hand-edit of Version.php fail rather than ship.
        self::assertSame(
            InstalledVersions::getPrettyVersion('manuglopez/phpunit-replay'),
            Version::id(),
        );
    }

    public function test_it_never_reports_the_constant_that_went_stale(): void
    {
        // `0.1.0-dev` was the hand-written value, and it survived every release up to and
        // including v0.8.0 — stamped into every graph's `generator` field along the way. This
        // is a regression guard against reintroducing a literal, not a test of the string.
        self::assertNotSame('0.1.0-dev', Version::id());
    }

    public function test_it_reports_a_non_empty_string(): void
    {
        // Console\Application takes this as its version and Cache\Graph concatenates it into
        // `generator`; an empty string would silently produce `manuglopez/phpunit-replay `.
        self::assertNotSame('', Version::id());
    }

    public function test_the_fallback_is_not_mistakable_for_a_version(): void
    {
        // If Composer's map cannot describe the package, the answer has to read as absent
        // rather than as a number someone might act on. Anything digit-shaped here would
        // recreate the original failure in a quieter form.
        self::assertSame('unknown', Version::UNKNOWN);
        self::assertDoesNotMatchRegularExpression('/\d/', Version::UNKNOWN);
    }
}
