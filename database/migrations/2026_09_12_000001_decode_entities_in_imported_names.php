<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Imported names were stripped of their markup but kept the entities it
     * left behind, so a category read as "Schmuck &amp; Accessoires". The
     * import decodes them now; this repairs what was already stored rather
     * than making everyone re-sync to read their own catalog.
     */
    public function up(): void
    {
        foreach (['categories', 'brands', 'products'] as $table) {
            $this->decodeNames($table);
        }
    }

    /**
     * Reverse the migrations.
     *
     * Re-encoding would be a change for the worse, and the decoded name is
     * what the store meant either way, so this is deliberately one way.
     */
    public function down(): void
    {
        //
    }

    /**
     * Decode the entities in one table's names.
     */
    private function decodeNames(string $table): void
    {
        DB::table($table)
            ->select('id', 'name')
            ->where('name', 'like', '%&%;%')
            ->orderBy('id')
            ->each(function (object $row) use ($table) {
                $decoded = html_entity_decode((string) $row->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                if ($decoded === $row->name) {
                    return;
                }

                DB::table($table)->where('id', $row->id)->update(['name' => $decoded]);
            });
    }
};
