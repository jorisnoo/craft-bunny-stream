<?php

namespace Noo\CraftBunnyStream\jobs;

use Craft;
use craft\elements\Asset;
use craft\helpers\Queue;
use craft\i18n\Translation;
use craft\queue\BaseJob;
use Noo\CraftBunnyStream\exceptions\MissingConfigurationException;
use Noo\CraftBunnyStream\helpers\BunnyStreamApiHelper;
use Noo\CraftBunnyStream\helpers\BunnyStreamHelper;
use yii\queue\RetryableJobInterface;

class DeleteBunnyStreamVideoJob extends BaseJob implements RetryableJobInterface
{
    public string $assetUid;
    public string $videoId;
    public string $ownershipProof;

    /** Only mint a receipt for a GUID just allocated by Bunny, never an existing field value. */
    public static function forCreatedVideo(Asset $asset, string $videoId): self
    {
        $job = new self(['assetUid' => $asset->uid, 'videoId' => $videoId]);
        $job->ownershipProof = $job->expectedProof();
        return $job;
    }

    /** Capture the server-written receipt before Craft removes the element row. */
    public static function forPersistedLink(?Asset $asset): ?self
    {
        $attributes = BunnyStreamHelper::getBunnyStreamFieldAttributes($asset);
        if (!$asset?->uid || !$attributes?->videoId || !$attributes->ownershipProof) {
            return null;
        }
        return new self([
            'assetUid' => $asset->uid,
            'videoId' => $attributes->videoId,
            'ownershipProof' => $attributes->ownershipProof,
        ]);
    }

    public function schedule(): void
    {
        BunnyStreamHelper::afterCommit(fn() => Queue::push($this));
    }

    public function execute($queue): void
    {
        // A legacy field, a duplicated asset, a different library, or a rotated
        // site key must not confer permission to destroy a video.
        if (!hash_equals($this->expectedProof(), $this->ownershipProof)) {
            return;
        }
        foreach (BunnyStreamHelper::findLinkedAssets($this->videoId, includeTrashed: true) as $linked) {
            if (!$linked->dateDeleted || $linked->keptFile) {
                return;
            }
        }
        BunnyStreamApiHelper::deleteVideo($this->videoId);
    }

    private function expectedProof(): string
    {
        $key = Craft::$app->getConfig()->getGeneral()->securityKey;
        if ($key === '') {
            throw new MissingConfigurationException('The Craft security key is required for Bunny Stream ownership.');
        }
        $library = BunnyStreamApiHelper::getLibraryId();
        return hash_hmac('sha256', "bunny-stream:owner:$library:$this->assetUid:$this->videoId", $key);
    }

    public function getTtr(): int
    {
        return 120;
    }

    public function canRetry($attempt, $error): bool
    {
        return $attempt < 3 && !$error instanceof MissingConfigurationException;
    }

    protected function defaultDescription(): ?string
    {
        return Translation::prep('bunny-stream', 'Deleting Bunny Stream video');
    }
}
