<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Throwable;

class OrderDeliveryToCourier extends Model {
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    public function setAffiliatorIdAttribute( $value ): void {
        $this->attributes['affiliator_id'] = ( (int) $value > 0 ) ? (int) $value : 0;
    }

    public function courierCredential() {
        return $this->belongsTo( CourierCredential::class, 'courier_id' );
    }

    protected function performInsert( Builder $query ) {
        $allowZero   = (int) ( $this->affiliator_id ?? 0 ) === 0;
        $connection  = $this->getConnection();

        if ( $allowZero ) {
            self::dropAffiliatorForeignKeyIfExists( $connection );
            $connection->unprepared( 'SET FOREIGN_KEY_CHECKS=0' );
        }

        try {
            return parent::performInsert( $query );
        } finally {
            if ( $allowZero ) {
                $connection->unprepared( 'SET FOREIGN_KEY_CHECKS=1' );
            }
        }
    }

    protected static function dropAffiliatorForeignKeyIfExists( $connection ): void {
        static $dropped = [];

        $name = $connection->getName() ?: 'default';
        if ( isset( $dropped[$name] ) ) {
            return;
        }

        try {
            $foreignKeys = collect( $connection->select( "SELECT CONSTRAINT_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'order_delivery_to_couriers'
                  AND COLUMN_NAME = 'affiliator_id'
                  AND REFERENCED_TABLE_NAME IS NOT NULL" ) )
                ->pluck( 'CONSTRAINT_NAME' );

            foreach ( $foreignKeys as $foreignKey ) {
                $connection->statement( "ALTER TABLE `order_delivery_to_couriers` DROP FOREIGN KEY `{$foreignKey}`" );
            }
        } catch ( Throwable $e ) {
            // Insert still proceeds with FOREIGN_KEY_CHECKS=0.
        }

        $dropped[$name] = true;
    }
}
