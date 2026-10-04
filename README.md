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

Requests without the configured token are rejected before their payload is processed. The plugin uses the `VideoGuid` in an authenticated payload to find the matching asset and refresh its metadata.

## Setup

1. Create a Bunny Stream field and add it to the field layout of any asset volume that holds video assets. Only one Bunny Stream field per layout is allowed, and the field cannot be added to non-asset layouts.
2. Upload a video asset to that volume. The plugin queues creation of the Bunny Stream video and stores its `videoId` and metadata on the asset when the job runs.
3. A queue job (`RefreshBunnyStreamMetadataJob`) polls Bunny Stream with exponential backoff until the video reaches a terminal status (`Finished`, `Error`, or `UploadFailed`).

In production, the plugin asks Bunny Stream to fetch the video over HTTP from the asset's public URL. In `dev` environments, or when an asset has no public URL, the file is uploaded as a binary stream instead.

Replacing or deleting an asset deletes the corresponding Bunny Stream video.

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
| `getBunnyStreamHlsUrl()` | HLS playlist URL on the configured CDN hostname |
| `getBunnyStreamDirectUrl()` | The `player.mediadelivery.net` embed URL |
| `getBunnyStreamEmbedUrl(params)` | Embed URL with query params merged in |
| `getBunnyStreamEmbed(params)` | Responsive `<iframe>` embed HTML |
| `getBunnyStreamThumbnailUrl(relative)` | Thumbnail URL (or path-only when `relative` is true) |
| `getBunnyStreamAspectRatio()` | Width / height as a float, or `null` |

The default embed query string is `autoplay=false&preload=true&responsive=true`. Anything passed to `getBunnyStreamEmbedUrl()` or `getBunnyStreamEmbed()` is merged on top.

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

## Logging

The plugin writes to `storage/logs/bunny-stream-YYYY-MM-DD.log` under the `bunny-stream` log category, keeping 30 days of rotated logs.

## License

MIT. See [LICENSE.md](LICENSE.md).

## Maintainer releases

See [RELEASING.md](RELEASING.md) for versioning, changelog entries and the release command.
