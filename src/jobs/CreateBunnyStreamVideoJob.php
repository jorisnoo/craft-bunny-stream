<?php

namespace Noo\CraftBunnyStream\jobs;

use Craft;
use craft\elements\Asset;
use craft\helpers\Queue;
use craft\i18n\Translation;
use craft\queue\BaseJob;
use Noo\CraftBunnyStream\exceptions\MissingConfigurationException;
use Noo\CraftBunnyStream\helpers\BunnyStreamHelper;
use yii\queue\RetryableJobInterface;

class CreateBunnyStreamVideoJob extends BaseJob implements RetryableJobInterface
{
    public int $assetId;
    public ?int $siteId = null;

    public function execute($queue): void
    {
        $asset = Craft::$app->getElements()->getElementById($this->assetId, Asset::class, $this->siteId ?? '*');
        // Jobs describe desired state, not a file snapshot. A deleted asset or
        // an asset already linked by another job/CLI process needs no upload.
        BunnyStreamHelper::updateOrCreateBunnyStreamAsset($asset, createOnly: true);
    }

    public static function schedule(int $assetId, ?int $siteId = null): void
    {
        BunnyStreamHelper::afterCommit(static fn() => Queue::push(new self([
            'assetId' => $assetId,
            'siteId' => $siteId,
        ])));
    }

    public function getTtr(): int
    {
        // A binary PUT is bounded at an hour; allow time for metadata and the save.
        return 3900;
    }

    public function canRetry($attempt, $error): bool
    {
        return $attempt < 3 && !$error instanceof MissingConfigurationException;
    }

    protected function defaultDescription(): ?string
    {
        return Translation::prep('bunny-stream', 'Creating Bunny Stream video');
    }
}
