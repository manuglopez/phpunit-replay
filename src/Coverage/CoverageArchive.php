<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Coverage;

use SebastianBergmann\CodeCoverage\CodeCoverage;

/**
 * Reads and writes a PHPUnit `--coverage-php` file in the shape the installed
 * `phpunit/php-code-coverage` produces, so {@see \Manuglopez\Replay\Report\CoverageMerger}
 * never has to know which one that is. {@see CoverageFormat::archive()} picks the
 * implementation:
 *
 * - {@see LegacyArchive} for php-code-coverage 11-13 (`Report\PHP`);
 * - {@see SerializedArchive} for 14 and later (`Serialization\Serializer`).
 *
 * Neither ever throws: an unreadable or foreign-format file comes back as null, and the
 * caller warns and degrades (docs/INTERNALS.md — "never throw out of a public method for an
 * environmental problem").
 */
interface CoverageArchive
{
    /**
     * The coverage a `--coverage-php` file holds, with ABSOLUTE file paths whatever the
     * on-disk representation was, or null when this installation cannot read the file.
     */
    public function read(string $path): ?CodeCoverage;

    /**
     * Writes `$coverage` to `$path` atomically, in the shape the installed
     * php-code-coverage's own `--coverage-php` produces, so every downstream consumer
     * (`phpcov`, `--coverage-html` from the merged file, a CI uploader) reads it as if PHPUnit
     * had written it. May mutate `$coverage`.
     */
    public function write(string $path, CodeCoverage $coverage): bool;
}
