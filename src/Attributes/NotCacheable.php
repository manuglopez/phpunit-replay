<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Attributes;

use Attribute;

/**
 * Marks a test class or method as unsafe to replay from cache (SPEC.md §8 rule 1): the
 * test always executes for real, even when its content key is unchanged. Read by
 * reflection while recording ({@see \Manuglopez\Replay\PHPUnit\Subscribers\RecordNotCacheableOnPreparationStarted})
 * and persisted in the graph's `not_cacheable` section.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class NotCacheable
{
    public function __construct(public string $reason = '')
    {
    }
}
