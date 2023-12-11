<?php

namespace jorisnoo\bunnystream\helpers;

use Craft;
use craft\base\Element;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\helpers\Json;
use Illuminate\Support\Collection;
use jorisnoo\bunnystream\BunnyStream;
use jorisnoo\bunnystream\exceptions\BunnyException;
use jorisnoo\bunnystream\fields\BunnyStreamField;
use jorisnoo\bunnystream\models\BunnyStreamFieldAttributes;
use Throwable;

class BunnyStreamHelper
{
    /** @var BunnyStreamField[] */
    private static array $_bunnyStreamFieldsByVolume = [];

    public static function getBunnyStreamVideoId(?Asset $asset): ?string
    {
        return static::getBunnyStreamFieldAttributes($asset)?->bunnyStreamVideoId;
    }

    public static function getBunnyStreamData(?Asset $asset): ?array
    {
        return static::getBunnyStreamFieldAttributes($asset)?->bunnyStreamMetaData;
    }

    public static function getBunnyStreamStatus(?Asset $asset): ?string
    {
        $data = static::getBunnyStreamData($asset);

        if (!$data) {
            return null;
        }

        // Bunny Stream Statuses:
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

    private static function getBunnyStreamCdnHostname(): string
    {
        $bunnyStreamCdnHostname = BunnyStream::getInstance()->getSettings()?->bunnyStreamCdnHostname;

        if (!$bunnyStreamCdnHostname) {
            throw new \RuntimeException("No Bunny Stream Hostname set");
        }

        return $bunnyStreamCdnHostname;
    }

    private static function getBunnyStreamLibraryId(): string
    {
        $bunnyStreamLibraryId = BunnyStream::getInstance()->getSettings()?->bunnyStreamLibraryId;

        if (!$bunnyStreamLibraryId) {
            throw new \RuntimeException("No Bunny Stream Library ID set");
        }

        return $bunnyStreamLibraryId;
    }

    public static function getHlsUrl($bunnyStreamVideoId): string
    {
        $bunnyStreamCdnHostname = self::getBunnyStreamCdnHostname();

        return "https://{$bunnyStreamCdnHostname}/{$bunnyStreamVideoId}/playlist.m3u8";
    }

    public static function getDirectUrl($bunnyStreamVideoId): string
    {
        $bunnyStreamLibraryId = self::getBunnyStreamLibraryId();

        return "https://iframe.mediadelivery.net/embed/{$bunnyStreamLibraryId}/{$bunnyStreamVideoId}";
    }

    public static function getThumbnailUrl(?Asset $asset): string
    {
        if (!$asset) {
            return false;
        }

        $bunnyStreamCdnHostname = self::getBunnyStreamCdnHostname();

        $thumbnailFileName = self::getBunnyStreamData($asset)['thumbnailFileName'];
        $bunnyStreamVideoId = self::getBunnyStreamVideoId($asset);

        return "https://{$bunnyStreamCdnHostname}/{$bunnyStreamVideoId}/{$thumbnailFileName}";
    }

    public static function updateOrCreateBunnyStreamAsset(?Asset $asset): bool
    {
        if (!$asset) {
            return false;
        }

        $bunnyStreamVideoId = static::getBunnyStreamVideoId($asset);
        $bunnyStreamVideo = null;

        if ($bunnyStreamVideoId) {
            // Get existing Bunny Stream Video
            try {
                $bunnyStreamVideo = BunnyStreamApiHelper::getVideo($bunnyStreamVideoId);
            } catch (Throwable $e) {
                Craft::error($e, __METHOD__);
                //throw new BunnyException("Unable to get Bunny Stream video: " . $e->getMessage());
            }
        }

        if (!$bunnyStreamVideo) {
            // Create a new Bunny Stream Video
            try {
                $assetUrl = static::_getAssetUrl($asset);

                if (!$assetUrl) {
                    throw new BunnyException("Asset ID \"$asset->id\" has no URL");
                }

                $bunnyStreamVideo = BunnyStreamApiHelper::createVideo($assetUrl);
            } catch (Throwable $e) {
                Craft::error($e, __METHOD__);
                //throw new BunnyException("Unable to create Bunny Stream video: " . $e->getMessage());
            }
        }

        if (!$bunnyStreamVideo) {
            // Still no Bunny asset; make sure the data on the Craft asset is wiped out and bail
            static::deleteBunnyStreamVideo($asset);
            return false;
        }

        return static::saveBunnyStreamAttributesToAsset($asset, [
            'bunnyStreamVideoId' => $bunnyStreamVideo['guid'],
            'bunnyStreamMetaData' => (array)$bunnyStreamVideo,
        ]);
    }

    public static function updateBunnyStreamData(?Asset $asset): bool
    {
        if (!$asset) {
            return false;
        }

        $bunnyStreamVideoId = static::getBunnyStreamVideoId($asset);

        if(!$bunnyStreamVideoId) {
            return false;
        }

        $bunnyStreamVideo = BunnyStreamApiHelper::getVideo($bunnyStreamVideoId);

        return static::saveBunnyStreamAttributesToAsset($asset, [
            'bunnyStreamVideoId' => $bunnyStreamVideoId,
            'bunnyStreamMetaData' => (array)$bunnyStreamVideo,
        ]);
    }

    public static function saveBunnyStreamAttributesToAsset(Asset $asset, array $attributes): bool
    {
        if (!static::_setBunnyStreamFieldAttributes($asset, $attributes)) {
            return false;
        }

        $asset->setScenario(Element::SCENARIO_ESSENTIALS);
        $asset->resaving = true;

        try {
            $success = Craft::$app->getElements()->saveElement($asset, false);
        } catch (Throwable $e) {
            Craft::error($e, __METHOD__);
            throw new BunnyException("Unable to save Bunny Stream attributes to asset: " . $e->getMessage());
            //return false;
        }

        if (!$success) {
            Craft::error("Unable to save Bunny Stream attributes to asset: " . Json::encode($asset->getErrors()), __METHOD__);
            throw new BunnyException("Unable to save Bunny Stream attributes to asset: " . Json::encode($asset->getErrors()));
            //return false;
        }

        return true;
    }

    public static function deleteBunnyStreamVideo(?Asset $asset, bool $preserveBunnyStreamVideo = false): bool
    {
        if (!$asset) {
            return false;
        }

        $bunnyStreamVideoId = static::getBunnyStreamFieldAttributes($asset)?->bunnyStreamVideoId;

        if (!$bunnyStreamVideoId) {
            return false;
        }

        static::_setBunnyStreamFieldAttributes($asset, null);

        $asset->setScenario(Element::SCENARIO_ESSENTIALS);
        $asset->resaving = true;

        try {
            $success = Craft::$app->getElements()->saveElement($asset, false);
        } catch (Throwable $e) {
            Craft::error($e, __METHOD__);
            throw new BunnyException("Unable to delete Bunny Stream attributes for asset: " . $e->getMessage());
            //return false;
        }

        if (!$success) {
            Craft::error("Unable to delete Bunny Stream attributes for asset: " . Json::encode($asset->getErrors()));
            throw new BunnyException("Unable to delete Bunny Stream attributes for asset: " . Json::encode($asset->getErrors()));
            //return false;
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
        $bunnyStreamFieldHandle = static::_getBunnyStreamFieldForAsset($asset)?->handle;
        if (!$bunnyStreamFieldHandle) {
            return null;
        }

        /** @var BunnyStreamFieldAttributes|null $bunnyStreamFieldAttributes */
        $bunnyStreamFieldAttributes = $asset->$bunnyStreamFieldHandle ?? null;

        return $bunnyStreamFieldAttributes;
    }

    private static function _setBunnyStreamFieldAttributes(?Asset $asset, ?array $attributes = null): bool
    {
        if (!$asset) {
            return false;
        }

        $bunnyStreamFieldHandle = static::_getBunnyStreamFieldForAsset($asset)?->handle;

        if (!$bunnyStreamFieldHandle) {
            return false;
        }

        $asset->setFieldValue($bunnyStreamFieldHandle, $attributes);

        return true;
    }

    private static function _getBunnyStreamFieldForAsset(?Asset $asset): ?BunnyStreamField
    {
        if (!$asset || $asset?->kind !== Asset::KIND_VIDEO) {
            return null;
        }

        // Get the first Bunny Stream field for this asset
        try {
            $volumeHandle = $asset->getVolume()->handle;

            if (isset(static::$_bunnyStreamFieldsByVolume[$volumeHandle])) {
                return static::$_bunnyStreamFieldsByVolume[$volumeHandle];
            }

            /** @var FieldInterface|null $bunnyStreamField */
            $bunnyStreamField = Collection::make($asset->getFieldLayout()?->getCustomFields())
                ->first(static fn(FieldInterface $field) => $field instanceof BunnyStreamField);

            static::$_bunnyStreamFieldsByVolume[$volumeHandle] = $bunnyStreamField;

        } catch (Throwable $e) {
            Craft::error($e, __METHOD__);
            //throw new BunnyException("Unable to get Bunny Stream field for asset: " . $e->getMessage());
            return null;
        }

        return static::$_bunnyStreamFieldsByVolume[$volumeHandle] ?? null;
    }

    private static function _getAssetUrl(Asset $asset): ?string
    {
        if (Craft::$app->env === 'dev') {
            return null;
        }

        $url = $asset->getUrl();
        return str_starts_with($url, 'http') ? $url : null;
    }
}
