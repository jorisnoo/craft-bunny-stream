<?php

use Noo\CraftBunnyStream\helpers\WebhookTokenHelper;

it('accepts only an exact configured webhook token', function (?string $expected, mixed $provided, bool $valid) {
    expect(WebhookTokenHelper::isValid($expected, $provided))->toBe($valid);
})->with([
    'matching token' => ['secret-token', 'secret-token', true],
    'wrong token' => ['secret-token', 'wrong-token', false],
    'missing configured token' => [null, 'secret-token', false],
    'empty configured token' => ['', '', false],
    'missing provided token' => ['secret-token', null, false],
    'non-string provided token' => ['secret-token', ['secret-token'], false],
]);
