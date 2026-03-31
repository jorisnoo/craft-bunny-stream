<?php

namespace Noo\CraftBunnyStream\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        // Migrate field type from old namespace
        $this->update('{{%fields}}', [
            'type' => 'Noo\CraftBunnyStream\fields\BunnyStreamField',
        ], [
            'type' => 'jorisnoo\bunnystream\fields\BunnyStreamField',
        ]);

        // Clean up old plugin record if it exists
        $this->delete('{{%plugins}}', ['handle' => '_bunny-stream']);

        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }
}
