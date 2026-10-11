<?php

namespace Noo\CraftBunnyStream\helpers;

use Craft;
use craft\base\Element;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\helpers\Html;
use craft\helpers\Json;
use Illuminate\Support\Collection;
use Noo\CraftBunnyStream\BunnyStream;
use Noo\CraftBunnyStream\enums\VideoStatus;
use Noo\CraftBunnyStream\exceptions\BunnyException;
use Noo\CraftBunnyStream\exceptions\MissingConfigurationException;
use Noo\CraftBunnyStream\fields\BunnyStreamField;
use Noo\CraftBunnyStream\jobs\RefreshBunnyStreamMetadataJob;
use Noo\CraftBunnyStream\models\BunnyStreamFieldAttributes;
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

    public static function getBunnyStreamStatus(?Asset $asset): ?VideoStatus
    {
        $data = self::getBunnyStreamData($asset);

        if (!$data || !isset($data['status'])) {
            return null;
        }

        return VideoStatus::tryFrom((int)$data['status']);
    }

    public static function getHlsUrl(Asset $asset): ?string
    {
        $videoId = self::getBunnyStreamVideoId($asset);

        if (!$videoId) {
            return null;
        }

        $hostname = self::getCdnHostname();

        return "https://{$hostname}/" . rawurlencode($videoId) . '/playlist.m3u8';
    }

    public static function getDirectUrl(Asset $asset): ?string
    {
        $videoId = self::getBunnyStreamVideoId($asset);

        if (!$videoId) {
            return null;
        }

        $libraryId = BunnyStreamApiHelper::getLibraryId();

        return "https://player.mediadelivery.net/embed/{$libraryId}/" . rawurlencode($videoId);
    }

    public static function getEmbedUrl(Asset $asset, array $params = []): ?string
    {
        $directUrl = self::getDirectUrl($asset);

        if (!$directUrl) {
            return null;
        }

        $query = http_build_query([
            'autoplay' => 'false',
            'preload' => 'true',
            'responsive' => 'true',
            ...$params,
        ]);

        return $directUrl . '?' . $query;
    }

    public static function getEmbedHtml(Asset $asset, array $params = []): ?string
    {
        $iframe = self::getIframeHtml($asset, $params, [
            'style' => 'border:0;position:absolute;top:0;height:100%;width:100%;',
        ]);

        if (!$iframe) {
            return null;
        }

        return Html::tag('div', $iframe, ['style' => 'position:relative;padding-top:56.25%;']);
    }

    /**
     * Returns an escaped `<iframe>` tag for the asset's Bunny Stream player.
     *
     * @param array $params Player query parameters
     * @param array $options Extra iframe attributes (merged over the defaults)
     */
    public static function getIframeHtml(Asset $asset, array $params = [], array $options = []): ?string
    {
        $src = self::getEmbedUrl($asset, $params);

        if (!$src) {
            return null;
        }

        return Html::tag('iframe', '', [
            'src' => $src,
            'loading' => 'lazy',
            'allow' => 'accelerometer;gyroscope;autoplay;encrypted-media;picture-in-picture;',
            'allowfullscreen' => true,
            ...$options,
        ]);
    }

    public static function getRelativeThumbnailUrl(Asset $asset): ?string
    {
        $videoId = self::getBunnyStreamVideoId($asset);
        $thumbnailFileName = self::getBunnyStreamData($asset)['thumbnailFileName'] ?? null;

        if (!$videoId || !$thumbnailFileName) {
            return null;
        }

        return '/' . rawurlencode($videoId) . '/' . rawurlencode($thumbnailFileName);
    }

    public static function getThumbnailUrl(Asset $asset): ?string
    {
        $relativeUrl = self::getRelativeThumbnailUrl($asset);

        if (!$relativeUrl) {
            return null;
        }

        return 'https://' . self::getCdnHostname() . $relativeUrl;
    }

    /**
     * Creates a Bunny Stream video for an asset that doesn't have one yet,
     * or refreshes the metadata of an existing one.
     *
     * Returns false without contacting Bunny if the asset's volume has no
     * Bunny Stream field.
     */
    public static function updateOrCreateBunnyStreamAsset(?Asset $asset): bool
    {
        if (!$asset || !self::getBunnyStreamFieldForAsset($asset)) {
            return false;
        }

        if (self::getBunnyStreamVideoId($asset)) {
            return self::updateBunnyStreamData($asset);
        }

        try {
            $bunnyStreamVideo = BunnyStreamApiHelper::createVideo($asset);
        } catch (MissingConfigurationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Craft::error($e, __METHOD__);
            return false;
        }

        if (!$bunnyStreamVideo) {
            return false;
        }

        try {
            $saved = self::saveBunnyStreamAttributesToAsset($asset, [
                'videoId' => $bunnyStreamVideo['guid'],
                'metaData' => (array)$bunnyStreamVideo,
            ]);
        } catch (Throwable $e) {
            // Don't leave an orphaned video on Bunny if we couldn't store its ID
            try {
                BunnyStreamApiHelper::deleteVideo($bunnyStreamVideo['guid']);
            } catch (Throwable $deleteError) {
                Craft::error("Unable to delete orphaned Bunny Stream video: " . $deleteError->getMessage(), __METHOD__);
            }

            throw $e;
        }

        if ($saved) {
            RefreshBunnyStreamMetadataJob::schedule($asset->id);
        }

        return $saved;
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

        $metaData = (array)BunnyStreamApiHelper::getVideo($bunnyStreamVideoId);

        // Skip the (expensive) element save when nothing changed
        if ($metaData == self::getBunnyStreamData($asset)) {
            return true;
        }

        return self::saveBunnyStreamAttributesToAsset($asset, [
            'videoId' => $bunnyStreamVideoId,
            'metaData' => $metaData,
        ]);
    }

    public static function saveBunnyStreamAttributesToAsset(Asset $asset, ?array $attributes): bool
    {
        if (!self::setBunnyStreamFieldAttributes($asset, $attributes)) {
            return false;
        }

        return self::saveAsset($asset, $attributes === null ? 'delete' : 'save');
    }

    /**
     * @param bool $saveAsset Whether to clear the Bunny Stream attributes on the asset and save it.
     *                        Pass false when the asset itself is being deleted.
     */
    public static function deleteBunnyStreamVideo(?Asset $asset, bool $preserveBunnyStreamVideo = false, bool $saveAsset = true): bool
    {
        if (!$asset) {
            return false;
        }

        $bunnyStreamVideoId = self::getBunnyStreamFieldAttributes($asset)?->videoId;

        if (!$bunnyStreamVideoId) {
            return false;
        }

        if ($saveAsset) {
            self::saveBunnyStreamAttributesToAsset($asset, null);
        }

        if (!$preserveBunnyStreamVideo) {
            try {
                BunnyStreamApiHelper::deleteVideo($bunnyStreamVideoId);
            } catch (MissingConfigurationException $e) {
                throw $e;
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
            throw new MissingConfigurationException('No Bunny Stream Hostname set');
        }

        return $hostname;
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

    public static function getBunnyStreamFieldForAsset(?Asset $asset): ?BunnyStreamField
    {
        if (!$asset || $asset->kind !== Asset::KIND_VIDEO) {
            return null;
        }

        try {
            $volumeHandle = $asset->getVolume()->handle;

            if (array_key_exists($volumeHandle, self::$bunnyStreamFieldsByVolume)) {
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
