<?php

namespace Noo\CraftBunnyStream\helpers;

use Craft;
use craft\elements\Asset;
use Noo\CraftBunnyStream\BunnyStream;
use Noo\CraftBunnyStream\models\Settings;
use RuntimeException;
use ToshY\BunnyNet\BunnyHttpClient;
use ToshY\BunnyNet\Enum\Endpoint;
use ToshY\BunnyNet\Model\Api\Stream\ManageVideos\CreateVideo;
use ToshY\BunnyNet\Model\Api\Stream\ManageVideos\DeleteVideo;
use ToshY\BunnyNet\Model\Api\Stream\ManageVideos\FetchVideo;
use ToshY\BunnyNet\Model\Api\Stream\ManageVideos\GetVideo;
use ToshY\BunnyNet\Model\Api\Stream\ManageVideos\UploadVideo;

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

    public static function createVideo(Asset $asset): mixed
    {
        $url = self::getFetchableAssetUrl($asset);

        if ($url !== null) {
            return self::fetchVideoFromUrl($url);
        }

        return self::uploadVideoBinary($asset);
    }

    private static function getFetchableAssetUrl(Asset $asset): ?string
    {
        if (Craft::$app->env === 'dev') {
            return null;
        }

        $url = $asset->getUrl();
        return $url && str_starts_with($url, 'http') ? $url : null;
    }

    private static function fetchVideoFromUrl(string $url): mixed
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
                body: ['url' => $url],
            ),
        )->getContents();

        if ($result['statusCode'] !== 200) {
            throw new RuntimeException("Error Creating Bunny Stream Video - " . $result['message']);
        }

        return self::getVideo($result['id']);
    }

    private static function uploadVideoBinary(Asset $asset): mixed
    {
        $settings = self::getSettings();
        $libraryId = (int)$settings->bunnyStreamLibraryId;

        $createBody = [
            'title' => $asset->filename,
            'thumbnailTime' => 0,
        ];

        if ($settings->bunnyStreamCollectionId) {
            $createBody['collectionId'] = $settings->bunnyStreamCollectionId;
        }

        $created = self::getClient()->request(
            new CreateVideo(
                libraryId: $libraryId,
                body: $createBody,
            ),
        )->getContents();

        $videoId = $created['guid'] ?? null;
        if (!$videoId) {
            throw new RuntimeException("Error Creating Bunny Stream Video - missing guid in response");
        }

        $stream = $asset->getStream();

        try {
            self::getClient()->request(
                new UploadVideo(
                    libraryId: $libraryId,
                    videoId: $videoId,
                    body: $stream,
                ),
            )->getContents();
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return self::getVideo($videoId);
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
