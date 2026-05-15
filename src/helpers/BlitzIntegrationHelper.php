<?php

namespace Noo\CraftBunnyStream\helpers;

use craft\elements\Asset;
use craft\elements\Entry;
use putyourlightson\blitz\Blitz;
use putyourlightson\blitz\models\SiteUriModel;

class BlitzIntegrationHelper
{
    public static function isAvailable(): bool
    {
        return class_exists(Blitz::class) && Blitz::$plugin !== null;
    }

    public static function preventCachingCurrentRequest(): void
    {
        if (!self::isAvailable()) {
            return;
        }

        Blitz::$plugin->generateCache->options->cachingEnabled(false);
    }

    public static function refreshCachesForAsset(Asset $asset): void
    {
        if (!self::isAvailable()) {
            return;
        }

        $entries = Entry::find()
            ->relatedTo($asset)
            ->status(Entry::STATUS_LIVE)
            ->siteId('*')
            ->unique()
            ->all();

        $siteUris = [];
        foreach ($entries as $entry) {
            if ($entry->uri === null) {
                continue;
            }

            $siteUris[] = new SiteUriModel([
                'siteId' => $entry->siteId,
                'uri' => $entry->uri,
            ]);
        }

        if (!$siteUris) {
            return;
        }

        Blitz::$plugin->refreshCache->refreshSiteUris($siteUris);
    }
}
