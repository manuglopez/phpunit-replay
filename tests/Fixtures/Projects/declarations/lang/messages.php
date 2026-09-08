<?php

declare(strict_types=1);

/**
 * The `lang/` shape: a `return [...]` file that declares no name for anything to reference
 * and that nothing in this project loads statically. Neither technique can reach it, so it
 * is the residue — covered conservatively by the watch fallback
 * (Select\RunListBuilder::residuePatterns()), never dropped.
 */
return [
    'pending' => 'Awaiting review',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
];
