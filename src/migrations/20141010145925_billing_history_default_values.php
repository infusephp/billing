<?php

use Phinx\Migration\AbstractMigration;

class BillingHistoryDefaultValues extends AbstractMigration
{
    public function change()
    {
        $table = $this->table('BillingHistories');
        $table->changeColumn('error', 'string', ['default' => null, 'null' => true])
              ->save();
    }
}
