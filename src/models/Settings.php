<?php

namespace Noo\CraftBunnyStream\models;

use craft\base\Model;
use craft\helpers\App;

class Settings extends Model
{
    public ?string $bunnyStreamAccessKey = null;
    public ?string $bunnyStreamLibraryId = null;
    public ?string $bunnyStreamCdnHostname = null;
    public ?string $bunnyStreamCollectionId = null;

    public function init(): void
    {
        parent::init();

        $this->bunnyStreamAccessKey ??= App::env('BUNNY_STREAM_ACCESS_KEY');
        $this->bunnyStreamLibraryId ??= App::env('BUNNY_STREAM_LIBRARY_ID');
        $this->bunnyStreamCdnHostname ??= App::env('BUNNY_STREAM_CDN_HOSTNAME');
        $this->bunnyStreamCollectionId ??= App::env('BUNNY_STREAM_COLLECTION_ID');
    }
}
