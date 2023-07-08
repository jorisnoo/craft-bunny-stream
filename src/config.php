<?php

use craft\helpers\App;

return [
    'bunnyStreamAccessKey' => App::env('BUNNY_STREAM_ACCESS_KEY'),
    'bunnyStreamLibraryId' => App::env('BUNNY_STREAM_LIBRARY_ID'),
    'bunnyStreamCollectionGuid' => App::env('BUNNY_STREAM_COLLECTION_GUID'),
];
