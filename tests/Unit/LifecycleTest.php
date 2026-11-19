<?php

use craft\base\Element;
use craft\config\GeneralConfig;
use craft\console\Application;
use craft\db\Command;
use craft\db\Connection;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\events\ModelEvent;
use craft\events\ReplaceAssetEvent;
use craft\models\FieldLayout;
use craft\queue\Queue;
use craft\services\Assets;
use craft\services\Config;
use craft\services\Elements;
use craft\services\Fields;
use Noo\CraftBunnyStream\BunnyStream;
use Noo\CraftBunnyStream\fields\BunnyStreamField;
use Noo\CraftBunnyStream\helpers\BunnyStreamApiHelper;
use Noo\CraftBunnyStream\helpers\BunnyStreamHelper;
use Noo\CraftBunnyStream\jobs\CreateBunnyStreamVideoJob;
use Noo\CraftBunnyStream\jobs\DeleteBunnyStreamVideoJob;
use Noo\CraftBunnyStream\jobs\RefreshBunnyStreamMetadataJob;
use ToshY\BunnyNet\BunnyHttpClient;
use ToshY\BunnyNet\Model\Api\Stream\ManageVideos\DeleteVideo;
use ToshY\BunnyNet\Model\Client\BunnyHttpClientResponseInterface;
use yii\base\Event;
use yii\db\Transaction;
use yii\mutex\Mutex;

beforeEach(function () {
    $this->oldApp = Craft::$app;
    $this->oldHandlers = (new ReflectionProperty(Event::class, '_events'))->getValue();
    $this->app = $this->createStub(Application::class);
    Craft::$app = $this->app;
    $general = new GeneralConfig();
    $general->securityKey = 'local-test-key';
    $config = $this->createStub(Config::class);
    $config->method('getGeneral')->willReturn($general);
    $this->app->method('getConfig')->willReturn($config);

    $plugin = (new ReflectionClass(BunnyStream::class))->newInstanceWithoutConstructor();
    BunnyStream::setInstance($plugin);
    $plugin->setSettings(['bunnyStreamLibraryId' => '41']);
    (new ReflectionMethod(BunnyStream::class, 'attachEventHandlers'))->invoke($plugin);

    $this->active = true;
    $transaction = $this->createStub(Transaction::class);
    $this->db = $this->getMockBuilder(Connection::class)->onlyMethods(['getTransaction', 'transaction', 'createCommand'])->disableOriginalConstructor()->getMock();
    $this->db->method('getTransaction')->willReturnCallback(fn() => $this->active ? $transaction : null);
    $this->db->method('transaction')->willReturnCallback(fn($save) => $save($this->db));
    $this->db->method('createCommand')->willReturn($this->createStub(Command::class));
    $this->app->method('getDb')->willReturn($this->db);
    $this->jobs = [];
    $queue = $this->getMockBuilder(Queue::class)->onlyMethods(['push'])->disableOriginalConstructor()->getMock();
    $queue->method('push')->willReturnCallback(function ($job) {
        $this->jobs[] = $job;
        return (string)count($this->jobs);
    });
    $this->app->method('getQueue')->willReturn($queue);

    $this->field = new BunnyStreamField(['handle' => 'volumeStream']);
    $this->layout = $this->createStub(FieldLayout::class);
    $this->layout->method('getCustomFields')->willReturn([$this->field]);
    $this->layout->method('getFieldByHandle')->willReturn($this->field);
    $fields = $this->createStub(Fields::class);
    $fields->method('getLayoutsByType')->willReturn([$this->layout]);
    $this->app->method('getFields')->willReturn($fields);
    $this->rows = [];
    $query = $this->createStub(AssetQuery::class);
    foreach (['id', 'site', 'status', 'trashed', 'drafts', 'revisions', 'provisionalDrafts'] as $method) {
        $query->method($method)->willReturnSelf();
    }
    $query->method('all')->willReturnCallback(fn() => $this->rows);
    $this->elements = $this->createMock(Elements::class);
    $this->elements->method('createElementQuery')->willReturn($query);
    $this->app->method('getElements')->willReturn($this->elements);
    $this->guid = '3b9c54d0-1e19-46c1-b84d-64ca55b8438f';

    $this->asset = function (?array $attributes = null, bool $withField = true) {
        $asset = $this->getMockBuilder(Asset::class)->disableOriginalConstructor()
            ->onlyMethods(['getFieldLayout', 'getFieldValue', 'setFieldValue', 'setFocalPoint', 'getUrl'])->getMock();
        $asset->id = 27;
        $asset->uid = 'e5df6e35-c769-4398-8236-96fb47ac3fad';
        $asset->siteId = 3;
        $asset->kind = Asset::KIND_VIDEO;
        $asset->method('getFieldLayout')->willReturn($withField ? $this->layout : null);
        $asset->method('getUrl')->willReturn('https://assets.example.test/video.mp4');
        $value = $this->field->normalizeValue($attributes, $asset);
        $asset->method('getFieldValue')->willReturnCallback(static function () use (&$value) { return $value; });
        $asset->method('setFieldValue')->willReturnCallback(function ($handle, $attributes) use ($asset, &$value) {
            $value = $this->field->normalizeValue($attributes, $asset);
        });
        return $asset;
    };
    $this->owned = function () {
        $asset = ($this->asset)();
        $receipt = DeleteBunnyStreamVideoJob::forCreatedVideo($asset, $this->guid);
        $asset->setFieldValue('volumeStream', ['videoId' => $this->guid, 'ownershipProof' => $receipt->ownershipProof]);
        return $asset;
    };
    $this->finish = function (bool $commit) {
        $this->active = false;
        $this->db->trigger($commit ? Connection::EVENT_COMMIT_TRANSACTION : Connection::EVENT_ROLLBACK_TRANSACTION);
    };
});

