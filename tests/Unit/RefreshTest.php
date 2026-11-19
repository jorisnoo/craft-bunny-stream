<?php

use craft\config\GeneralConfig;
use craft\console\Application;
use craft\db\Connection;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\models\FieldLayout;
use craft\services\Config;
use craft\services\Elements;
use craft\services\Fields;
use craft\services\Plugins;
use craft\web\Request;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Noo\CraftBunnyStream\BunnyStream;
use Noo\CraftBunnyStream\console\controllers\SyncController;
use Noo\CraftBunnyStream\controllers\WebhookController;
use Noo\CraftBunnyStream\enums\VideoStatus;
use Noo\CraftBunnyStream\fields\BunnyStreamField;
use Noo\CraftBunnyStream\helpers\BunnyStreamApiHelper;
use Noo\CraftBunnyStream\jobs\RefreshBunnyStreamMetadataJob;
use Noo\CraftBunnyStream\models\BunnyStreamFieldAttributes;
use putyourlightson\blitz\Blitz;
use putyourlightson\blitz\services\RefreshCacheService;
use yii\console\ExitCode;
use yii\db\Command;
use yii\mutex\Mutex;
use yii\queue\Queue;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;

beforeEach(function () {
    $this->previousApp = Craft::$app;
    Craft::$app = $this->createStub(Application::class);
    $this->guid = 'd6a325f0-40f7-46ea-a11c-612cd0f43219';
    $this->elements = $this->createMock(Elements::class);
    Craft::$app->method('getElements')->willReturn($this->elements);

    $this->jobs = [];
    $this->queue = $this->createStub(Queue::class);
    $this->queue->method('priority')->willReturnSelf();
    $this->queue->method('ttr')->willReturnSelf();
    $this->queue->method('delay')->willReturnCallback(function ($delay) {
        $this->delay = $delay;
        return $this->queue;
    });
    $this->queue->method('push')->willReturnCallback(function ($job) {
        $this->jobs[] = [$job, $this->delay];
        return '1';
    });
    Craft::$app->method('getQueue')->willReturn($this->queue);

    $plugin = (new ReflectionClass(BunnyStream::class))->newInstanceWithoutConstructor();
    BunnyStream::setInstance($plugin);
    $plugin->setSettings(['bunnyStreamLibraryId' => '23', 'bunnyStreamAccessKey' => 'test-key', 'bunnyStreamWebhookToken' => 'test-token']);

    $this->http = new MockHandler();
    $config = $this->createStub(Config::class);
    $config->method('getConfigFromFile')->willReturn(['handler' => HandlerStack::create($this->http)]);
    $config->method('getGeneral')->willReturn(new GeneralConfig());
    Craft::$app->method('getVersion')->willReturn('5.0.0');
    Craft::$app->method('getConfig')->willReturn($config);
    $this->clientProperty = new ReflectionProperty(BunnyStreamApiHelper::class, 'client');
    $this->previousClient = $this->clientProperty->getValue();
    $this->clientProperty->setValue(null, null);

    $this->field = $this->createStub(BunnyStreamField::class);
    $this->field->handle = 'localizedVideo';
    $this->layout = $this->createStub(FieldLayout::class);
    $this->layout->method('getCustomFields')->willReturn([$this->field]);
    $this->layout->method('getFieldByHandle')->willReturnMap([['localizedVideo', $this->field]]);
    $this->attributes = new BunnyStreamFieldAttributes(['videoId' => $this->guid, 'metaData' => ['status' => 2]]);
    $this->asset = $this->createStub(Asset::class);
    $this->asset->id = 17;
    $this->asset->siteId = 2;
    $this->asset->kind = Asset::KIND_VIDEO;
    $this->asset->method('getFieldLayout')->willReturn($this->layout);
    $this->asset->method('getFieldValue')->willReturnCallback(fn(string $handle) => $handle === 'localizedVideo' ? $this->attributes : null);
    $this->asset->method('setFieldValue')->willReturnCallback(function (string $handle, array $value) {
        expect($handle)->toBe('localizedVideo');
        $this->attributes = new BunnyStreamFieldAttributes($value);
    });
    $this->asset->method('__get')->willReturnMap([['filename', 'example.mp4']]);
    $this->elements->method('getElementById')->willReturn($this->asset);
    $mutex = $this->createStub(Mutex::class);
    $mutex->method('acquire')->willReturn(true);
    Craft::$app->method('getMutex')->willReturn($mutex);
    $db = $this->createStub(Connection::class);
    $db->method('transaction')->willReturnCallback(fn(callable $run) => $run($db));
    $db->method('createCommand')->willReturn($this->createStub(Command::class));
    Craft::$app->method('getDb')->willReturn($db);
});

