<?php

use Phinx\Migration\AbstractMigration;

class BillingHistoryDefaultValues extends AbstractMigration
{
    /**
     * Change Method.
     */
    public function change()
    {
        $table = $this->table('BillingHistories');
        $table->changeColumn('error', 'string', ['default' => null, 'null' => true])
              ->save();
    }

    /**
     * Migrate Up.
     */
    public function up()
    {

    }

    /**
     * Migrate Down.
     */
    public function down()
    {

    }
}