afterEach(function () {
    (new ReflectionProperty(Event::class, '_events'))->setValue(null, $this->oldHandlers);
    (new ReflectionProperty(BunnyStreamApiHelper::class, 'client'))->setValue(null, null);
    Craft::$app = $this->oldApp;
});

it('queues release of the persisted old link and creation only for a successful replacement transaction', function (bool $commit) {
    $this->elements->expects($this->never())->method('saveElement');
    $this->rows = [($this->owned)()];
    $asset = ($this->asset)(['videoId' => 'babf2321-5d62-41ea-9bac-6fcd7cede149']);
    $asset->expects($this->once())->method('setFocalPoint')->with(null);
    Event::trigger(Assets::class, Assets::EVENT_BEFORE_REPLACE_ASSET, new ReplaceAssetEvent(['asset' => $asset]));
    expect(BunnyStreamHelper::getBunnyStreamVideoId($asset))->toBeNull()
        ->and(BunnyStreamHelper::getBunnyStreamFieldAttributes($asset)->generation)->not->toBeEmpty()
        ->and($asset->resaving)->toBeFalse();
    $asset->setScenario(Asset::SCENARIO_REPLACE);
    Event::trigger($asset, Element::EVENT_AFTER_PROPAGATE, new ModelEvent());
    expect($this->jobs)->toBeEmpty();
    ($this->finish)($commit);
    if ($commit) {
        expect($this->jobs)->toHaveCount(2)
            ->and($this->jobs[0])->toBeInstanceOf(DeleteBunnyStreamVideoJob::class)
            ->and($this->jobs[0]->videoId)->toBe($this->guid)
            ->and($this->jobs[1])->toBeInstanceOf(CreateBunnyStreamVideoJob::class)
            ->and($this->jobs[1]->siteId)->toBe(3);
    } else {
        $this->db->trigger(Connection::EVENT_COMMIT_TRANSACTION);
        expect($this->jobs)->toBeEmpty();
    }
})->with(['commit' => true, 'rollback followed by unrelated commit' => false]);

