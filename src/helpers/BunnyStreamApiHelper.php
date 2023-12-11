<?php

namespace jorisnoo\bunnystream\helpers;

use Craft;
use jorisnoo\bunnystream\BunnyStream;
use RuntimeException;
use ToshY\BunnyNet\Client\BunnyClient;
use ToshY\BunnyNet\StreamAPI;

class BunnyStreamApiHelper
{

    public static function getVideo(string $videoId)
    {
        $settings = static::getBunnyStreamApiSettings();

        return $settings->streamApi->getVideo(
            libraryId: $settings->settings['libraryId'],
            videoId: $videoId,
        )->getContents();
    }

    public static function deleteVideo(string $videoId)
    {
        $settings = static::getBunnyStreamApiSettings();

        return $settings->streamApi->deleteVideo(
            libraryId: $settings->settings['libraryId'],
            videoId: $videoId,
        )->getContents();
    }


    public static function createVideo(string $inputUrl)
    {
        $settings = static::getBunnyStreamApiSettings();

        $result = $settings->streamApi->fetchVideo(
            libraryId: $settings->settings['libraryId'],
            body: ['url' => $inputUrl],
            query: [
                ...$settings->settings['collection'] ? ['collectionId' => $settings->settings['collection']] : [],
                'thumbnailTime' => 0,
            ],
        );

        $video = $result->getContents();

        if ($video['statusCode'] !== 200) {
            throw new RuntimeException("Error Creating Bunny Stream Video - " . $video['message']);
        }

        return static::getVideo($video['id']);
    }

    public static function getBunnyStreamApiSettings(): BunnyStreamApiSettings
    {
        $settings = BunnyStream::getInstance()->getSettings();

        $apiSettings = new BunnyStreamApiSettings();

        $apiSettings->settings = [
            'libraryId' => $settings?->bunnyStreamLibraryId,
            'collection' => $settings?->bunnyStreamCollectionId,
        ];

        $apiSettings->streamApi = static::getStreamApiClient($settings);

        return $apiSettings;
    }

    private static function getStreamApiClient($settings): StreamAPI
    {
        if (!$settings?->bunnyStreamAccessKey || !$settings?->bunnyStreamLibraryId) {
            throw new RuntimeException("No Bunny Stream access key or library ID");
        }

        return new StreamAPI(
            apiKey: $settings?->bunnyStreamAccessKey,
            client: new BunnyClient(Craft::createGuzzleClient())
        );
    }
}
