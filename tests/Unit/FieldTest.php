<?php

use craft\base\ElementInterface;
use craft\console\Application;
use craft\db\Command;
use craft\db\Connection;
use craft\db\mysql\Schema as MysqlSchema;
use craft\db\pgsql\Schema as PgsqlSchema;
use craft\elements\Asset;
use craft\errors\InvalidFieldException;
use craft\models\FieldLayout;
use Noo\CraftBunnyStream\behaviors\BunnyStreamAssetBehavior;
use Noo\CraftBunnyStream\BunnyStream;
use Noo\CraftBunnyStream\enums\VideoStatus;
use Noo\CraftBunnyStream\fields\BunnyStreamField;
use Noo\CraftBunnyStream\migrations\Install;
use Noo\CraftBunnyStream\migrations\m261119_000000_migrate_field_content;
use Noo\CraftBunnyStream\models\BunnyStreamFieldAttributes;
use Noo\CraftBunnyStream\previews\BunnyStreamAssetPreviewHandler;
use yii\base\Behavior;

beforeEach(function () {
    $this->field = new BunnyStreamField(['handle' => 'stream']);
    $this->guid = '0f424d24-5697-43f6-a03e-9d04cbe99559';
});

it('uses one link across sites even with older translated field settings', function () {
    $field = new BunnyStreamField([
        'translationMethod' => BunnyStreamField::TRANSLATION_METHOD_CUSTOM,
        'translationKeyFormat' => '{site.id}',
    ]);
    expect(BunnyStreamField::supportedTranslationMethods())->toBe([BunnyStreamField::TRANSLATION_METHOD_NONE])
        ->and($field->getIsTranslatable(null))->toBeFalse()
        ->and($field->translationKeyFormat)->toBeNull();
});

it('round trips legacy JSON field values through the current serialization', function () {
    $metadata = ['status' => 4, 'availableResolutions' => ['720p', '480p']];
    $legacy = json_encode([
        'bunnyStreamVideoId' => $this->guid,
        'bunnyStreamMetaData' => json_encode($metadata, JSON_THROW_ON_ERROR),
        'unrelated' => 'not a model attribute',
    ], JSON_THROW_ON_ERROR);

    $normalized = $this->field->normalizeValue($legacy, null);
    $serialized = $this->field->serializeValue($normalized, null);
    expect($normalized)->toBeInstanceOf(BunnyStreamFieldAttributes::class)
        ->and($normalized->metaData)->toBe($metadata)
        ->and($serialized)->toBe([
            'videoId' => $this->guid,
            'metaData' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'ownershipProof' => null,
            'generation' => null,
        ])
        ->and($this->field->normalizeValue($serialized, null)->metaData)->toBe($metadata)
        ->and($this->field->normalizeValue($this->guid, null)->videoId)->toBe($this->guid);
});

it('keeps current keys and the generation token on a cleared link', function () {
    $normalized = $this->field->normalizeValue([
        'videoId' => null,
        'metaData' => null,
        'generation' => $this->guid,
        'bunnyStreamVideoId' => $this->guid,
        'bunnyStreamMetaData' => ['status' => 4],
    ], null);

    $stored = $this->field->serializeValue($normalized, null);
    expect($stored['videoId'])->toBeNull()
        ->and($this->field->normalizeValue($stored, null)->metaData)->toBeNull()
        ->and($this->field->normalizeValue($stored, null)->generation)->toBe($this->guid);
});

it('rejects malformed identifiers and malformed metadata', function (mixed $id) {
    $value = $this->field->normalizeValue(['videoId' => $id, 'metaData' => 'not JSON'], null);
    expect($value->videoId)->toBeNull()->and($value->metaData)->toBeNull();
})->with([
    'wrong length' => ['abcd'],
    'array' => [['not a string']],
    'numeric' => [123],
    'trailing line break' => ["0f424d24-5697-43f6-a03e-9d04cbe99559\n"],
]);

it('does not bypass GUID validation on an already instantiated model', function () {
    $value = new BunnyStreamFieldAttributes();
    $value->videoId = 'not-a-guid';
    expect($this->field->serializeValue($value, null)['videoId'])->toBeNull()
        ->and($this->field->normalizeValue($value, null)->videoId)->toBeNull();
});

