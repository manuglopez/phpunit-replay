<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel;

/**
 * What a project-relative path under a language root is (`LangFiles::classify()`).
 *
 * `locale` is set for `json` and `php`, `group` for `php` only; `vendor` and `other` carry neither.
 */
final readonly class LangPath
{
    /** `<root>/<locale>.json` */
    public const JSON = 'json';

    /** `<root>/<locale>/<group>.php`, the group may contain subdirectories (`admin/users`) */
    public const PHP = 'php';

    /** `<root>/vendor/**` */
    public const VENDOR = 'vendor';

    /** anything else under a root: a README, a `.txt`, a backup */
    public const OTHER = 'other';

    /** @param self::JSON|self::PHP|self::VENDOR|self::OTHER $kind */
    public function __construct(
        public string $kind,
        public ?string $locale,
        public ?string $group,
    ) {
    }
}
