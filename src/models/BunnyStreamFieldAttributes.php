<?php

namespace Noo\CraftBunnyStream\models;

use craft\base\Model;

class BunnyStreamFieldAttributes extends Model
{
    public ?string $videoId = null;
    public ?array $metaData = null;
    public ?string $ownershipProof = null;
    public ?string $generation = null;

    public static function normalizeVideoId(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $value)
            ? $value
            : null;
    }
}
