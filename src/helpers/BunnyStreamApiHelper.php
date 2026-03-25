<?php

namespace Noo\CraftBunnyStream\helpers;

use Craft;
use Noo\CraftBunnyStream\BunnyStream;
use Noo\CraftBunnyStream\models\Settings;
use RuntimeException;
use ToshY\BunnyNet\BunnyHttpClient;
use ToshY\BunnyNet\Enum\Endpoint;
use ToshY\BunnyNet\Model\Api\Stream\ManageVideos\DeleteVideo;
use ToshY\BunnyNet\Model\Api\Stream\ManageVideos\FetchVideo;
use ToshY\BunnyNet\Model\Api\Stream\ManageVideos\GetVideo;

class BunnyStreamApiHelper
{
    private static ?BunnyHttpClient $client = null;

    public static function getVideo(string $videoId): mixed
    {
        $libraryId = self::getLibraryId();

        return self::getClient()->request(
            new GetVideo(libraryId: $libraryId, videoId: $videoId),
        )->getContents();
    }

    public static function deleteVideo(string $videoId): mixed
    {
        $libraryId = self::getLibraryId();

        return self::getClient()->request(
            new DeleteVideo(libraryId: $libraryId, videoId: $videoId),
        )->getContents();
    }

    public static function createVideo(string $inputUrl): mixed
    {
        $settings = self::getSettings();
        $libraryId = (int)$settings->bunnyStreamLibraryId;

        $query = ['thumbnailTime' => 0];

        if ($settings->bunnyStreamCollectionId) {
            $query['collectionId'] = $settings->bunnyStreamCollectionId;
        }

        $result = self::getClient()->request(
            new FetchVideo(
                libraryId: $libraryId,
                query: $query,
                body: ['url' => $inputUrl],
            ),
        )->getContents();

        if ($result['statusCode'] !== 200) {
            throw new RuntimeException("Error Creating Bunny Stream Video - " . $result['message']);
        }

        return self::getVideo($result['id']);
    }

    private static function getSettings(): Settings
    {
        return BunnyStream::getInstance()->getSettings();
    }

    private static function getLibraryId(): int
    {
        $libraryId = self::getSettings()->bunnyStreamLibraryId;

        if (!$libraryId) {
            throw new RuntimeException('No Bunny Stream Library ID set');
        }

        return (int)$libraryId;
    }

    private static function getClient(): BunnyHttpClient
    {
        if (self::$client !== null) {
            return self::$client;
        }

        $settings = self::getSettings();

        if (!$settings->bunnyStreamAccessKey || !$settings->bunnyStreamLibraryId) {
            throw new RuntimeException('No Bunny Stream access key or library ID');
        }

        self::$client = new BunnyHttpClient(
            client: Craft::createGuzzleClient(),
            apiKey: $settings->bunnyStreamAccessKey,
            baseUrl: Endpoint::STREAM,
        );

        return self::$client;
    }
}
