<?php

use App\Models\Product;
use Database\Seeders\ProductSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Renames/reprices the existing catalog to the new TrackPro lineup and adds the
 * three new devices (Core, Vision, Link). Existing rows keep their slug, stock
 * and active flag; new rows start at 0 stock until set in the admin dashboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (ProductSeeder::lineup() as $data) {
            $product = Product::firstOrNew(['slug' => $data['slug']]);
            $product->fill($data);
            if (!$product->exists) {
                $product->stock = 0;
            }
            $product->save();
        }
    }

    public function down(): void
    {
        // Content-only change; nothing structural to roll back.
    }
};
