<?php

namespace jorisnoo\bunnystream\models;

use craft\base\Model;

class BunnyStreamFieldAttributes extends Model
{
    public ?string $bunnyStreamVideoGuid = null;
    public ?int $width = 0;
    public ?int $height = 0;
    public ?bool $ready = false;
    public ?array $bunnyStreamMetaData = null;
}
