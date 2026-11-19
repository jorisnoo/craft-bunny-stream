<?php

namespace Noo\CraftBunnyStream\helpers;

use Craft;
use craft\base\Element;
use craft\db\Connection;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\helpers\Html;
use Noo\CraftBunnyStream\BunnyStream;
use Noo\CraftBunnyStream\enums\VideoStatus;
use Noo\CraftBunnyStream\exceptions\BunnyException;
use Noo\CraftBunnyStream\exceptions\MissingConfigurationException;
use Noo\CraftBunnyStream\fields\BunnyStreamField;
use Noo\CraftBunnyStream\jobs\DeleteBunnyStreamVideoJob;
use Noo\CraftBunnyStream\jobs\RefreshBunnyStreamMetadataJob;
use Noo\CraftBunnyStream\models\BunnyStreamFieldAttributes;
use Throwable;
use yii\base\Event;

class BunnyStreamHelper
{
    /** Run only on commit, not on rollback like Connection::onAfterTransaction(). */
    public static function afterCommit(callable $callback): void
    {
        $db = Craft::$app->getDb();
        if ($db->getTransaction() === null) {
            $callback();
            return;
        }

        $finish = static function(Event $event) use ($db, &$finish, $callback) {
            $db->off(Connection::EVENT_COMMIT_TRANSACTION, $finish);
            $db->off(Connection::EVENT_ROLLBACK_TRANSACTION, $finish);
            if ($event->name === Connection::EVENT_COMMIT_TRANSACTION) {
                $callback();
            }
        };
        $db->on(Connection::EVENT_COMMIT_TRANSACTION, $finish);
        $db->on(Connection::EVENT_ROLLBACK_TRANSACTION, $finish);
    }

    public static function getBunnyStreamVideoId(?Asset $asset): ?string
    {
        return self::getBunnyStreamFieldAttributes($asset)?->videoId;
    }

    public static function getBunnyStreamData(?Asset $asset): ?array
    {
        $attributes = self::getBunnyStreamFieldAttributes($asset);
        return $attributes?->videoId ? $attributes->metaData : null;
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
        if (!$videoId || !($hostname = self::getCdnHostname())) {
            return null;
        }
        return "https://$hostname/" . rawurlencode($videoId) . '/playlist.m3u8';
    }

    public static function getDirectUrl(Asset $asset): ?string
    {
        $videoId = self::getBunnyStreamVideoId($asset);
        if (!$videoId) {
            return null;
        }
        try {
            $libraryId = BunnyStreamApiHelper::getLibraryId();
        } catch (MissingConfigurationException) {
            return null;
        }
        return "https://player.mediadelivery.net/embed/$libraryId/" . rawurlencode($videoId);
    }

    public static function getEmbedUrl(Asset $asset, array $params = []): ?string
    {
        $url = self::getDirectUrl($asset);
        if ($url === null) {
            return null;
        }

        $query = http_build_query([
            'autoplay' => 'false',
            'preload' => 'true',
            'responsive' => 'true',
            ...$params,
        ]);

        return $url . '?' . $query;
    }

    public static function getEmbedHtml(Asset $asset, array $params = []): string
    {
        $iframe = self::getIframeHtml($asset, $params, [
            'style' => 'border:0;position:absolute;top:0;height:100%;width:100%;',
        ]);
        return $iframe === '' ? '' : Html::tag('div', $iframe, ['style' => 'position:relative;padding-top:56.25%;']);
    }

    public static function getIframeHtml(Asset $asset, array $params = [], array $options = []): string
    {
        $url = self::getEmbedUrl($asset, $params);
        if ($url === null) {
            return '';
        }
        return Html::tag('iframe', '', [
            'src' => $url,
            'loading' => 'lazy',
            'allow' => 'accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture',
            'allowfullscreen' => true,
        ] + $options);
    }

