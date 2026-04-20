<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Category;
use App\Models\Subcategory;
use App\Models\Brand;
use App\Models\Unit;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\Product;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Models\Banner;
use App\Models\Offer;
use App\Models\ServiceContent;


class ThemeImportController extends Controller
{
    public function importThemeContent(Request $request, $theme)
    {
        $basePath = public_path("theme-content/{$theme}");
        
        if (!is_dir($basePath)) {
            return response()->json(['success' => false, 'message' => 'Theme directory not found.'], 404);
        }

        $filesToModels = [
            'banner.json' => Banner::class,
            'offer.json' => Offer::class,
            'services.json' => ServiceContent::class,
            'brand.json' => Brand::class,
            'category.json' => Category::class,
            'sub-category.json' => Subcategory::class,
            'supplier.json' => Supplier::class,
            'unit.json' => Unit::class,
            'warehouse.json' => Warehouse::class,
            'product.json' => Product::class,
        ];

        try {
            foreach ($filesToModels as $filename => $modelClass) {
                $filePath = $basePath . '/' . $filename;
                if (file_exists($filePath)) {
                    $jsonData = file_get_contents($filePath);
                    $items = json_decode($jsonData, true);
                    
                    if (is_array($items)) {
                        $modelInstance = new $modelClass();
                        $columns = Schema::getColumnListing($modelInstance->getTable());

                        foreach ($items as $item) {
                            $oldId = $item['id'] ?? null;
                            
                            // Remove the ID so the database auto-increments and creates a NEW record 
                            // instead of replacing the user's existing records
                            if (isset($item['id'])) {
                                unset($item['id']);
                            }

                            // Remap foreign keys for the product table so they match the newly created sub-records
                            if ($filename === 'product.json') {
                                if (isset($item['category_id']) && isset($idMap[Category::class][$item['category_id']])) {
                                    $item['category_id'] = $idMap[Category::class][$item['category_id']];
                                }
                                if (isset($item['subcategory_id']) && isset($idMap[Subcategory::class][$item['subcategory_id']])) {
                                    $item['subcategory_id'] = $idMap[Subcategory::class][$item['subcategory_id']];
                                }
                                if (isset($item['brand_id']) && isset($idMap[Brand::class][$item['brand_id']])) {
                                    $item['brand_id'] = $idMap[Brand::class][$item['brand_id']];
                                }
                                if (isset($item['supplier_id']) && isset($idMap[Supplier::class][$item['supplier_id']])) {
                                    $item['supplier_id'] = $idMap[Supplier::class][$item['supplier_id']];
                                }
                                if (isset($item['warehouse_id']) && isset($idMap[Warehouse::class][$item['warehouse_id']])) {
                                    $item['warehouse_id'] = $idMap[Warehouse::class][$item['warehouse_id']];
                                }
                                if (isset($item['unit_id']) && isset($idMap[Unit::class][$item['unit_id']])) {
                                    $item['unit_id'] = $idMap[Unit::class][$item['unit_id']];
                                }
                            }
                            
                            // Remap foreign key for subcategories
                            if ($filename === 'sub-category.json') {
                                if (isset($item['category_id']) && isset($idMap[Category::class][$item['category_id']])) {
                                    $item['category_id'] = $idMap[Category::class][$item['category_id']];
                                }
                            }

                            // Try to find if we already imported this exact same string name to avoid full duplicates
                            // (optional, but it prevents infinite duplication if they run it 10 times)
                            $existingQuery = $modelClass::query();
                            if (isset($item['sku'])) {
                                $existingQuery->where('sku', $item['sku']);
                            } elseif (isset($item['slug'])) {
                                $existingQuery->where('slug', $item['slug']);
                            } elseif (isset($item['name'])) {
                                $existingQuery->where('name', $item['name']);
                            } elseif (isset($item['image'])) {
                                $existingQuery->where('image', $item['image']);
                            } elseif (isset($item['title']) && $item['title'] !== '') {
                                $existingQuery->where('title', $item['title']);
                            }
                            
                            $model = $existingQuery->first();

                            // If we don't have it, create it!
                            if (!$model) {
                                $model = new $modelClass();
                                foreach ($item as $key => $value) {
                                    if (in_array($key, $columns)) {
                                        if (is_array($value)) {
                                            $model->{$key} = $model->hasCast($key) ? $value : json_encode($value);
                                        } else {
                                            $model->{$key} = $value;
                                        }
                                    }
                                }
                                $model->save();
                            }

                            // Save the mapping from the JSON ID to the new database ID
                            if ($oldId) {
                                $idMap[$modelClass][$oldId] = $model->id;
                            }
                        }
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Theme content imported successfully.'
            ]);
        } catch (\Exception $e) {
            Log::error('Theme import failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to import theme content: ' . $e->getMessage()
            ], 500);
        }
    }
}
