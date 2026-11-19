<?php

namespace Noo\CraftBunnyStream\migrations;

use craft\db\Migration;
use craft\db\Table;
use Noo\CraftBunnyStream\fields\BunnyStreamField;

class m260331_000000_migrate_field_type extends Migration
{
    public function safeUp(): bool
    {
        // Migrate field type from old namespace
        $this->update(Table::FIELDS, [
            'type' => BunnyStreamField::class,
        ], [
            'type' => 'jorisnoo\bunnystream\fields\BunnyStreamField',
        ]);

        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }
}
