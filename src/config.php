<?php

use craft\helpers\App;

return [
    'bunnyStreamAccessKey' => App::env('BUNNY_STREAM_ACCESS_KEY'),
    'bunnyStreamLibraryId' => App::env('BUNNY_STREAM_LIBRARY_ID'),
    'bunnyStreamPullZoneUrl' => App::env('BUNNY_STREAM_PULL_ZONE_URL'),
    'bunnyStreamCollectionId' => App::env('BUNNY_STREAM_COLLECTION_ID'),
];