it('ignores a different asset’s well-formed GUID and metadata on Craft’s request write path', function () {
    $values = new class extends Behavior {
        public mixed $stream = null;
    };
    $asset = $this->getMockBuilder(Asset::class)
        ->disableOriginalConstructor()
        ->onlyMethods(['fieldByHandle', 'getBehavior', 'hasEagerLoadedElements'])
        ->getMock();
    $asset->method('fieldByHandle')->with('stream')->willReturn($this->field);
    $asset->method('getBehavior')->with('customFields')->willReturn($values);
    $asset->method('hasEagerLoadedElements')->willReturn(false);

    $other = [
        'videoId' => '44438cb1-a983-4f44-a888-12fda104f211',
        'metaData' => ['status' => 0],
        'ownershipProof' => 'other-proof',
        'generation' => 'other-generation',
    ];
    $original = ['videoId' => $this->guid, 'metaData' => ['status' => 4], 'ownershipProof' => 'saved-proof'];
    $asset->setFieldValue('stream', $original);
    $asset->setFieldValueFromRequest('stream', $other);
    $asset->setFieldValueFromRequest('stream', null);
    expect($asset->getFieldValue('stream')->videoId)->toBe($this->guid)
        ->and($asset->getFieldValue('stream')->metaData)->toBe($original['metaData'])
        ->and($this->field->serializeValue($asset->getFieldValue('stream'), $asset)['ownershipProof'])->toBe('saved-proof')
        ->and($asset->getFieldValue('stream')->generation)->toBeNull();

    $asset->setFieldValue('stream', null);
    $asset->setFieldValueFromRequest('stream', $other);
    expect($asset->getFieldValue('stream')->videoId)->toBeNull()
        ->and($asset->getFieldValue('stream')->metaData)->toBeNull()
        ->and($asset->getFieldValue('stream')->ownershipProof)->toBeNull()
        ->and($asset->getFieldValue('stream')->generation)->toBeNull();
});

it('does not accept request data without an attached field value', function () {
    $asset = $this->createStub(ElementInterface::class);
    $asset->method('getFieldValue')->willThrowException(new InvalidFieldException('stream'));
    $submitted = ['videoId' => $this->guid, 'metaData' => ['status' => 4]];
    foreach ([null, $asset] as $context) {
        $value = $this->field->normalizeValueFromRequest($submitted, $context);
        expect($value->videoId)->toBeNull()->and($value->metaData)->toBeNull();
    }
});

it('renders status through the Craft 5 preview method', function (?VideoStatus $status, string $label) {
    $value = $this->field->normalizeValue($status ? [
        'videoId' => $this->guid,
        'metaData' => ['status' => $status->value],
    ] : null, null);
    $html = $this->field->getPreviewHtml($value, $this->createStub(ElementInterface::class));
    expect($html)->toContain('role="img"', 'aria-label="' . $label . '"');
})->with([
    [null, 'Video does not have a Bunny Stream asset'],
    [VideoStatus::Processing, 'Bunny Stream video is being processed. Stay tuned!'],
    [VideoStatus::Finished, 'Bunny Stream video is ready to play!'],
]);

it('renders no player or playback URLs for an asset without a stream', function () {
    $asset = $this->createStub(Asset::class);
    $asset->kind = Asset::KIND_IMAGE;
    $behavior = new BunnyStreamAssetBehavior();
    expect($behavior->getBunnyStreamEmbed())->toBe('');
    $behavior->attach($asset);

    expect($behavior->getBunnyStreamHlsUrl())->toBeNull()
        ->and($behavior->getBunnyStreamDirectUrl())->toBeNull()
        ->and($behavior->getBunnyStreamEmbedUrl())->toBeNull()
        ->and($behavior->getBunnyStreamEmbed())->toBe('')
        ->and((new BunnyStreamAssetPreviewHandler($asset))->getPreviewHtml())->toBe('');
});

it('encodes the player URL as a single iframe attribute', function () {
    $layout = $this->createStub(FieldLayout::class);
    $layout->method('getCustomFields')->willReturn([$this->field]);
    $asset = $this->createStub(Asset::class);
    $asset->kind = Asset::KIND_VIDEO;
    $asset->method('getFieldLayout')->willReturn($layout);
    $asset->method('getFieldValue')->willReturn($this->field->normalizeValue($this->guid, null));

    $previous = Craft::$app;
    Craft::$app = $this->createStub(Application::class);
    try {
        $plugin = (new ReflectionClass(BunnyStream::class))->newInstanceWithoutConstructor();
        BunnyStream::setInstance($plugin);
        $plugin->setSettings(['bunnyStreamLibraryId' => '23']);
        $html = (new BunnyStreamAssetPreviewHandler($asset))->getPreviewHtml();
        expect($html)->toContain('src="https://player.mediadelivery.net/embed/23/' . $this->guid . '?autoplay=false&amp;preload=metadata&amp;responsive=true"');
    } finally {
        Craft::$app = $previous;
    }
});

