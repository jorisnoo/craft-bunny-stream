<?php

namespace Noo\CraftBunnyStream\behaviors;

use craft\elements\Asset;

use Noo\CraftBunnyStream\helpers\BunnyStreamHelper;

use yii\base\Behavior;

class BunnyStreamAssetBehavior extends Behavior
{
    private function asset(): ?Asset
    {
        return $this->owner instanceof Asset ? $this->owner : null;
    }

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
        return $this->asset() ? BunnyStreamHelper::getHlsUrl($this->asset()) : null;
    }

    public function getBunnyStreamThumbnailUrl(bool $relative = false): ?string
    {
        $asset = $this->asset();
        if (!$asset) {
            return null;
        }

        return $relative
            ? BunnyStreamHelper::getRelativeThumbnailUrl($asset)
            : BunnyStreamHelper::getThumbnailUrl($asset);
    }

    public function getBunnyStreamDirectUrl(): ?string
    {
        return $this->asset() ? BunnyStreamHelper::getDirectUrl($this->asset()) : null;
    }

    public function getBunnyStreamVideoId(): ?string
    {
        return $this->asset() ? BunnyStreamHelper::getBunnyStreamVideoId($this->asset()) : null;
    }

    public function getBunnyStreamData(): ?array
    {
        return $this->asset() ? BunnyStreamHelper::getBunnyStreamData($this->asset()) : null;
    }

    public function getBunnyStreamStatus(): ?string
    {
        return $this->asset() ? BunnyStreamHelper::getBunnyStreamStatus($this->asset()) : null;
    }

    public function getBunnyStreamAspectRatio(): float|int|null
    {
        $data = $this->getBunnyStreamData();

        if (empty($data)) {
            return null;
        }

        $width = (int)($data['width'] ?? 0);
        $height = (int)($data['height'] ?? 0);

        if ($width === 0 || $height === 0) {
            return null;
        }

        return $width / $height;
    }
}
