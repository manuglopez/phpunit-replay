<?php

declare(strict_types=1);

namespace Tests\Feature\Translations;

use Tests\TestCase;

class GroupSubtreeTest extends TestCase
{
    public function test_a_whole_subtree_is_read_as_an_array(): void
    {
        $this->assertSame(
            ['title' => 'Nested title', 'body' => 'Nested body'],
            trans('messages.nested'),
        );
    }
}
