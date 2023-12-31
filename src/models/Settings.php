<?php

namespace jorisnoo\bunnystream\models;

use craft\base\Model;

class Settings extends Model
{
    public ?string $bunnyStreamAccessKey = null;
    public ?string $bunnyStreamLibraryId = null;
    public ?string $bunnyStreamCdnHostname = null;
    public ?string $bunnyStreamCollectionId = null;
}
