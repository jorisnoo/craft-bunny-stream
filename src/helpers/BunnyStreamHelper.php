<?php

namespace jorisnoo\bunnystream\helpers;

use craft\base\FieldInterface;
use craft\elements\Asset;
use Illuminate\Support\Collection;
use jorisnoo\bunnystream\fields\BunnyStreamField;
use jorisnoo\bunnystream\models\BunnyStreamFieldAttributes;
use vaersaagod\muxmate\helpers\MuxApiHelper;

class BunnyStreamHelper
{
    /** @var BunnyStreamField[] */
    private static array $_bunnyStreamFieldsByVolume = [];

    public static function getBunnyStreamVideoGuid(?Asset $asset): ?string
    {
        return static::getBunnyStreamFieldAttributes($asset)?->bunnyStreamVideoGuid;
    }

    /**
     * @param Asset|null $asset
     * @return bool
     */
    public static function updateOrCreateBunnyStreamVideo(?Asset $asset): bool
    {
        if (!$asset) {
            return false;
        }

        $bunnyStreamVideoGuid = static::getBunnyStreamVideoGuid($asset);
        $bunnyStreamVideo = null;

        if ($bunnyStreamVideoGuid) {
            // Get existing Bunny Stream Video
            try {
                $bunnyStreamVideo = BunnyStreamApiHelper::getVideo($bunnyStreamVideoGuid);
            } catch (\Throwable $e) {
                \Craft::error($e, __METHOD__);
            }
        }

        if (!$bunnyStreamVideo) {
            $assetUrl = static::_getAssetUrl($asset);
            $bunnyStreamVideo = BunnyStreamApiHelper::createVideo($assetUrl);

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

//        if (!$bunnyStreamVideo) {
//            // Still no Mux asset; make sure the data on the Craft asset is wiped out and bail
//            static::deleteMuxAttributesForAsset($asset);
//            return false;
//        }
//
//        return static::saveMuxAttributesToAsset($asset, [
//            'muxAssetId' => $muxAsset->getId(),
//            'muxPlaybackId' => $muxAsset->getPlaybackIds()[0]['id'] ?? null,
//            'muxMetaData' => (array)$muxAsset->jsonSerialize(),
//        ]);

        return true;
    }

    /**
     * @param Asset|null $asset
     * @return BunnyStreamFieldAttributes|null
     */
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

    /**
     * @param Asset|null $asset
     * @param array|null $attributes
     * @return void
     */
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

    /**
     * @param Asset|null $asset
     * @return BunnyStreamField|null
     */
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

    /**
     * @param Asset $asset
     * @return string|null
     * @throws \yii\base\InvalidConfigException
     */
    private static function _getAssetUrl(Asset $asset): ?string
    {
//        return $asset->getUrl();
        return str_replace('.test', '.noo.dev', $asset->getUrl());
    }
}
