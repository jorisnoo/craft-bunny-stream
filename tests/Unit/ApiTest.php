<?php

use craft\config\GeneralConfig;
use craft\console\Application;
use craft\elements\Asset;
use craft\services\Config;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Noo\CraftBunnyStream\BunnyStream;
use Noo\CraftBunnyStream\exceptions\BunnyException;
use Noo\CraftBunnyStream\helpers\BunnyStreamApiHelper;

it('uses bounded transfers and compensates a partial upload without hiding the failure', function (bool $fails) {
    $guid = '410d9f20-3668-44e2-83dc-1218fa152700';
    $metadata = ['guid' => $guid, 'status' => 1];
    $responses = new MockHandler([
        new Response(200, [], json_encode(['guid' => $guid], JSON_THROW_ON_ERROR)),
        new Response($fails ? 503 : 200, [], '{}'),
        new Response(200, [], json_encode($fails ? ['success' => true] : $metadata, JSON_THROW_ON_ERROR)),
    ]);
    $history = [];
    $stack = HandlerStack::create($responses);
    $stack->push(Middleware::history($history));
    $config = $this->createStub(Config::class);
    $config->method('getConfigFromFile')->willReturn(['handler' => $stack]);
    $config->method('getGeneral')->willReturn(new GeneralConfig());
    $app = $this->createStub(Application::class);
    $app->method('getConfig')->willReturn($config);
    $app->method('getVersion')->willReturn('5.0.0');
    $app->env = 'dev';
    $previous = Craft::$app;
    Craft::$app = $app;
    try {
        $plugin = (new ReflectionClass(BunnyStream::class))->newInstanceWithoutConstructor();
        BunnyStream::setInstance($plugin);
        $plugin->setSettings(['bunnyStreamAccessKey' => 'test-only', 'bunnyStreamLibraryId' => '23']);
        $asset = $this->createStub(Asset::class);
        $asset->method('__get')->willReturnMap([['filename', 'example.mp4']]);
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, 'fixture');
        rewind($stream);
        $asset->method('getStream')->willReturn($stream);
        if ($fails) {
            expect(fn() => BunnyStreamApiHelper::createVideo($asset))->toThrow(BunnyException::class, 'HTTP 503');
        } else {
            expect(BunnyStreamApiHelper::createVideo($asset))->toBe($metadata);
        }
        expect(array_map(fn($item) => $item['request']->getMethod(), $history))
            ->toBe(['POST', 'PUT', $fails ? 'DELETE' : 'GET'])
            ->and($history[1]['request']->getUri()->getPath())->toBe("/library/23/videos/$guid")
            ->and($history[2]['request']->getUri()->getPath())->toBe("/library/23/videos/$guid")
            ->and(is_resource($stream))->toBeFalse();
        foreach ($history as $item) {
            expect($item['options']['timeout'])->toBeGreaterThan(0)
                ->and($item['options']['connect_timeout'])->toBeGreaterThan(0);
        }
        expect($history[1]['options']['timeout'])->toBeGreaterThan($history[0]['options']['timeout']);
    } finally {
        (new ReflectionProperty(BunnyStreamApiHelper::class, 'client'))->setValue(null, null);
        (new ReflectionProperty(BunnyStreamApiHelper::class, 'uploadClient'))->setValue(null, null);
        Craft::$app = $previous;
    }
})->with(['completed PUT' => [false], 'failed PUT' => [true]]);
