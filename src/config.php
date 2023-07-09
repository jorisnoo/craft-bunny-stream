<?php

use craft\helpers\App;

return [
    'bunnyStreamAccessKey' => App::env('BUNNY_STREAM_ACCESS_KEY'),
    'bunnyStreamLibraryId' => App::env('BUNNY_STREAM_LIBRARY_ID'),
    'bunnyStreamCdnHostname' => App::env('BUNNY_STREAM_CDN_HOSTNAME'),
    'bunnyStreamCollectionId' => App::env('BUNNY_STREAM_COLLECTION_ID'),
];
