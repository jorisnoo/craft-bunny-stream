<?php

namespace Noo\CraftBunnyStream\enums;

enum VideoStatus: int
{
    case Created = 0;
    case Uploaded = 1;
    case Processing = 2;
    case Transcoding = 3;
    case Finished = 4;
    case Error = 5;
    case UploadFailed = 6;
    case JitSegmenting = 7;
    case JitPlaylistsCreated = 8;

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Finished, self::Error, self::UploadFailed => true,
            default => false,
        };
    }
}
