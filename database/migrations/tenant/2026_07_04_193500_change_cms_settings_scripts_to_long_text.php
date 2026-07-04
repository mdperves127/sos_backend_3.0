<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $columns = [
        'scripts_google_analytics',
        'scripts_google_adsense',
        'scripts_google_recaptcha',
        'scripts_facebook_pixel',
        'scripts_facebook_messenger',
        'scripts_whatsapp_chat',
        'scripts_google_tag_manager',
    ];

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if ( ! Schema::hasTable( 'cms_settings' ) ) {
            return;
        }

        foreach ( $this->columns as $column ) {
            if ( Schema::hasColumn( 'cms_settings', $column ) ) {
                DB::statement( "ALTER TABLE `cms_settings` MODIFY `{$column}` LONGTEXT NULL" );
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if ( ! Schema::hasTable( 'cms_settings' ) ) {
            return;
        }

        foreach ( $this->columns as $column ) {
            if ( Schema::hasColumn( 'cms_settings', $column ) ) {
                DB::statement( "ALTER TABLE `cms_settings` MODIFY `{$column}` VARCHAR(255) NULL" );
            }
        }
    }
};