    public static function getRelativeThumbnailUrl(Asset $asset): ?string
    {
        $videoId = self::getBunnyStreamVideoId($asset);
        $thumbnailFileName = self::getBunnyStreamData($asset)['thumbnailFileName'] ?? null;

        if (!$videoId || !is_string($thumbnailFileName) || $thumbnailFileName === '') {
            return null;
        }

        return '/' . rawurlencode($videoId) . '/' . rawurlencode($thumbnailFileName);
    }

    public static function getThumbnailUrl(Asset $asset): ?string
    {
        $path = self::getRelativeThumbnailUrl($asset);
        if ($path === null || !($hostname = self::getCdnHostname())) {
            return null;
        }
        return "https://$hostname$path";
    }

    public static function updateOrCreateBunnyStreamAsset(?Asset $asset, bool $createOnly = false): bool
    {
        if (!$asset?->id || !self::getBunnyStreamFieldForAsset($asset)) {
            return false;
        }
        if (self::getBunnyStreamVideoId($asset)) {
            return !$createOnly && self::updateBunnyStreamData($asset);
        }

        return self::withAssetMutex($asset->id, static function() use ($asset) {
            $current = self::loadAsset($asset);
            if (!$current || self::getBunnyStreamVideoId($current)) {
                return false;
            }

            $video = BunnyStreamApiHelper::createVideo($current);
            $videoId = $video['guid'];
            try {
                $receipt = DeleteBunnyStreamVideoJob::forCreatedVideo($current, $videoId);
            } catch (Throwable $e) {
                BunnyStreamApiHelper::discardCreatedVideo($current, $videoId);
                throw $e;
            }
            try {
                $saved = self::publishIfCurrent($current, $asset, $video, $receipt->ownershipProof);
            } catch (Throwable $e) {
                // A post-save listener may have failed after a commit. Check
                // for a surviving link before compensating for the failure.
                $receipt->schedule();
                throw $e;
            }
            if (!$saved) {
                $receipt->schedule();
                return false;
            }
            self::afterCommit(static fn() => RefreshBunnyStreamMetadataJob::schedule($asset->id, videoId: $videoId, siteId: $asset->siteId));
            return true;
        });
    }

    public static function updateBunnyStreamData(?Asset $asset): bool
    {
        $videoId = self::getBunnyStreamVideoId($asset);
        if (!$asset?->id || !$videoId) {
            return false;
        }
        return self::withAssetMutex($asset->id, static function() use ($asset, $videoId) {
            $current = self::loadAsset($asset);
            if (!$current || self::getBunnyStreamVideoId($current) !== $videoId) {
                return false;
            }
            return self::publishIfCurrent($current, $asset, BunnyStreamApiHelper::getVideo($videoId));
        });
    }

    private static function withAssetMutex(int $id, callable $callback): bool
    {
        $mutex = Craft::$app->getMutex();
        $key = "bunny-stream:asset:$id";
        if (!$mutex->acquire($key, 5)) {
            throw new BunnyException("Bunny Stream asset $id is already being processed.");
        }
        try {
            return $callback();
        } finally {
            $mutex->release($key);
        }
    }

    private static function loadAsset(Asset $asset): ?Asset
    {
        $current = Craft::$app->getElements()->getElementById($asset->id, Asset::class, $asset->siteId);
        return $current && !$current->getIsDerivative() && self::getBunnyStreamFieldForAsset($current) ? $current : null;
    }

