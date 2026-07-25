<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * iCalUId auf der Serie: geteilte Identität einer wiederkehrenden Termin-Serie aus
 * MS365. Eine aus dem Eingang promotete Serie wird darüber find-or-create geführt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('meetings_series')) {
            return;
        }

        Schema::table('meetings_series', function (Blueprint $table) {
            if (! Schema::hasColumn('meetings_series', 'ical_uid')) {
                $table->string('ical_uid')->nullable()->after('uuid');
                $table->index('ical_uid', 'meetings_series_ical_uid_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('meetings_series')) {
            return;
        }

        Schema::table('meetings_series', function (Blueprint $table) {
            if (Schema::hasColumn('meetings_series', 'ical_uid')) {
                $table->dropIndex('meetings_series_ical_uid_idx');
                $table->dropColumn('ical_uid');
            }
        });
    }
};
