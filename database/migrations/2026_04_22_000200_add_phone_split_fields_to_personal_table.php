<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal', function (Blueprint $table): void {
            if (!Schema::hasColumn('personal', 'telefono_1')) {
                $table->string('telefono_1', 30)->nullable()->after('telefono');
            }

            if (!Schema::hasColumn('personal', 'telefono_2')) {
                $table->string('telefono_2', 30)->nullable()->after('telefono_1');
            }
        });

        if (Schema::hasColumn('personal', 'telefono') && Schema::hasColumn('personal', 'telefono_1')) {
            DB::table('personal')
                ->whereNotNull('telefono')
                ->whereNull('telefono_1')
                ->update(['telefono_1' => DB::raw('telefono')]);
        }
    }

    public function down(): void
    {
        Schema::table('personal', function (Blueprint $table): void {
            if (Schema::hasColumn('personal', 'telefono_2')) {
                $table->dropColumn('telefono_2');
            }

            if (Schema::hasColumn('personal', 'telefono_1')) {
                $table->dropColumn('telefono_1');
            }
        });
    }
};
