<?php

namespace Noo\CraftBunnyStream\helpers;

use Craft;
use craft\base\Element;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\helpers\Json;
use Illuminate\Support\Collection;
use Noo\CraftBunnyStream\BunnyStream;
use Noo\CraftBunnyStream\exceptions\BunnyException;
use Noo\CraftBunnyStream\fields\BunnyStreamField;
use Noo\CraftBunnyStream\models\BunnyStreamFieldAttributes;
use RuntimeException;
use Throwable;

class BunnyStreamHelper
{
    /** @var array<string, BunnyStreamField|null> */
    private static array $bunnyStreamFieldsByVolume = [];

    public static function getBunnyStreamVideoId(?Asset $asset): ?string
    {
        return self::getBunnyStreamFieldAttributes($asset)?->videoId;
    }

    public static function getBunnyStreamData(?Asset $asset): ?array
    {
        return self::getBunnyStreamFieldAttributes($asset)?->metaData;
    }

    public static function getBunnyStreamStatus(?Asset $asset): ?string
    {
        $data = self::getBunnyStreamData($asset);

        if (!$data) {
            return null;
        }

        return match ((int)$data['status']) {
            1 => 'queued',
            2 => 'processing',
            3 => 'encoding',
            4 => 'finished',
            5 => 'resolution_finished',
            6 => 'failed',
            default => null,
        };
    }

    public static function getHlsUrl(Asset $asset): string
    {
        $videoId = self::getBunnyStreamVideoId($asset);
        $hostname = self::getCdnHostname();

        return "https://{$hostname}/{$videoId}/playlist.m3u8";
    }

    public static function getDirectUrl(Asset $asset): string
    {
        $videoId = self::getBunnyStreamVideoId($asset);
        $libraryId = self::getLibraryId();

        return "https://player.mediadelivery.net/embed/{$libraryId}/{$videoId}";
    }

    public static function getEmbedUrl(Asset $asset, array $params = []): string
    {
        $query = http_build_query([
            'autoplay' => 'false',
            'preload' => 'true',
            'responsive' => 'true',
            ...$params,
        ]);

        return self::getDirectUrl($asset) . '?' . $query;
    }

    public static function getEmbedHtml(Asset $asset, array $params = []): string
    {
        $src = self::getEmbedUrl($asset, $params);

        return '<div style="position:relative;padding-top:56.25%;"><iframe src="' . htmlspecialchars($src, ENT_QUOTES) . '" loading="lazy" style="border:0;position:absolute;top:0;height:100%;width:100%;" allow="accelerometer;gyroscope;autoplay;encrypted-media;picture-in-picture;" allowfullscreen></iframe></div>';
    }

    public static function getRelativeThumbnailUrl(Asset $asset): ?string
    {
        $videoId = self::getBunnyStreamVideoId($asset);
        $thumbnailFileName = self::getBunnyStreamData($asset)['thumbnailFileName'] ?? null;

        if (!$videoId || !$thumbnailFileName) {
            return null;
        }

        return "/{$videoId}/{$thumbnailFileName}";
    }

    public static function getThumbnailUrl(Asset $asset): ?string
    {
        $videoId = self::getBunnyStreamVideoId($asset);
        $hostname = self::getCdnHostname();
        $thumbnailFileName = self::getBunnyStreamData($asset)['thumbnailFileName'] ?? null;

        if (!$videoId || !$thumbnailFileName) {
            return null;
        }

        return "https://{$hostname}/{$videoId}/{$thumbnailFileName}";
    }

    public static function updateOrCreateBunnyStreamAsset(?Asset $asset): bool
    {
        if (!$asset) {
            return false;
        }

        $bunnyStreamVideoId = self::getBunnyStreamVideoId($asset);
        $bunnyStreamVideo = null;

        if ($bunnyStreamVideoId) {
            try {
                $bunnyStreamVideo = BunnyStreamApiHelper::getVideo($bunnyStreamVideoId);
            } catch (Throwable $e) {
                Craft::error($e, __METHOD__);
            }
        }

        if (!$bunnyStreamVideo) {
            try {
                $bunnyStreamVideo = BunnyStreamApiHelper::createVideo($asset);
            } catch (Throwable $e) {
                Craft::error($e, __METHOD__);
            }
        }

        if (!$bunnyStreamVideo) {
            self::deleteBunnyStreamVideo($asset);
            return false;
        }

        return self::saveBunnyStreamAttributesToAsset($asset, [
            'videoId' => $bunnyStreamVideo['guid'],
            'metaData' => (array)$bunnyStreamVideo,
        ]);
    }

