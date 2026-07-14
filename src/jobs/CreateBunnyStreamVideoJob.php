<?php

namespace Noo\CraftBunnyStream\jobs;

use Craft;
use craft\elements\Asset;
use craft\helpers\Queue;
use craft\i18n\Translation;
use craft\queue\BaseJob;
use Noo\CraftBunnyStream\helpers\BunnyStreamHelper;

class CreateBunnyStreamVideoJob extends BaseJob
{
    public int $assetId;

    public function execute($queue): void
    {
        $asset = Craft::$app->getElements()->getElementById($this->assetId, Asset::class);

        if (
            !$asset ||
            $asset->kind !== Asset::KIND_VIDEO ||
            BunnyStreamHelper::getBunnyStreamVideoId($asset)
        ) {
            return;
        }

        BunnyStreamHelper::updateOrCreateBunnyStreamAsset($asset);
    }

    public static function schedule(int $assetId): void
    {
        Queue::push(new self([
            'assetId' => $assetId,
        ]));
    }

    protected function defaultDescription(): ?string
    {
        return Translation::prep('bunny-stream', 'Creating Bunny Stream video');
    }
}