it('never saves a deleted asset and preserves restorable streams', function (bool $hard, bool $keepFile, bool $shouldQueue) {
    $this->elements->expects($this->never())->method('saveElement');
    $this->rows = [($this->owned)()];
    $asset = ($this->asset)(); // The event sender need not even have the original field value.
    $asset->hardDelete = $hard;
    $asset->keepFileOnDelete = $keepFile;
    Event::trigger($asset, Element::EVENT_BEFORE_DELETE);
    $this->rows = [];
    Event::trigger($asset, Element::EVENT_AFTER_DELETE);
    expect($this->jobs)->toBeEmpty();
    ($this->finish)(true);
    expect($this->jobs)->toHaveCount($shouldQueue ? 1 : 0);
    if ($shouldQueue) {
        expect($this->jobs[0]->videoId)->toBe($this->guid);
    }
})->with([
    'hard delete' => [true, false, true],
    'soft delete without recoverable file' => [false, false, true],
    'soft delete with recoverable file' => [false, true, false],
]);

it('restarts creation after restoring an asset whose upload was skipped while trashed', function () {
    $asset = ($this->asset)();
    Event::trigger($asset, Element::EVENT_AFTER_RESTORE);
    ($this->finish)(true);
    expect($this->jobs)->toHaveCount(1)->and($this->jobs[0]->assetId)->toBe($asset->id);
});

it('skips both queueing and CLI bootstrap on volumes without the field', function () {
    $asset = ($this->asset)(null, false);
    Event::trigger($asset, Element::EVENT_AFTER_PROPAGATE, new ModelEvent());
    expect(BunnyStreamHelper::updateOrCreateBunnyStreamAsset($asset))->toBeFalse()
        ->and(BunnyStreamHelper::getThumbnailUrl($asset))->toBeNull();
    ($this->finish)(true);
    expect($this->jobs)->toBeEmpty();
});

it('rechecks the asset inside the shared creator mutex before either CLI or queue creates a video', function (bool $queued) {
    $stale = ($this->asset)();
    $mutex = $this->createMock(Mutex::class);
    $mutex->expects($this->once())->method('acquire')->with('bunny-stream:asset:27', 5)->willReturn(true);
    $mutex->expects($this->once())->method('release')->with('bunny-stream:asset:27');
    $this->app->method('getMutex')->willReturn($mutex);
    $current = ($this->owned)();
    if ($queued) {
        $this->elements->expects($this->exactly(2))->method('getElementById')->willReturnOnConsecutiveCalls($stale, $current);
        (new CreateBunnyStreamVideoJob(['assetId' => 27, 'siteId' => 3]))->execute(null);
    } else {
        $this->elements->expects($this->once())->method('getElementById')->willReturn($current);
        expect(BunnyStreamHelper::updateOrCreateBunnyStreamAsset($stale))->toBeFalse();
    }
    expect($this->jobs)->toBeEmpty();
})->with(['queue' => true, 'CLI' => false]);

it('restores save flags even when Craft fails to persist stream data', function () {
    $asset = ($this->asset)();
    $asset->setScenario(Asset::SCENARIO_REPLACE);
    $this->elements->method('saveElement')->willThrowException(new RuntimeException('save failed'));
    expect(fn() => BunnyStreamHelper::saveBunnyStreamAttributesToAsset($asset, null))->toThrow(RuntimeException::class)
        ->and($asset->getScenario())->toBe(Asset::SCENARIO_REPLACE)
        ->and($asset->resaving)->toBeFalse();
});

