<?php

namespace jorisnoo\bunnystream\helpers;

use Corbpie\BunnyCdn\BunnyAPIStream;
use jorisnoo\bunnystream\BunnyStream;

class BunnyStreamApiHelper
{

    public static function getVideo(string $bunnyStreamVideoGuid)
    {
        return static::getApiClient()->getVideo($bunnyStreamVideoGuid);
    }

    public static function createVideo(string $inputUrl)
    {
        $apiClient = static::getApiClient();

        $result = $apiClient->fetchVideo($inputUrl);

        dd($result);

        return $result->getData();

    }

    public static function getApiClient(): BunnyAPIStream
    {
        $settings = BunnyStream::getInstance()->getSettings();
        $bunnyStreamAccessKey = $settings?->bunnyStreamAccessKey;
        $bunnyStreamLibraryId = $settings?->bunnyStreamLibraryId;
        $bunnyStreamCollectionGuid = $settings?->bunnyStreamCollectionGuid;

        if (!$bunnyStreamAccessKey) {
            throw new \RuntimeException("No Bunny Stream access key");
        }

        if (!$bunnyStreamLibraryId) {
            throw new \RuntimeException("No Bunny Stream library ID");
        }

        $bunny = new BunnyAPIStream();
        $bunny->apiKey($bunnyStreamAccessKey);
        $bunny->setStreamLibraryId($bunnyStreamLibraryId);

        if($bunnyStreamCollectionGuid){
            $bunny->setStreamCollectionGuid($bunnyStreamCollectionGuid);
        }

        return $bunny;
    }
}
