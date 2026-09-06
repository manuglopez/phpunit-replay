<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Select;

use Manuglopez\Replay\Support\Paths;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Plugins/Tia/TestPaths.php
 *
 * What counts as "a test file" for this project: the directories/files/suffixes declared
 * in the PHPUnit configuration's `<testsuites>`.
 */
final readonly class TestPaths
{
    /**
     * @param  list<string>  $directories  project-relative, no trailing '/'
     * @param  list<string>  $files  project-relative
     * @param  list<string>  $suffixes
     */
    public function __construct(
        private array $directories,
        private array $files,
        private array $suffixes,
    ) {
    }

    public static function fromConfiguration(Configuration $configuration, string $projectRoot): self
    {
        $directories = [];
        $files = [];
        $suffixes = [];

        foreach ($configuration->testSuite() as $suite) {
            foreach ($suite->directories() as $directory) {
                $rel = Paths::relative($projectRoot, $directory->path());

                if ($rel !== null) {
                    $directories[] = $rel;
                }

                $suffix = $directory->suffix();

                if ($suffix !== '') {
                    $suffixes[] = $suffix;
                }
            }

            foreach ($suite->files() as $file) {
                $rel = Paths::relative($projectRoot, $file->path());

                if ($rel !== null) {
                    $files[] = $rel;
                }
            }
        }

        if ($suffixes === []) {
            // PHPUnit\TextUI\Configuration\Configuration::testSuffixes() is a
            // non-empty-list<non-empty-string> by construction (built via Merger), so this
            // is always the last word: no further ['Test.php'] fallback can ever run.
            foreach ($configuration->testSuffixes() as $suffix) {
                $suffixes[] = $suffix;
            }
        }

        if ($directories === [] && $files === [] && is_dir(Paths::join($projectRoot, 'tests'))) {
            $directories[] = 'tests';
        }

        return new self(
            array_values(array_unique($directories)),
            array_values(array_unique($files)),
            array_values(array_unique($suffixes)),
        );
    }

    public function isTestFile(string $relativePath): bool
    {
        if (in_array($relativePath, $this->files, true)) {
            return true;
        }

        $matchesSuffix = false;

        foreach ($this->suffixes as $suffix) {
            if (str_ends_with($relativePath, $suffix)) {
                $matchesSuffix = true;

                break;
            }
        }

        if (! $matchesSuffix) {
            return false;
        }

        foreach ($this->directories as $dir) {
            if ($dir === '') {
                continue;
            }

            if (str_starts_with($relativePath, $dir . '/')) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function directories(): array
    {
        return $this->directories;
    }
}