afterEach(function () {
    $this->clientProperty->setValue(null, $this->previousClient);
    Craft::$app = $this->previousApp;
});

it('rejects invalid webhook requests before queueing any work', function ($body, $token, $exception) {
    $request = $this->createMock(Request::class);
    $request->method('getIsPost')->willReturn(true);
    $request->method('getQueryParam')->willReturn($token);
    $request->expects($token === 'test-token' ? $this->once() : $this->never())->method('getRawBody')->willReturn($body);
    $controller = (new ReflectionClass(WebhookController::class))->newInstanceWithoutConstructor();
    $controller->request = $request;
    expect(fn() => $controller->actionIndex())->toThrow($exception)
        ->and($this->jobs)->toBeEmpty();
})->with([
    'bad credentials' => ['{}', 'wrong', ForbiddenHttpException::class],
    'invalid JSON' => ['{', 'test-token', BadRequestHttpException::class],
    'scalar payload' => ['null', 'test-token', BadRequestHttpException::class],
    'invalid GUID' => ['{"VideoGuid":"not-a-guid"}', 'test-token', BadRequestHttpException::class],
]);

it('queues an authenticated webhook and only looks up matching assets in the worker', function (bool $found) {
    $request = $this->createStub(Request::class);
    $request->method('getIsPost')->willReturn(true);
    $request->method('getQueryParam')->willReturn('test-token');
    $request->method('getRawBody')->willReturn(json_encode(['VideoGuid' => $this->guid, 'Status' => 4]));
    $controller = (new ReflectionClass(WebhookController::class))->newInstanceWithoutConstructor();
    $controller->request = $request;
    expect($controller->actionIndex())->toBeTrue()->and($this->jobs)->toHaveCount(1);
    [[$job, $delay]] = $this->jobs;
    expect($job)->toBeInstanceOf(RefreshBunnyStreamMetadataJob::class)->and($delay)->toBeNull();

    $fields = $this->createMock(Fields::class);
    $fields->expects($this->once())->method('getLayoutsByType')->with(Asset::class)->willReturn([$this->layout]);
    Craft::$app->method('getFields')->willReturn($fields);
    $query = $this->createMock(AssetQuery::class);
    $query->expects($this->once())->method('site')->with('*')->willReturnSelf();
    $query->expects($this->once())->method('status')->with(null)->willReturnSelf();
    $query->expects($this->once())->method('trashed')->with(false)->willReturnSelf();
    $query->expects($this->once())->method('__call')->with('localizedVideo', [['value' => $this->guid, 'caseInsensitive' => true]])->willReturnSelf();
    $query->method('all')->willReturn($found ? [$this->asset] : []);
    $this->elements->expects($this->once())->method('createElementQuery')->with(Asset::class)->willReturn($query);
    $this->elements->expects($found ? $this->once() : $this->never())->method('saveElement')->willReturn(true);
    $this->http->append(new Response(200, [], json_encode(['guid' => $this->guid, 'status' => 3])));
    $job->execute($this->queue);
    expect($this->attributes->metaData['status'])->toBe($found ? 3 : 2)
        ->and($this->http->count())->toBe($found ? 0 : 1)
        ->and($this->jobs)->toHaveCount(1);
})->with(['linked' => true, 'unknown' => false]);