    /** Re-read under a short write lock; never hold a DB transaction during an upload. */
    private static function publishIfCurrent(Asset $source, Asset $caller, array $video, ?string $newProof = null): bool
    {
        $db = Craft::$app->getDb();
        return $db->transaction(static function() use ($db, $source, $caller, $video, $newProof) {
            $db->createCommand('SELECT [[id]] FROM ' . Table::ELEMENTS . ' WHERE [[id]] = :id FOR UPDATE', [
                ':id' => $source->id,
            ])->queryScalar();
            $current = self::loadAsset($source);
            $currentAttributes = self::getBunnyStreamFieldAttributes($current);
            $sourceAttributes = self::getBunnyStreamFieldAttributes($source);
            if (
                !$current ||
                $currentAttributes?->videoId !== $sourceAttributes?->videoId ||
                $currentAttributes?->generation !== $sourceAttributes?->generation
            ) {
                return false;
            }

            $attributes = [
                'videoId' => $currentAttributes->videoId ?? $video['guid'],
                'metaData' => $video,
                'ownershipProof' => $newProof ?? $currentAttributes?->ownershipProof,
                'generation' => $currentAttributes?->generation,
            ];
            if (self::getBunnyStreamData($current) != $video && !self::saveBunnyStreamAttributesToAsset($current, $attributes)) {
                return false;
            }
            self::setBunnyStreamFieldAttributes($caller, $attributes);
            return true;
        });
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
        if (!$asset?->id) {
            return false;
        }
        $persisted = self::loadAsset($asset);
        $videoId = self::getBunnyStreamVideoId($persisted);
        $delete = DeleteBunnyStreamVideoJob::forPersistedLink($persisted);
        if (!$persisted || !$videoId || !self::saveBunnyStreamAttributesToAsset($persisted, null)) {
            return false;
        }
        self::setBunnyStreamFieldAttributes($asset, null);
        if (!$preserveBunnyStreamVideo) {
            $delete?->schedule();
        }
        return true;
    }

    public static function getBunnyStreamFieldAttributes(?Asset $asset): ?BunnyStreamFieldAttributes
    {
        $field = self::getBunnyStreamFieldForAsset($asset);
        if (!$field) {
            return null;
        }

        $attributes = $asset->getFieldValue($field->handle);
        return $attributes instanceof BunnyStreamFieldAttributes ? $attributes : null;
    }

    private static function saveAsset(Asset $asset, string $action): bool
    {
        $scenario = $asset->getScenario();
        $resaving = $asset->resaving;
        $asset->setScenario(Element::SCENARIO_ESSENTIALS);
        $asset->resaving = true;
        try {
            if (!Craft::$app->getElements()->saveElement($asset, false)) {
                throw new BunnyException("Unable to $action Bunny Stream attributes for asset $asset->id.");
            }
        } finally {
            $asset->resaving = $resaving;
            $asset->setScenario($scenario);
        }
        return true;
    }

    private static function getCdnHostname(): ?string
    {
        return BunnyStream::getInstance()->getSettings()->bunnyStreamCdnHostname;
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
        foreach ($asset->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            if ($field instanceof BunnyStreamField) {
                return $field;
            }
        }
        return null;
    }

    /** @return iterable<Asset> Matching field instances, including per-layout handle overrides. */
    public static function findLinkedAssets(string $videoId, bool $includeTrashed = false): iterable
    {
        $seen = [];
        foreach (Craft::$app->getFields()->getLayoutsByType(Asset::class) as $layout) {
            foreach ($layout->getCustomFields() as $field) {
                if (!$field instanceof BunnyStreamField || isset($seen[$field->handle])) {
                    continue;
                }
                $seen[$field->handle] = true;
                /** @var AssetQuery $query */
                $query = Craft::$app->getElements()->createElementQuery(Asset::class);
                // In Craft status(null) still excludes archived rows.
                $statuses = $includeTrashed ? [Element::STATUS_ENABLED, Element::STATUS_DISABLED, Element::STATUS_ARCHIVED] : null;
                $query->site('*')->status($statuses)->trashed($includeTrashed ? null : false);
                if ($includeTrashed) {
                    $query->drafts(null)->revisions(null)->provisionalDrafts(null);
                }
                $query->{$field->handle}(['value' => $videoId, 'caseInsensitive' => true]);
                foreach ($query->all() as $asset) {
                    if (!$asset->getFieldLayout()?->getFieldByHandle($field->handle) instanceof BunnyStreamField) {
                        continue;
                    }
                    $attributes = $asset->getFieldValue($field->handle);
                    if ($attributes instanceof BunnyStreamFieldAttributes && strcasecmp($attributes->videoId ?? '', $videoId) === 0) {
                        yield $asset;
                    }
                }
            }
        }
    }
}
