<?php

namespace Noo\CraftBunnyStream\helpers;

class WebhookTokenHelper
{
    public static function isValid(?string $expectedToken, mixed $providedToken): bool
    {
        return $expectedToken !== null &&
            $expectedToken !== '' &&
            is_string($providedToken) &&
            hash_equals($expectedToken, $providedToken);
    }
}