it('deletes only an attested unreferenced GUID in its original library', function (string $case) {
    $owner = ($this->owned)();
    $candidate = DeleteBunnyStreamVideoJob::forPersistedLink($owner);
    $existing = ($this->asset)(['videoId' => $this->guid]);
    expect(DeleteBunnyStreamVideoJob::forPersistedLink($existing))->toBeNull();
    switch ($case) {
        case 'survivor':
            $this->rows = [$existing];
            break;
        case 'restorable survivor':
            $existing->dateDeleted = new DateTime();
            $existing->keptFile = true;
            $this->rows = [$existing];
            break;
        case 'other asset':
            $candidate->assetUid = 'a83052c8-91d6-48c0-a58c-2314d2bd9ffe';
            break;
        case 'other video':
            $candidate->videoId = '910a2796-a659-4bd4-8e56-beddab410c63';
            break;
        case 'other library':
            BunnyStream::getInstance()->getSettings()->bunnyStreamLibraryId = '42';
            break;
    }
    $http = $this->createMock(BunnyHttpClient::class);
    if ($case === 'orphan') {
        $http->expects($this->once())->method('request')->with($this->callback(
            fn($request) => $request instanceof DeleteVideo && $request->videoId === $this->guid && $request->libraryId === 41
        ))->willReturn($this->createStub(BunnyHttpClientResponseInterface::class));
    } else {
        $http->expects($this->never())->method('request');
    }
    (new ReflectionProperty(BunnyStreamApiHelper::class, 'client'))->setValue(null, $http);
    $candidate->execute(null);
})->with(['orphan', 'survivor', 'restorable survivor', 'other asset', 'other video', 'other library']);

it('publishes the created GUID only if the same asset generation still exists after Bunny responds', function (string $outcome) {
    $this->active = false;
    $this->app->env = 'production';
    $mutex = $this->createMock(Mutex::class);
    $mutex->expects($this->once())->method('acquire')->willReturn(true);
    $mutex->expects($this->once())->method('release');
    $this->app->method('getMutex')->willReturn($mutex);
    $source = ($this->asset)(['generation' => 'before']);
    $latest = match ($outcome) {
        'deleted' => null,
        'replaced' => ($this->asset)(['generation' => 'after']),
        default => ($this->asset)(['generation' => 'before']),
    };
    $this->elements->method('getElementById')->willReturnOnConsecutiveCalls($source, $latest);
    $shouldSave = in_array($outcome, ['success', 'save fails'], true);
    $this->elements->expects($shouldSave ? $this->once() : $this->never())->method('saveElement')->willReturn($outcome === 'success');

    $fetched = $this->createStub(BunnyHttpClientResponseInterface::class);
    $fetched->method('getContents')->willReturn(['statusCode' => 200, 'id' => $this->guid]);
    $metadata = $this->createStub(BunnyHttpClientResponseInterface::class);
    $metadata->method('getContents')->willReturn(['guid' => $this->guid, 'status' => 1]);
    $http = $this->createMock(BunnyHttpClient::class);
    $http->expects($this->exactly(2))->method('request')->willReturnOnConsecutiveCalls($fetched, $metadata);
    (new ReflectionProperty(BunnyStreamApiHelper::class, 'client'))->setValue(null, $http);

    if ($outcome === 'save fails') {
        expect(fn() => BunnyStreamHelper::updateOrCreateBunnyStreamAsset($source))->toThrow(\Noo\CraftBunnyStream\exceptions\BunnyException::class);
    } else {
        expect(BunnyStreamHelper::updateOrCreateBunnyStreamAsset($source))->toBe($outcome === 'success');
    }
    expect($this->jobs)->toHaveCount(1);
    if ($outcome === 'success') {
        expect(DeleteBunnyStreamVideoJob::forPersistedLink($latest)?->videoId)->toBe($this->guid)
            ->and($this->jobs[0])->toBeInstanceOf(RefreshBunnyStreamMetadataJob::class)
            ->and($this->jobs[0]->videoId)->toBe($this->guid);
    } else {
        expect($this->jobs[0])->toBeInstanceOf(DeleteBunnyStreamVideoJob::class)
            ->and($this->jobs[0]->videoId)->toBe($this->guid);
    }
})->with(['success', 'deleted', 'replaced', 'save fails']);

it('bounds worker retries and reserves longer than a binary upload', function () {
    $create = new CreateBunnyStreamVideoJob();
    expect($create->getTtr())->toBeGreaterThan(3600);
    foreach ([$create, new DeleteBunnyStreamVideoJob()] as $job) {
        expect($job->canRetry(1, new RuntimeException()))->toBeTrue()
            ->and($job->canRetry(3, new RuntimeException()))->toBeFalse()
            ->and($job->canRetry(1, new \Noo\CraftBunnyStream\exceptions\MissingConfigurationException()))->toBeFalse();
    }
});
