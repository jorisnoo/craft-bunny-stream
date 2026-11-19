<?php

namespace Noo\CraftBunnyStream\helpers;

use Craft;
use craft\elements\Asset;
use Noo\CraftBunnyStream\BunnyStream;
use Noo\CraftBunnyStream\exceptions\BunnyException;
use Noo\CraftBunnyStream\exceptions\MissingConfigurationException;
use Noo\CraftBunnyStream\jobs\DeleteBunnyStreamVideoJob;
use Noo\CraftBunnyStream\models\BunnyStreamFieldAttributes;
use Noo\CraftBunnyStream\models\Settings;
use Throwable;
use ToshY\BunnyNet\BunnyHttpClient;
use ToshY\BunnyNet\Enum\Endpoint;
use ToshY\BunnyNet\Exception\Client\BunnyHttpClientResponseException;
use ToshY\BunnyNet\Model\Api\Stream\ManageVideos\CreateVideo;
use ToshY\BunnyNet\Model\Api\Stream\ManageVideos\DeleteVideo;
use ToshY\BunnyNet\Model\Api\Stream\ManageVideos\FetchVideo;
use ToshY\BunnyNet\Model\Api\Stream\ManageVideos\GetVideo;
use ToshY\BunnyNet\Model\Api\Stream\ManageVideos\UploadVideo;
use ToshY\BunnyNet\Model\ModelInterface;

class BunnyStreamApiHelper
{
    private static ?BunnyHttpClient $client = null;
    private static ?BunnyHttpClient $uploadClient = null;

    public static function getVideo(string $videoId): array
    {
        $video = self::request(new GetVideo(libraryId: self::getLibraryId(), videoId: $videoId));
        $guid = BunnyStreamFieldAttributes::normalizeVideoId($video['guid'] ?? null);
        if ($guid === null || strcasecmp($guid, $videoId) !== 0) {
            throw new BunnyException('Bunny Stream returned unexpected video metadata.');
        }

        return $video;
    }

    public static function deleteVideo(string $videoId): mixed
    {
        return self::request(new DeleteVideo(libraryId: self::getLibraryId(), videoId: $videoId));
    }

    public static function createVideo(Asset $asset): array
    {
        $url = self::getFetchableAssetUrl($asset);

        if ($url !== null) {
            return self::fetchVideoFromUrl($asset, $url);
        }

        return self::uploadVideoBinary($asset);
    }

    private static function getFetchableAssetUrl(Asset $asset): ?string
    {
        if (Craft::$app->env === 'dev') {
            return null;
        }

        $url = $asset->getUrl();
        return $url && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) ? $url : null;
    }

    private static function fetchVideoFromUrl(Asset $asset, string $url): array
    {
        $settings = self::getSettings();
        $libraryId = self::getLibraryId();

        $query = ['thumbnailTime' => 0];

        if ($settings->bunnyStreamCollectionId) {
            $query['collectionId'] = $settings->bunnyStreamCollectionId;
        }

        $result = self::request(new FetchVideo(
            libraryId: $libraryId,
            query: $query,
            body: ['url' => $url],
        ));

        $videoId = BunnyStreamFieldAttributes::normalizeVideoId($result['id'] ?? null);
        if (($result['statusCode'] ?? null) !== 200 || $videoId === null) {
            throw new BunnyException('Bunny Stream did not accept the video fetch.');
        }

        try {
            return self::getVideo($videoId);
        } catch (Throwable $e) {
            self::discardCreatedVideo($asset, $videoId);
            throw $e;
        }
    }

    private static function uploadVideoBinary(Asset $asset): array
    {
        $settings = self::getSettings();
        $libraryId = self::getLibraryId();

        $createBody = [
            'title' => $asset->filename,
            'thumbnailTime' => 0,
        ];

        if ($settings->bunnyStreamCollectionId) {
            $createBody['collectionId'] = $settings->bunnyStreamCollectionId;
        }

        $created = self::request(new CreateVideo(libraryId: $libraryId, body: $createBody));
        $videoId = BunnyStreamFieldAttributes::normalizeVideoId($created['guid'] ?? null);
        if ($videoId === null) {
            throw new BunnyException('Bunny Stream did not return a video GUID.');
        }

        try {
            $stream = $asset->getStream();
            try {
                self::request(new UploadVideo(libraryId: $libraryId, videoId: $videoId, body: $stream));
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            return self::getVideo($videoId);
        } catch (Throwable $e) {
            self::discardCreatedVideo($asset, $videoId);
            throw $e;
        }
    }

    /** Compensate only for a GUID returned by Bunny for this creation. */
    public static function discardCreatedVideo(Asset $asset, string $videoId): void
    {
        try {
            self::deleteVideo($videoId);
        } catch (Throwable) {
            Craft::warning("Queuing cleanup of incomplete Bunny Stream video $videoId.", __METHOD__);
            DeleteBunnyStreamVideoJob::forCreatedVideo($asset, $videoId)->schedule();
        }
    }

    private static function getSettings(): Settings
    {
        return BunnyStream::getInstance()->getSettings();
    }

    public static function getLibraryId(): int
    {
        $libraryId = self::getSettings()->bunnyStreamLibraryId;

        if (!$libraryId || !ctype_digit($libraryId) || (int)$libraryId < 1) {
            throw new MissingConfigurationException('No Bunny Stream Library ID set');
        }

        return (int)$libraryId;
    }

    private static function request(ModelInterface $operation): mixed
    {
        $client = self::getClient($operation instanceof UploadVideo);
        try {
            return $client->request($operation)->getContents();
        } catch (BunnyHttpClientResponseException $e) {
            if ($operation instanceof DeleteVideo && $e->getCode() === 404) {
                return null;
            }
            // The SDK puts the remote response body in the exception message.
            throw new BunnyException('Bunny Stream request failed (HTTP ' . $e->getCode() . ').', $e->getCode());
        } catch (Throwable) {
            throw new BunnyException('Bunny Stream request failed.');
        }
    }

    private static function getClient(bool $upload): BunnyHttpClient
    {
        $client = $upload ? self::$uploadClient : self::$client;
        if ($client !== null) {
            return $client;
        }

        $settings = self::getSettings();

        if (!$settings->bunnyStreamAccessKey || !$settings->bunnyStreamLibraryId) {
            throw new MissingConfigurationException('No Bunny Stream access key or library ID');
        }

        $client = new BunnyHttpClient(
            client: Craft::createGuzzleClient(['connect_timeout' => 5, 'timeout' => $upload ? 3600 : 30]),
            apiKey: $settings->bunnyStreamAccessKey,
            baseUrl: Endpoint::STREAM,
        );

        if ($upload) {
            return self::$uploadClient = $client;
        }
        return self::$client = $client;
    }
}
