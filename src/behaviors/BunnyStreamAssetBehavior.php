<?php

namespace jorisnoo\bunnystream\behaviors;

use craft\elements\Asset;
use craft\helpers\Template;
use craft\web\View;

use jorisnoo\bunnystream\helpers\BunnyStreamHelper;

use yii\base\Behavior;

class BunnyStreamAssetBehavior extends Behavior
{

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



//    public function isBunnyStreamVideoReady(): bool
//    {
////        return $this->getMuxStatus() === 'ready';
//    }

//    /**
//     * @return bool
//     */
//    public function isMuxVideo(): bool
//    {
//        return !empty($this->getMuxPlaybackId());
//    }
//
//    /**
//     * @return bool
//     */
//    public function isMuxVideoReady(): bool
//    {
//        return $this->getMuxStatus() === 'ready';
//    }
//
//    /**
//     * @return string|null
//     */
//    public function getMuxStreamUrl(): ?string
//    {
//        if (
//            !$this->owner instanceof Asset ||
//            !$playbackId = MuxMateHelper::getMuxPlaybackId($this->owner)
//        ) {
//            return null;
//        }
//        return MuxApiHelper::getStreamUrl($playbackId);
//    }

//
//    /**
//     * @param string $quality
//     * @param bool $download
//     * @param string|null $filename
//     * @return string|null
//     * @throws \Exception
//     */
//    public function getMuxMp4Url(string $quality = 'high', bool $download = false, ?string $filename = null): ?string
//    {
//        if (
//            !$this->owner instanceof Asset ||
//            !$playbackId = MuxMateHelper::getMuxPlaybackId($this->owner)
//        ) {
//            return null;
//        }
//        $qualities = ['high', 'medium', 'low'];
//        if (!in_array($quality, $qualities)) {
//            throw new \Exception("Invalid quality \"$quality\" (needs to be one of " . implode(', ', $qualities) . ')');
//        }
//        $muxData = MuxMateHelper::getMuxData($this->owner) ?? [];
//        $staticRenditions = $muxData['static_renditions'] ?? [];
//        if (($staticRenditions['status'] ?? null) !== 'ready') {
//            return null;
//        }
//        $availableQualities = [];
//        foreach ($muxData['static_renditions']['files'] ?? [] as $staticRendition) {
//            $availableQualities[] = explode('.', $staticRendition['name'])[0];
//        }
//        if (empty($availableQualities)) {
//            return null;
//        }
//        $availableQualities = array_values(array_intersect($qualities, $availableQualities));
//        if (!in_array($quality, $availableQualities)) {
//            $quality = $availableQualities[0];
//        }
//        return MuxApiHelper::getMp4Url($playbackId, $quality, $download, $filename);
//    }
//
//    /**
//     * See https://docs.mux.com/guides/video/get-images-from-a-video for params
//     *
//     * @param array $params
//     * @return string|null
//     */
//    public function getMuxImageUrl(array $params = []): ?string
//    {
//        if (
//            !$this->owner instanceof Asset ||
//            !MuxMateHelper::getMuxPlaybackId($this->owner) ||
//            MuxMateHelper::getMuxStatus($this->owner) !== 'ready'
//        ) {
//            return null;
//        }
//        return MuxApiHelper::getImageUrl(MuxMateHelper::getMuxPlaybackId($this->owner), $params);
//    }
//
//    /**
//     * See https://docs.mux.com/guides/video/get-images-from-a-video#get-an-animated-gif-from-a-video for params
//     *
//     * @param array $params
//     * @return string|null
//     */
//    public function getMuxGifUrl(array $params = []): ?string
//    {
//        if (
//            !$this->owner instanceof Asset ||
//            !MuxMateHelper::getMuxPlaybackId($this->owner) ||
//            MuxMateHelper::getMuxStatus($this->owner) !== 'ready'
//        ) {
//            return null;
//        }
//        return MuxApiHelper::getGifUrl(MuxMateHelper::getMuxPlaybackId($this->owner), $params);
//    }
//

//
//    /**
//     * @return string|null
//     */
//    public function getMuxStatus(): ?string
//    {
//        if (!$this->owner instanceof Asset) {
//            return null;
//        }
//        return MuxMateHelper::getMuxStatus($this->owner);
//    }
//
//    /**
//     * @return float|int|null
//     */
//    public function getMuxAspectRatio(): float|int|null
//    {
//        if (!$this->owner instanceof Asset) {
//            return null;
//        }
//
//        $data = MuxMateHelper::getMuxData($this->owner);
//        if (empty($data)) {
//            return null;
//        }
//
//        $aspectRatio = $data['aspect_ratio'] ?? null;
//        if (empty($aspectRatio) || !is_string($aspectRatio)) {
//            return null;
//        }
//
//        $temp = array_map('intval', explode(':', $aspectRatio));
//        $width = $temp[0] ?? null;
//        $height = $temp[1] ?? null;
//
//        if (!$width || !$height) {
//            return null;
//        }
//
//        return $width / $height;
//
//    }

}
