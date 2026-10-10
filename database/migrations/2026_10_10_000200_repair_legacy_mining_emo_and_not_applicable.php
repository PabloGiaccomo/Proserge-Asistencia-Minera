<?php

use App\Modules\Personal\Services\LegacyMiningDeployRepairService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(LegacyMiningDeployRepairService::class)->run();
    }

    public function down(): void
    {
        // Data repair is irreversible by rollback: preserve medical and audit history.
    }
};
