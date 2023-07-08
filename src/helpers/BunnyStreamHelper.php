<?php

namespace jorisnoo\bunnystream\helpers;

use craft\base\Element;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\helpers\Json;
use Illuminate\Support\Collection;
use jorisnoo\bunnystream\fields\BunnyStreamField;
use jorisnoo\bunnystream\models\BunnyStreamFieldAttributes;
use vaersaagod\muxmate\helpers\MuxApiHelper;

class BunnyStreamHelper
{
    /** @var BunnyStreamField[] */
    private static array $_bunnyStreamFieldsByVolume = [];

    public static function getBunnyStreamVideoId(?Asset $asset): ?string
    {
        return static::getBunnyStreamFieldAttributes($asset)?->bunnyStreamVideoGuid;
    }

    public static function getBunnyStreamData(?Asset $asset): ?array
    {
        return static::getBunnyStreamFieldAttributes($asset)?->bunnyStreamMetaData;
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

        $bunnyStreamVideoGuid = static::getBunnyStreamVideoId($asset);
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
            static::deleteMuxAttributesForAsset($asset);
            return false;
        }

        return static::saveBunnyStreamAttributesToAsset($asset, [
            'bunnyStreamVideoId' => $bunnyStreamVideo['id'],
            'bunnyStreamMetaData' => (array)$bunnyStreamVideo,
        ]);
    }

    /**
     * @param Asset $asset
     * @param array $attributes
     * @return bool
     */
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
            \Craft::error("Unable to save Mux attributes to asset: " . Json::encode($asset->getErrors()), __METHOD__);
            return false;
        }

        return true;
    }

    /**
     * @param Asset|null $asset
     * @param bool $alsoDeleteBunnyStreamVideo
     * @return bool
     */
    public static function deleteMuxAttributesForAsset(?Asset $asset, bool $alsoDeleteBunnyStreamVideo = true): bool
    {
        if (!$asset) {
            return false;
        }

        $bunnyStreamVideoGuid = static::getBunnyStreamFieldAttributes($asset)?->bunnyStreamVideoGuid;

        if (!$bunnyStreamVideoGuid) {
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
            \Craft::error("Unable to delete Mux attributes for asset: " . Json::encode($asset->getErrors()));
            return false;
        }

        if ($alsoDeleteBunnyStreamVideo) {
            try {
                BunnyStreamApiHelper::deleteVideo($bunnyStreamVideoGuid);
            } catch (\Throwable) {
                // Don't really care.
            }
        }

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
