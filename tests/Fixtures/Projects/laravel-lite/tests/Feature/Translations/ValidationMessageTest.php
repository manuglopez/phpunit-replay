<?php

declare(strict_types=1);

namespace Tests\Feature\Translations;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ValidationMessageTest extends TestCase
{
    public function test_the_validator_reads_its_message_from_the_validation_group(): void
    {
        $validator = Validator::make([], ['name' => 'required']);

        $this->assertSame('The name field is required.', $validator->errors()->first('name'));
    }
}