    public static function updateBunnyStreamData(?Asset $asset): bool
    {
        if (!$asset) {
            return false;
        }

        $bunnyStreamVideoId = self::getBunnyStreamVideoId($asset);

        if (!$bunnyStreamVideoId) {
            return false;
        }

        $bunnyStreamVideo = BunnyStreamApiHelper::getVideo($bunnyStreamVideoId);

        return self::saveBunnyStreamAttributesToAsset($asset, [
            'videoId' => $bunnyStreamVideoId,
            'metaData' => (array)$bunnyStreamVideo,
        ]);
    }

    public static function saveBunnyStreamAttributesToAsset(Asset $asset, ?array $attributes): bool
    {
        if (!self::setBunnyStreamFieldAttributes($asset, $attributes)) {
            return false;
        }

        return self::saveAsset($asset, $attributes === null ? 'delete' : 'save');
    }

    public static function deleteBunnyStreamVideo(?Asset $asset, bool $preserveBunnyStreamVideo = false): bool
    {
        if (!$asset) {
            return false;
        }

        $bunnyStreamVideoId = self::getBunnyStreamFieldAttributes($asset)?->videoId;

        if (!$bunnyStreamVideoId) {
            return false;
        }

        self::saveBunnyStreamAttributesToAsset($asset, null);

        if (!$preserveBunnyStreamVideo) {
            try {
                BunnyStreamApiHelper::deleteVideo($bunnyStreamVideoId);
            } catch (Throwable $e) {
                Craft::error("Unable to delete Bunny Stream video: " . $e->getMessage(), __METHOD__);
            }
        }

        return true;
    }

    public static function getBunnyStreamFieldAttributes(?Asset $asset): ?BunnyStreamFieldAttributes
    {
        $field = self::getBunnyStreamFieldForAsset($asset);
        if (!$field) {
            return null;
        }

        /** @var BunnyStreamFieldAttributes|null */
        return $asset->{$field->handle} ?? null;
    }

    private static function saveAsset(Asset $asset, string $action): bool
    {
        $asset->setScenario(Element::SCENARIO_ESSENTIALS);
        $asset->resaving = true;

        try {
            $success = Craft::$app->getElements()->saveElement($asset, false);
        } catch (Throwable $e) {
            Craft::error($e, __METHOD__);
            throw new BunnyException("Unable to {$action} Bunny Stream attributes for asset: " . $e->getMessage());
        }

        if (!$success) {
            $errors = Json::encode($asset->getErrors());
            Craft::error("Unable to {$action} Bunny Stream attributes for asset: " . $errors, __METHOD__);
            throw new BunnyException("Unable to {$action} Bunny Stream attributes for asset: " . $errors);
        }

        return true;
    }

    private static function getCdnHostname(): string
    {
        $hostname = BunnyStream::getInstance()->getSettings()->bunnyStreamCdnHostname;

        if (!$hostname) {
            throw new RuntimeException('No Bunny Stream Hostname set');
        }

        return $hostname;
    }

    private static function getLibraryId(): string
    {
        $libraryId = BunnyStream::getInstance()->getSettings()->bunnyStreamLibraryId;

        if (!$libraryId) {
            throw new RuntimeException('No Bunny Stream Library ID set');
        }

        return $libraryId;
    }

    private static function setBunnyStreamFieldAttributes(?Asset $asset, ?array $attributes = null): bool
    {
        if (!$asset) {
            return false;
        }

        $field = self::getBunnyStreamFieldForAsset($asset);

        if (!$field) {
            return false;
        }

        $asset->setFieldValue($field->handle, $attributes);

        return true;
    }

    private static function getBunnyStreamFieldForAsset(?Asset $asset): ?BunnyStreamField
    {
        if (!$asset || $asset->kind !== Asset::KIND_VIDEO) {
            return null;
        }

        try {
            $volumeHandle = $asset->getVolume()->handle;

            if (isset(self::$bunnyStreamFieldsByVolume[$volumeHandle])) {
                return self::$bunnyStreamFieldsByVolume[$volumeHandle];
            }

            /** @var BunnyStreamField|null $bunnyStreamField */
            $bunnyStreamField = Collection::make($asset->getFieldLayout()?->getCustomFields())
                ->first(static fn(FieldInterface $field) => $field instanceof BunnyStreamField);

            self::$bunnyStreamFieldsByVolume[$volumeHandle] = $bunnyStreamField;
        } catch (Throwable $e) {
            Craft::error($e, __METHOD__);
            return null;
        }

        return self::$bunnyStreamFieldsByVolume[$volumeHandle] ?? null;
    }
}
