<?php

namespace jorisnoo\bunnystream\helpers;

use jorisnoo\bunnystream\BunnyStream;
use ToshY\BunnyNet\Client\BunnyClient;
use ToshY\BunnyNet\StreamAPI;

class BunnyStreamApiHelper
{

    public static function getVideo(string $videoId)
    {
        ['streamApi' => $streamApi, 'settings' => $settings] = static::getApiClient();

        $result = $streamApi->getVideo(
            libraryId: $settings['libraryId'],
            videoId: $videoId,
        );

        return $result->getContents();
    }

    public static function deleteVideo(string $videoId)
    {
        ['streamApi' => $streamApi, 'settings' => $settings] = static::getApiClient();

        $result = $streamApi->deleteVideo(
            libraryId: $settings['libraryId'],
            videoId: $videoId,
        );

        return $result->getContents();
    }

    public static function createVideo(string $inputUrl)
    {
        ['streamApi' => $streamApi, 'settings' => $settings] = static::getApiClient();

        $result = $streamApi->fetchVideo(
            libraryId: $settings['libraryId'],
            body: [
                'url' => $inputUrl,
            ],
            query: [
                ...$settings['collection'] ? ['collectionId' => $settings['collection']] : [],
            ],
        );

        $video = $result->getContents();

        if ($video['statusCode'] !== 200) {
            throw new \RuntimeException("No Bunny Stream video");
        }

        return self::getVideo($video['id']);
    }

    public static function getApiClient()
    {
        $settings = BunnyStream::getInstance()->getSettings();
        $bunnyStreamAccessKey = $settings?->bunnyStreamAccessKey;
        $bunnyStreamLibraryId = $settings?->bunnyStreamLibraryId;
        $bunnyStreamCollectionId = $settings?->bunnyStreamCollectionId;

        if (!$bunnyStreamAccessKey) {
            throw new \RuntimeException("No Bunny Stream access key");
        }

        if (!$bunnyStreamLibraryId) {
            throw new \RuntimeException("No Bunny Stream library ID");
        }

        $bunnyClient = new BunnyClient(
            \Craft::createGuzzleClient(),
        );

        $streamApi = new StreamAPI(
            apiKey: $bunnyStreamAccessKey,
            client: $bunnyClient
        );

        return [
            'streamApi' => $streamApi,
            'settings' => [
                'libraryId' => $bunnyStreamLibraryId,
                'collection' => $bunnyStreamCollectionId,
            ]
        ];
    }
}
