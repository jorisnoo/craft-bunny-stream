<?php

namespace Noo\CraftBunnyStream\jobs;

use Craft;
use craft\elements\Asset;
use craft\helpers\Queue;
use craft\i18n\Translation;
use craft\queue\BaseJob;
use Noo\CraftBunnyStream\exceptions\MissingConfigurationException;
use Noo\CraftBunnyStream\helpers\BlitzIntegrationHelper;
use Noo\CraftBunnyStream\helpers\BunnyStreamHelper;
use Throwable;

class RefreshBunnyStreamMetadataJob extends BaseJob
{
    private const FAST_ATTEMPTS = 20;
    private const HOURLY_ATTEMPTS = 24;
    private const MAX_DELAY = 300;
    private const BASE_DELAY = 15;

    public ?int $assetId = null;
    public ?int $siteId = null;
    public ?string $videoId = null;
    public int $attempt = 0;

    public function execute($queue): void
    {
        // A webhook is a one-off notification; it must not start a second poll chain.
        if ($this->assetId === null && $this->videoId !== null) {
            foreach (BunnyStreamHelper::findLinkedAssets($this->videoId) as $asset) {
                $this->refresh($asset);
            }
            return;
        }

        if ($this->assetId === null) {
            return;
        }

        $asset = Craft::$app->getElements()->getElementById($this->assetId, Asset::class, $this->siteId);

        if (!$asset) {
            return;
        }

        $videoId = BunnyStreamHelper::getBunnyStreamVideoId($asset);
        if ($videoId === null || ($this->videoId !== null && $this->videoId !== $videoId)) {
            return;
        }

        $this->refresh($asset);
    }

    private function refresh(Asset $asset): void
    {
        $previous = BunnyStreamHelper::getBunnyStreamStatus($asset);
        if ($this->assetId !== null && $previous?->isTerminal()) {
            return;
        }

        $lastPoll = $this->attempt + 1 >= self::FAST_ATTEMPTS + self::HOURLY_ATTEMPTS;
        try {
            if (!BunnyStreamHelper::updateBunnyStreamData($asset)) {
                return;
            }
            $status = BunnyStreamHelper::getBunnyStreamStatus($asset);
        } catch (MissingConfigurationException $e) {
            throw $e;
        } catch (Throwable $e) {
            if ($this->assetId === null || $lastPoll) {
                throw $e;
            }
            Craft::error($e, __METHOD__);
            $status = null;
        }

        if ($status?->isTerminal()) {
            if (!$previous?->isTerminal()) {
                BlitzIntegrationHelper::refreshCachesForAsset($asset);
            }
            return;
        }

        if ($this->assetId === null) {
            return;
        }

        if ($lastPoll) {
            Craft::warning("Bunny Stream metadata for asset #{$this->assetId} is still processing after the final poll.", __METHOD__);
            return;
        }

        self::schedule($this->assetId, $this->attempt + 1, $this->videoId ?? BunnyStreamHelper::getBunnyStreamVideoId($asset), $asset->siteId);
    }

    public static function schedule(int $assetId, int $attempt = 0, ?string $videoId = null, ?int $siteId = null): void
    {
        $delay = $attempt >= self::FAST_ATTEMPTS ? 3600 : min(self::MAX_DELAY, self::BASE_DELAY * (2 ** $attempt));

        Queue::push(new self([
            'assetId' => $assetId,
            'siteId' => $siteId,
            'videoId' => $videoId,
            'attempt' => $attempt,
        ]), null, $delay);
    }

    protected function defaultDescription(): ?string
    {
        return Translation::prep('bunny-stream', 'Refreshing Bunny Stream metadata');
    }
}
