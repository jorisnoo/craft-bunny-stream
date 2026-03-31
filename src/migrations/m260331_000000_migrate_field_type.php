<?php

namespace Noo\CraftBunnyStream\migrations;

use craft\db\Migration;

class m260331_000000_migrate_field_type extends Migration
{
    public function safeUp(): bool
    {
        $this->update('{{%fields}}', [
            'type' => 'Noo\CraftBunnyStream\fields\BunnyStreamField',
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