it('rewrites only legacy keys in Bunny layout instances and makes the GUID queryable', function (string $migrationClass) {
    $db = new Connection([
        'dsn' => getenv('TEST_DB_DSN'),
        'username' => getenv('TEST_DB_USER') ?: null,
        'password' => getenv('TEST_DB_PASSWORD') ?: null,
        'tablePrefix' => 'bs_' . bin2hex(random_bytes(4)) . '_',
        'commandMap' => ['mysql' => Command::class, 'pgsql' => Command::class],
        'schemaMap' => ['mysql' => MysqlSchema::class, 'pgsql' => PgsqlSchema::class],
        'schemaCache' => null,
        'enableLogging' => false,
        'enableProfiling' => false,
    ]);
    $previous = Craft::$app;
    $app = $this->createStub(Application::class);
    $app->method('getIsInstalled')->willReturn(true);
    $app->method('getDb')->willReturn($db);
    Craft::$app = $app;
    try {
        $jsonType = $db->getIsMysql() ? 'JSON' : 'JSONB';
        $db->createCommand('CREATE TEMPORARY TABLE {{%fields}} (uid VARCHAR(36), type VARCHAR(255))')->execute();
        $db->createCommand("CREATE TEMPORARY TABLE {{%fieldlayouts}} (type VARCHAR(255), config $jsonType)")->execute();
        $db->createCommand("CREATE TEMPORARY TABLE {{%elements_sites}} (id INT, content $jsonType)")->execute();
        $db->createCommand('CREATE TEMPORARY TABLE {{%plugins}} (handle VARCHAR(255))')->execute();

        $fieldUid = '10d4abce-a66b-4d7a-af51-cdce89e6ece0';
        $slot = 'c662538a-7e83-49e3-b868-6bc00eb42e31';
        $otherSlot = '3b64c9ab-15e0-4a18-b519-50cbaa574436';
        // Install must also recognize the old field namespace.
        $type = $migrationClass === Install::class ? 'jorisnoo\bunnystream\fields\BunnyStreamField' : BunnyStreamField::class;
        $db->createCommand('INSERT INTO {{%fields}} (uid, type) VALUES (:uid, :type)', [
            ':uid' => $fieldUid, ':type' => $type,
        ])->execute();
        $db->createCommand('INSERT INTO {{%fieldlayouts}} (type, config) VALUES (:type, :config)', [
            ':type' => Asset::class,
            ':config' => json_encode(['tabs' => [['elements' => [
                ['uid' => $slot, 'fieldUid' => $fieldUid],
                ['uid' => $otherSlot, 'fieldUid' => 'c73f48d1-0387-46fe-8960-12f23c392552'],
            ]]]], JSON_THROW_ON_ERROR),
        ])->execute();
        $legacy = ['bunnyStreamVideoId' => $this->guid, 'bunnyStreamMetaData' => '{"status":4}'];
        foreach ([
            1 => [$slot => $legacy, $otherSlot => $legacy],
            2 => [$slot => ['videoId' => null, 'metaData' => ['status' => 0]] + $legacy],
            3 => [$otherSlot => $legacy],
        ] as $id => $content) {
            $db->createCommand('INSERT INTO {{%elements_sites}} (id, content) VALUES (:id, :content)', [
                ':id' => $id, ':content' => json_encode($content, JSON_THROW_ON_ERROR),
            ])->execute();
        }

        $migration = new $migrationClass(['db' => $db, 'compact' => true]);
        expect($migration->safeUp())->toBeTrue();
        // Re-running after a partial deploy must not overwrite the new keys.
        expect((new m261119_000000_migrate_field_content(['db' => $db, 'compact' => true]))->safeUp())->toBeTrue();
        $rows = array_map(static fn(string $value) => json_decode($value, true, flags: JSON_THROW_ON_ERROR),
            $db->createCommand('SELECT content FROM {{%elements_sites}} ORDER BY id')->queryColumn());
        expect($rows)->toEqual([
            [$slot => ['videoId' => $this->guid, 'metaData' => '{"status":4}'], $otherSlot => $legacy],
            [$slot => ['videoId' => null, 'metaData' => ['status' => 0]]],
            [$otherSlot => $legacy],
        ]);
        $valueSql = $db->getQueryBuilder()->jsonExtract('content', [$slot, 'videoId']);
        expect((int)$db->createCommand("SELECT id FROM {{%elements_sites}} WHERE $valueSql = :guid", [
            ':guid' => $this->guid,
        ])->queryScalar())->toBe(1);
    } finally {
        $db->close();
        Craft::$app = $previous;
    }
})->with([
    'existing installation' => [m261119_000000_migrate_field_content::class],
    'installation over legacy field data' => [Install::class],
])->skip(!getenv('TEST_DB_DSN'), 'Set TEST_DB_DSN to exercise the Craft content migration.');
