<?php

declare(strict_types=1);

namespace App;

/**
 * The `PublisherReviewDecision` shape, in miniature: an enum with cases and no method
 * bodies at all. PHP executes this file's top level exactly once per process, so a
 * coverage driver can only ever credit its declaration footprint to whichever test in
 * that process loaded it first — every other test that asserts against these cases gets
 * no signal, not a weaker one (docs/SPEC.md §9 `static_declaration_edges`).
 */
enum ReviewDecision: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
