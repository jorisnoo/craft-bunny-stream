<?php

namespace jorisnoo\bunnystream\helpers;

use craft\base\Element;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\helpers\Json;
use Illuminate\Support\Collection;
use jorisnoo\bunnystream\BunnyStream;
use jorisnoo\bunnystream\fields\BunnyStreamField;
use jorisnoo\bunnystream\models\BunnyStreamFieldAttributes;

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

    public static function getHlsUrl($bunnyStreamVideoId): string
    {
        $settings = BunnyStream::getInstance()->getSettings();
        $bunnyStreamCDNHostname = $settings?->bunnyStreamCDNHostname;

        if (!$bunnyStreamCDNHostname) {
            throw new \RuntimeException("No Bunny Stream access key");
        }

        return "https://{$bunnyStreamCDNHostname}/{$$bunnyStreamVideoId}/playlist.m3u8";
    }

    public static function updateOrCreateBunnyStreamVideo(?Asset $asset): bool
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
            } catch (\Throwable $e) {
                \Craft::error($e, __METHOD__);
            }
        }

        if (!$bunnyStreamVideo) {
            // Create a new Bunny Stream Video
            try {
                $assetUrl = static::_getAssetUrl($asset);

                if (!$assetUrl) {
                    throw new \RuntimeException("Asset ID \"$asset->id\" has no URL");
                }

                $bunnyStreamVideo = BunnyStreamApiHelper::createVideo($assetUrl);
            } catch (\Throwable $e) {
                \Craft::error($e, __METHOD__);
            }
        }

        if (!$bunnyStreamVideo) {
            // Still no Mux asset; make sure the data on the Craft asset is wiped out and bail
            static::deleteBunnyStreamAttributesForAsset($asset);
            return false;
        }

        return static::saveBunnyStreamAttributesToAsset($asset, [
            'bunnyStreamVideoId' => $bunnyStreamVideo['guid'],
            'bunnyStreamMetaData' => (array)$bunnyStreamVideo,
        ]);
    }

    public static function updateBunnyStreamData(?Asset $asset) {
        if (!$asset) {
            return false;
        }

        $bunnyStreamVideoId = static::getBunnyStreamVideoId($asset);
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
            $success = \Craft::$app->getElements()->saveElement($asset, false);
        } catch (\Throwable $e) {
            \Craft::error($e, __METHOD__);
            return false;
        }

        if (!$success) {
            \Craft::error("Unable to save Bunny Stream attributes to asset: " . Json::encode($asset->getErrors()), __METHOD__);
            return false;
        }

        return true;
    }

    public static function deleteBunnyStreamAttributesForAsset(?Asset $asset, bool $alsoDeleteBunnyStreamVideo = true): bool
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
            $success = \Craft::$app->getElements()->saveElement($asset, false);
        } catch (\Throwable $e) {
            \Craft::error($e, __METHOD__);
            return false;
        }

        if (!$success) {
            \Craft::error("Unable to delete Bunny Stream attributes for asset: " . Json::encode($asset->getErrors()));
            return false;
        }

        if ($alsoDeleteBunnyStreamVideo) {
            try {
                BunnyStreamApiHelper::deleteVideo($bunnyStreamVideoId);
            } catch (\Throwable) {
                // Don't really care.
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

        } catch (\Throwable $e) {
            \Craft::error($e, __METHOD__);
            return null;
        }

        return static::$_bunnyStreamFieldsByVolume[$volumeHandle] ?? null;
    }

    private static function _getAssetUrl(Asset $asset): ?string
    {
        return $asset->getUrl();
    }
}
