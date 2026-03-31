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

    public static function getRelativeThumbnailUrl(Asset $asset): string
    {
        $videoId = self::getBunnyStreamVideoId($asset);
        $thumbnailFileName = self::getBunnyStreamData($asset)['thumbnailFileName'];

        return "/{$videoId}/{$thumbnailFileName}";
    }

    public static function getThumbnailUrl(Asset $asset): string
    {
        $videoId = self::getBunnyStreamVideoId($asset);
        $hostname = self::getCdnHostname();
        $thumbnailFileName = self::getBunnyStreamData($asset)['thumbnailFileName'];

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
                $assetUrl = self::getAssetUrl($asset);

                if (!$assetUrl) {
                    throw new BunnyException("Asset ID \"$asset->id\" has no URL");
                }

                $bunnyStreamVideo = BunnyStreamApiHelper::createVideo($assetUrl);
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

    public static function saveBunnyStreamAttributesToAsset(Asset $asset, array $attributes): bool
    {
        if (!self::setBunnyStreamFieldAttributes($asset, $attributes)) {
            return false;
        }

        $asset->setScenario(Element::SCENARIO_ESSENTIALS);
        $asset->resaving = true;

        try {
            $success = Craft::$app->getElements()->saveElement($asset, false);
        } catch (Throwable $e) {
            Craft::error($e, __METHOD__);
            throw new BunnyException("Unable to save Bunny Stream attributes to asset: " . $e->getMessage());
        }

        if (!$success) {
            Craft::error("Unable to save Bunny Stream attributes to asset: " . Json::encode($asset->getErrors()), __METHOD__);
            throw new BunnyException("Unable to save Bunny Stream attributes to asset: " . Json::encode($asset->getErrors()));
        }

        return true;
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

        self::setBunnyStreamFieldAttributes($asset, null);

        $asset->setScenario(Element::SCENARIO_ESSENTIALS);
        $asset->resaving = true;

        try {
            $success = Craft::$app->getElements()->saveElement($asset, false);
        } catch (Throwable $e) {
            Craft::error($e, __METHOD__);
            throw new BunnyException("Unable to delete Bunny Stream attributes for asset: " . $e->getMessage());
        }

        if (!$success) {
            Craft::error("Unable to delete Bunny Stream attributes for asset: " . Json::encode($asset->getErrors()), __METHOD__);
            throw new BunnyException("Unable to delete Bunny Stream attributes for asset: " . Json::encode($asset->getErrors()));
        }

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

    private static function getAssetUrl(Asset $asset): ?string
    {
        if (Craft::$app->env === 'dev') {
            return null;
        }

        $url = $asset->getUrl();
        return str_starts_with($url, 'http') ? $url : null;
    }
}
