<?php

namespace jorisnoo\bunnystream\behaviors;

use craft\elements\Asset;
use craft\helpers\Template;
use craft\web\View;

use jorisnoo\bunnystream\helpers\BunnyStreamHelper;

use yii\base\Behavior;

class BunnyStreamAssetBehavior extends Behavior
{

    public function isBunnyStreamVideo(): bool
    {
        return !empty($this->getBunnyStreamVideoId());
    }

    public function isBunnyStreamVideoReady(): bool
    {
        return $this->getBunnyStreamStatus() === 'finished';
    }

    public function getBunnyStreamHlsUrl(): ?string
    {
        if (!$this->owner instanceof Asset) {
            return null;
        }

        return BunnyStreamHelper::getHlsUrl($this->owner);
    }

    public function getBunnyStreamThumbnailUrl(bool $relative = false): ?string
    {
        if (!$this->owner instanceof Asset) {
            return null;
        }

        return $relative
            ? BunnyStreamHelper::getRelativeThumbnailUrl($this->owner)
            : BunnyStreamHelper::getThumbnailUrl($this->owner);
    }

    public function getBunnyStreamDirectUrl(): ?string
    {
        if (!$this->owner instanceof Asset) {
            return null;
        }

        return BunnyStreamHelper::getDirectUrl($this->owner);
    }

    public function getBunnyStreamVideoId(): ?string
    {
        if (!$this->owner instanceof Asset) {
            return null;
        }

        return BunnyStreamHelper::getBunnyStreamVideoId($this->owner);
    }

    public function getBunnyStreamData(): ?array
    {
        if (!$this->owner instanceof Asset) {
            return null;
        }

        return BunnyStreamHelper::getBunnyStreamData($this->owner);
    }

    public function getBunnyStreamStatus(): ?string
    {
        if (!$this->owner instanceof Asset) {
            return null;
        }

        return BunnyStreamHelper::getBunnyStreamStatus($this->owner);
    }

    public function getBunnyStreamAspectRatio(): float|int|null
    {
        if (!$this->owner instanceof Asset) {
            return null;
        }

        $data = BunnyStreamHelper::getBunnyStreamData($this->owner);

        if (empty($data)) {
            return null;
        }

        $width = $data['width'] ?? 0;
        $height = $data['height'] ?? 0;

        if ($width === 0 || $height === 0) {
            return null;
        }

        return (int)$width / (int)$height;
    }

}
