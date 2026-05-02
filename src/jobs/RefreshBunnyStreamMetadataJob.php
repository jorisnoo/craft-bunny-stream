<?php

namespace Noo\CraftBunnyStream\jobs;

use Craft;
use craft\elements\Asset;
use craft\helpers\Queue;
use craft\i18n\Translation;
use craft\queue\BaseJob;
use Noo\CraftBunnyStream\helpers\BunnyStreamHelper;
use Throwable;

class RefreshBunnyStreamMetadataJob extends BaseJob
{
    private const MAX_ATTEMPTS = 20;
    private const MAX_DELAY = 300;
    private const BASE_DELAY = 15;

    public int $assetId;
    public int $attempt = 0;

    public function execute($queue): void
    {
        $asset = Craft::$app->getElements()->getElementById($this->assetId, Asset::class);

        if (!$asset || !BunnyStreamHelper::getBunnyStreamVideoId($asset)) {
            return;
        }

        try {
            BunnyStreamHelper::updateBunnyStreamData($asset);
        } catch (Throwable $e) {
            Craft::error($e, __METHOD__);
        }

        $status = BunnyStreamHelper::getBunnyStreamStatus($asset);

        if (($status?->isTerminal() ?? false) || $this->attempt + 1 >= self::MAX_ATTEMPTS) {
            return;
        }

        self::schedule($this->assetId, $this->attempt + 1);
    }

    public static function schedule(int $assetId, int $attempt = 0): void
    {
        $delay = min(self::MAX_DELAY, self::BASE_DELAY * (2 ** $attempt));

        Queue::push(new self([
            'assetId' => $assetId,
            'attempt' => $attempt,
        ]), null, $delay);
    }

    protected function defaultDescription(): ?string
    {
        return Translation::prep('bunny-stream', 'Refreshing Bunny Stream metadata');
    }
}
