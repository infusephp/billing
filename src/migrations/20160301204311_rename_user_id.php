<?php

use Phinx\Migration\AbstractMigration;

class RenameUserId extends AbstractMigration
{
    public function change()
    {
        $this->table('BillingHistories')
             ->renameColumn('uid', 'user_id')
             ->update();
    }
}
