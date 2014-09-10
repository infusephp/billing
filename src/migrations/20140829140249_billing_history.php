<?php

use Phinx\Migration\AbstractMigration;

class BillingHistory extends AbstractMigration
{
    /**
     * Change Method.
     */
    public function change()
    {
        if( !$this->hasTable( 'BillingHistories' ) )
        {
            $table = $this->table( 'BillingHistories' );
            $table->addColumn( 'uid', 'integer' )
                  ->addColumn( 'payment_time', 'integer' )
                  ->addColumn( 'amount', 'decimal', [ 'length' => '14,2' ] )
                  ->addColumn( 'stripe_customer', 'string', [ 'length' => 50 ] )
                  ->addColumn( 'stripe_transaction', 'string', [ 'length' => 50 ] )
                  ->addColumn( 'success', 'boolean' )
                  ->addColumn( 'error', 'string' )
                  ->addColumn( 'description', 'string' )
                  ->create();
        }
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