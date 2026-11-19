<?php

namespace Noo\CraftBunnyStream\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        // Installation marks existing migrations as applied, even when content
        // from the old plugin is still present.
        (new m260331_000000_migrate_field_type(['db' => $this->db]))->safeUp();
        (new m261119_000000_migrate_field_content(['db' => $this->db]))->safeUp();

        // Clean up old plugin record if it exists
        $this->delete('{{%plugins}}', ['handle' => '_bunny-stream']);

        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }
}
