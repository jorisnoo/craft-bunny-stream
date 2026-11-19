# Bunny Stream

A [Craft CMS](https://craftcms.com) plugin that integrates [Bunny Stream](https://bunny.net/stream/) with native video assets. When a video asset is uploaded, the plugin creates a matching Bunny Stream video, syncs metadata back to the asset, and exposes playback URLs and embed helpers in templates. Inspired by [MuxMate](https://github.com/vaersaagod/muxmate).

## Requirements

- Craft CMS 5.0 or later
- PHP 8.2 or later
- A [Bunny Stream](https://bunny.net/stream/) library

## Installation

```bash
composer require jorisnoo/craft-bunny-stream
php craft plugin/install bunny-stream
```

## Configuration

Settings are loaded from environment variables, or you can override them with a `config/bunny-stream.php` file.

### Environment variables

```dotenv
BUNNY_STREAM_ACCESS_KEY=
BUNNY_STREAM_LIBRARY_ID=
BUNNY_STREAM_CDN_HOSTNAME=
BUNNY_STREAM_COLLECTION_ID=
BUNNY_STREAM_WEBHOOK_TOKEN=
```

`BUNNY_STREAM_COLLECTION_ID` is optional. If set, new videos are created inside that collection.
`BUNNY_STREAM_WEBHOOK_TOKEN` must be a long, random secret used only to authenticate webhook requests.

### Config file

```php
<?php
// config/bunny-stream.php
use craft\helpers\App;

return [
    'bunnyStreamAccessKey' => App::env('BUNNY_STREAM_ACCESS_KEY'),
    'bunnyStreamLibraryId' => App::env('BUNNY_STREAM_LIBRARY_ID'),
    'bunnyStreamCdnHostname' => App::env('BUNNY_STREAM_CDN_HOSTNAME'),
    'bunnyStreamCollectionId' => App::env('BUNNY_STREAM_COLLECTION_ID'),
    'bunnyStreamWebhookToken' => App::env('BUNNY_STREAM_WEBHOOK_TOKEN'),
];
```

### Webhook

Generate a dedicated secret (for example, with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`) and set it as `BUNNY_STREAM_WEBHOOK_TOKEN`. Then point Bunny Stream at:

```text
https://your-site.tld/bunnystream/webhook?token=YOUR_URL_ENCODED_TOKEN
```

Requests without the configured token are rejected before their payload is processed. For a matching asset, the webhook queues a metadata refresh; Bunny API requests and optional Blitz refreshes run in the worker. Keep a Craft queue runner active.

## Setup

1. Create a Bunny Stream field and add it to the field layout of any asset volume that holds video assets. Only one Bunny Stream field per layout is allowed, and the field cannot be added to non-asset layouts.
2. Upload a video asset to that volume. After the save commits, the plugin queues creation of the Bunny Stream video and stores its ID and metadata on the asset. Other volumes aren't uploaded. Bunny IDs and metadata are managed by the plugin; values submitted from a form are ignored.
3. A queue job polls with exponential backoff until the video reaches `Finished`, `Error`, or `UploadFailed`. After 20 fast polls, it continues hourly for up to another day. Configure the authenticated webhook as well so late status updates aren't missed; `sync/metadata` can reconcile an asset after polling ends.

In production, the plugin asks Bunny Stream to fetch the video over HTTP from the asset's public URL. In `dev` environments, or when an asset has no public URL, the file is uploaded as a binary stream instead.

Replacement queues removal of the previous video and creation of a new one. Deletion does not require re-saving the deleted asset; videos for restorable, file-retaining soft deletions are preserved.

Only a video created by this plugin for the same asset and library can be automatically removed. Existing links from older releases remain playable and continue receiving metadata, but the plugin cannot prove who originally created them. After replacing or deleting an older asset, verify the old video's ownership and remaining uses in the library before removing it manually.

Ownership is bound to the asset, library and Craft security key. Rotating that key also invalidates existing deletion proofs; treat those links like legacy links when cleaning up the library.

## Twig API

A behavior is attached to all `craft\elements\Asset` elements:

```twig
{% if asset.isBunnyStreamVideo %}
    {% if asset.isBunnyStreamVideoReady %}
        {{ asset.getBunnyStreamEmbed({ autoplay: 'true' })|raw }}
    {% else %}
        <p>Video is still processing.</p>
    {% endif %}
{% endif %}
```

### Available methods

| Method | Returns |
|---|---|
| `isBunnyStreamVideo()` | `bool`: whether the asset has a Bunny Stream video |
| `isBunnyStreamVideoReady()` | `bool`: true when status is `Finished` |
| `getBunnyStreamVideoId()` | The Bunny Stream `guid` |
| `getBunnyStreamStatus()` | A `VideoStatus` enum case |
| `getBunnyStreamData()` | The full metadata array from Bunny Stream |
| `getBunnyStreamHlsUrl()` | HLS playlist URL on the configured CDN hostname, or `null` without a video |
| `getBunnyStreamDirectUrl()` | The `player.mediadelivery.net` embed URL, or `null` without a video |
| `getBunnyStreamEmbedUrl(params)` | Embed URL with query parameters, or `null` without a video |
| `getBunnyStreamEmbed(params)` | Responsive `<iframe>` HTML when a video is linked |
| `getBunnyStreamThumbnailUrl(relative)` | Thumbnail URL (or path-only when `relative` is true), or `null` without a thumbnail |
| `getBunnyStreamThumbnailBlurhash()` | Placeholder blurhash from Bunny metadata, or `null` |
| `preventBlitzCachingWhileProcessing()` | Disables Blitz caching for this response if the asset is still processing |
| `getBunnyStreamAspectRatio()` | Width / height as a float, or `null` |

The default embed query string is `autoplay=false&preload=true&responsive=true`. Anything passed to `getBunnyStreamEmbedUrl()` or `getBunnyStreamEmbed()` is merged on top.

### Optional Blitz integration

Call the helper on pages that display a temporary processing message:

```twig
{% do asset.preventBlitzCachingWhileProcessing() %}
```

Without that call the plugin does not disable page caching. With Blitz installed, a terminal encoding update refreshes pages that reference the asset, including through Matrix entries.

### Control panel

For video assets that have a Bunny Stream video, the plugin:

- Renders the Bunny Stream player as the asset preview.
- Adds an embedded player to the asset editor sidebar.
- Replaces the asset thumbnail with the Bunny Stream thumbnail.
- Shows a status icon in the field's table column (`👍` ready, `⏳` processing, `❌` no video).

The asset's native `focalPoint` is reset whenever the underlying video is replaced, since the new video's framing may differ.

## Console commands

```bash
# Refresh metadata for every video asset that already has a Bunny Stream video
php craft bunny-stream/sync/metadata

# Also create Bunny Stream videos for assets that don't have one yet
php craft bunny-stream/sync/metadata --bootstrap
```

The command exits nonzero if any asset fails. After upgrading a legacy installation, run Craft's migrations to convert the old field keys, then run `php craft bunny-stream/sync/metadata` to refresh existing linked videos. Only use `--bootstrap` if unlinked videos should be uploaded too.

## Logging

Plugin messages go to `storage/logs/bunny-stream.log`. Yii rotates it at 10 MiB and retains up to 30 rotated files; retention is based on size, not days.

## License

MIT. See [LICENSE.md](LICENSE.md).

## Maintainer releases

See [RELEASING.md](RELEASING.md) for versioning, changelog entries and the release command.
