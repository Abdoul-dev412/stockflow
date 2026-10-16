<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\StockMovementService;
use Illuminate\Database\Seeder;

class DemoCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $categories = collect(['Aliments', 'Vaccins et santé', 'Matériel', 'Emballages'])
            ->mapWithKeys(fn ($name) => [$name => Category::firstOrCreate(['name' => $name])]);

        $agro = Supplier::firstOrCreate(['name' => 'Agro Plus'], [
            'contact_name' => 'Awa Traoré', 'email' => 'contact@agroplus.test',
            'phone' => '+22670000001', 'lead_time_days' => 5,
        ]);
        $vet = Supplier::firstOrCreate(['name' => 'Vétérinaire Sud'], [
            'contact_name' => 'Ibrahim Sawadogo', 'phone' => '+22670000002', 'lead_time_days' => 10,
        ]);
        $equip = Supplier::firstOrCreate(['name' => 'Équipements Sahel'], [
            'contact_name' => 'Mariam Ouédraogo', 'phone' => '+22670000003', 'lead_time_days' => 14,
        ]);

        // [sku, nom, catégorie, fournisseur, unité, prix d'achat, quantité, seuil]
        $rows = [
            ['ALI-001', 'Aliment démarrage 25 kg', 'Aliments', $agro, 'sac', 18500, 120, 30],
            ['ALI-002', 'Aliment croissance 25 kg', 'Aliments', $agro, 'sac', 17000, 8, 30],
            ['ALI-003', 'Aliment ponte 25 kg', 'Aliments', $agro, 'sac', 16500, 0, 30],
            ['VAC-001', 'Vaccin Newcastle (1000 doses)', 'Vaccins et santé', $vet, 'boîte', 12000, 14, 5],
            ['VAC-002', 'Vaccin Gumboro (1000 doses)', 'Vaccins et santé', $vet, 'boîte', 13500, 5, 5],
            ['VAC-003', 'Vitamines hydrosolubles 1 kg', 'Vaccins et santé', $vet, 'kg', 8500, 22, 6],
            ['MAT-001', 'Mangeoire linéaire 1 m', 'Matériel', $equip, 'unité', 4500, 60, 15],
            ['MAT-002', 'Abreuvoir automatique', 'Matériel', $equip, 'unité', 3200, 3, 10],
            ['EMB-001', 'Plateau à œufs (30 alvéoles)', 'Emballages', $agro, 'plateau', 350, 500, 200],
            ['EMB-002', 'Carton de 12 plateaux', 'Emballages', $agro, 'unité', 1800, 75, 40],
        ];

        $admin = User::where('email', 'admin@stockflow.test')->firstOrFail();
        $service = app(StockMovementService::class);

        foreach ($rows as [$sku, $name, $category, $supplier, $unit, $price, $quantity, $minimum]) {
            $product = Product::factory()->create([
                'sku' => $sku,
                'name' => $name,
                'category_id' => $categories[$category]->id,
                'supplier_id' => $supplier->id,
                'unit' => $unit,
                'purchase_price' => $price,
                'quantity' => 0,
                'minimum_stock' => $minimum,
            ]);

            if ($quantity > 0) {
                $service->recordEntry(
                    $product,
                    $quantity,
                    $admin,
                    comment: 'Stock initial (données de démonstration)',
                    occurredAt: now()->subDays(30),
                );
            }
        }
    }
}