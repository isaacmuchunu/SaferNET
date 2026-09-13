<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW schools AS
            SELECT * FROM institutions;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS schools;');
    }
};
