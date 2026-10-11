<?php

namespace Noo\CraftBunnyStream\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        // Migrate fields from the old plugin namespace. Installing marks all
        // existing migrations as applied, so run that migration explicitly.
        (new m260331_000000_migrate_field_type(['db' => $this->db]))->safeUp();

        // Clean up old plugin record if it exists
        $this->delete('{{%plugins}}', ['handle' => '_bunny-stream']);

        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }
}
