<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Coverage;

use Manuglopez\Replay\Support\AtomicFile;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use Throwable;

/**
 * The `--coverage-php` shape of php-code-coverage 11, 12 and 13
 * (`SebastianBergmann\CodeCoverage\Report\PHP::process()`): a serialized `CodeCoverage`
 * object inside `<?php return unserialize(<<<'END_OF_COVERAGE_SERIALIZATION' … );`, with
 * absolute file paths throughout — so {@see self::read()} has no path work to do, unlike
 * {@see SerializedArchive}.
 *
 * A file written by php-code-coverage 14+ unserializes to a plain array rather than a
 * `CodeCoverage`, so it is rejected by the `instanceof` check instead of being half-read.
 */
final class LegacyArchive implements CoverageArchive
{
    public function read(string $path): ?CodeCoverage
    {
        if (! is_file($path)) {
            return null;
        }

        // Buffered because this is an `include` of a file this package did not necessarily
        // write: a truncated or foreign `--coverage-php` file whose content is not valid PHP
        // is echoed verbatim by the include, straight into the wrapper's own stdout, where it
        // would corrupt the PHPUnit output the wrapper is forwarding to the user.
        ob_start();

        try {
            $value = @include $path;
        } catch (Throwable) {
            $value = null;
        } finally {
            ob_end_clean();
        }

        return $value instanceof CodeCoverage ? $value : null;
    }

    public function write(string $path, CodeCoverage $coverage): bool
    {
        try {
            $coverage->clearCache();
            $serialized = serialize($coverage);
        } catch (Throwable) {
            return false;
        }

        $buffer = "<?php\n"
            . "return unserialize(<<<'END_OF_COVERAGE_SERIALIZATION'\n"
            . $serialized . "\n"
            . "END_OF_COVERAGE_SERIALIZATION\n"
            . ");\n";

        return AtomicFile::write($path, $buffer);
    }
}
