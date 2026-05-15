<?php

use Noo\CraftBunnyStream\enums\VideoStatus;

it('treats only Finished, Error, and UploadFailed as terminal', function (VideoStatus $status, bool $expected) {
    expect($status->isTerminal())->toBe($expected);
})->with([
    [VideoStatus::Created, false],
    [VideoStatus::Uploaded, false],
    [VideoStatus::Processing, false],
    [VideoStatus::Transcoding, false],
    [VideoStatus::Finished, true],
    [VideoStatus::Error, true],
    [VideoStatus::UploadFailed, true],
    [VideoStatus::JitSegmenting, false],
    [VideoStatus::JitPlaylistsCreated, false],
]);