it('refreshes direct and nested referring pages once per site on a terminal poll', function (VideoStatus $status) {
    $first = $this->createStub(Entry::class);
    $first->siteId = 1;
    $first->uri = 'watch';
    $second = $this->createStub(Entry::class);
    $second->siteId = 2;
    $second->uri = 'watch';
    $nested = $this->createStub(Entry::class);
    $nested->method('getOwner')->willReturn($second);

    $entries = $this->createMock(EntryQuery::class);
    $entries->expects($this->once())->method('relatedTo')->with(['targetElement' => $this->asset])->willReturnSelf();
    $entries->method('status')->willReturnSelf();
    $entries->expects($this->once())->method('siteId')->with('*')->willReturnSelf();
    $entries->method('all')->willReturn([$first, $nested, $nested]);
    $this->elements->expects($this->once())->method('createElementQuery')->with(Entry::class)->willReturn($entries);
    $this->elements->expects($this->once())->method('saveElement')->willReturn(true);
    $refresh = $this->createMock(RefreshCacheService::class);
    $refresh->expects($this->once())->method('refreshSiteUris')->with($this->callback(
        fn($uris) => array_map(fn($page) => [$page->siteId, $page->uri], $uris) === [[1, 'watch'], [2, 'watch']]
    ));
    $blitz = (new ReflectionClass(Blitz::class))->newInstanceWithoutConstructor();
    $blitz->set('refreshCache', $refresh);
    $plugins = $this->createStub(Plugins::class);
    $plugins->method('getPlugin')->willReturnMap([['blitz', $blitz]]);
    Craft::$app->method('getPlugins')->willReturn($plugins);
    $this->http->append(new Response(200, [], json_encode(['guid' => $this->guid, 'status' => $status->value])));
    $job = new RefreshBunnyStreamMetadataJob(['assetId' => 17, 'siteId' => 2, 'videoId' => $this->guid]);
    $job->execute($this->queue);
    $job->execute($this->queue);
    expect($this->attributes->metaData['status'])->toBe($status->value)->and($this->jobs)->toBeEmpty();
})->with([VideoStatus::Finished, VideoStatus::Error, VideoStatus::UploadFailed]);

it('continues polling long encodes hourly for a bounded extra day', function (int $attempt, ?int $nextDelay) {
    $this->http->append(new Response(200, [], json_encode(['guid' => $this->guid, 'status' => 2])));
    $this->elements->expects($this->once())->method('saveElement')->willReturn(true);
    (new RefreshBunnyStreamMetadataJob(['assetId' => 17, 'siteId' => 2, 'videoId' => $this->guid, 'attempt' => $attempt]))->execute($this->queue);
    if ($nextDelay === null) {
        expect($this->jobs)->toBeEmpty();
    } else {
        expect($this->jobs)->toHaveCount(1);
        [[$next, $delay]] = $this->jobs;
        expect([$next->assetId, $next->siteId, $next->videoId, $next->attempt, $delay])->toBe([17, 2, $this->guid, $attempt + 1, $nextDelay]);
    }
})->with([[0, 30], [18, 300], [19, 3600], [42, 3600], [43, null]]);

it('does not poll a replacement for an obsolete job', function () {
    $this->elements->expects($this->never())->method('saveElement');
    (new RefreshBunnyStreamMetadataJob(['assetId' => 17, 'videoId' => '9663daa2-8cc4-4915-9417-8e76b7f10c74']))->execute($this->queue);
    expect($this->http->getLastRequest())->toBeNull()->and($this->jobs)->toBeEmpty();
});

it('returns a failing console exit code on a Bunny error and reports successful syncs', function (bool $failed, bool $bootstrap) {
    if ($bootstrap) {
        $this->attributes->videoId = null;
        Craft::$app->env = 'production';
        $this->asset->method('getUrl')->willReturn('https://example.test/video.mp4');
    }
    $query = $this->createMock(AssetQuery::class);
    $query->expects($this->once())->method('kind')->with(Asset::KIND_VIDEO)->willReturnSelf();
    $query->method('each')->willReturn([$this->asset]);
    $this->elements->method('createElementQuery')->willReturn($query);
    $this->elements->expects($failed ? $this->never() : $this->once())->method('saveElement')->willReturn(true);
    $this->http->append(new Response($failed ? 503 : 200, [], json_encode(['guid' => $this->guid, 'status' => 3])));
    $controller = $this->getMockBuilder(SyncController::class)->disableOriginalConstructor()->onlyMethods(['stdout', 'stderr'])->getMock();
    $controller->bootstrap = $bootstrap;
    $controller->expects($failed ? $this->once() : $this->never())->method('stderr');
    $output = '';
    $controller->method('stdout')->willReturnCallback(function ($line) use (&$output) {
        $output .= $line;
    });
    expect($controller->actionMetadata())->toBe($failed ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK)
        ->and($this->http->getLastRequest()?->getMethod())->toBe($bootstrap ? 'POST' : 'GET')
        ->and($output)->toContain($failed ? 'Synced: 0, Created: 0, Skipped: 0, Failed: 1' : 'Synced: 1, Created: 0, Skipped: 0, Failed: 0');
})->with([
    'metadata error' => [true, false],
    'bootstrap error' => [true, true],
    'successful sync' => [false, false],
]);
