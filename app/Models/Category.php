<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $shop_id
 * @property int|null $parent_id
 * @property int|null $remote_id
 * @property string $name
 * @property string $slug
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Shop $shop
 * @property-read Category|null $parent
 * @property-read Collection<int, Category> $children
 * @property-read Collection<int, Product> $products
 */
#[Fillable(['parent_id', 'remote_id', 'name', 'slug'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    /**
     * The separator between the levels of a written category path.
     */
    public const string PATH_SEPARATOR = '>';

    /**
     * The deepest a written path may go.
     *
     * Stores nest a few levels at most, and the limit keeps a pasted path
     * from turning into an unbounded chain of rows.
     */
    public const int MAX_DEPTH = 5;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (Category $category) {
            if ($category->isDirty('name') && ! $category->isDirty('slug')) {
                $category->slug = Str::slug($category->name);
            }
        });
    }

    /**
     * Get the shop's category at a written path, creating what is missing.
     *
     * A path reads "Bags > Totes", so a nested category can be entered as
     * text without a screen for managing the tree. Each level is matched on
     * its slug, which keeps one row per spelling. A path with nothing
     * sluggable in it is treated as no category at all.
     */
    public static function findOrCreatePath(Shop $shop, ?string $path): ?self
    {
        $names = collect(explode(self::PATH_SEPARATOR, (string) $path))
            ->map(fn (string $name) => trim($name))
            ->filter(fn (string $name) => Str::slug($name) !== '')
            ->take(self::MAX_DEPTH);

        $category = null;

        foreach ($names as $name) {
            $category = $shop->categories()->firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'parent_id' => $category?->id],
            );
        }

        return $category;
    }

    /**
     * Get the written path of every category in a shop, keyed by id.
     *
     * The whole tree is read at once and walked in memory: a shop holds few
     * enough categories for that, and it keeps a page of products from
     * running a query per level of nesting.
     *
     * @return array<int, string>
     */
    public static function pathsFor(Shop $shop): array
    {
        $categories = $shop->categories()
            ->select(['id', 'parent_id', 'name'])
            ->get()
            ->keyBy('id');

        $paths = [];

        foreach ($categories as $category) {
            $names = [];
            $current = $category;

            // Guard against a parent chain that loops back on itself.
            for ($depth = 0; $current !== null && $depth < self::MAX_DEPTH; $depth++) {
                array_unshift($names, $current->name);
                $current = $current->parent_id === null ? null : $categories->get($current->parent_id);
            }

            $paths[$category->id] = implode(' '.self::PATH_SEPARATOR.' ', $names);
        }

        return $paths;
    }

    /**
     * Get the shop that owns the category.
     *
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * Get the category this one sits under.
     *
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * Get the categories sitting directly under this one.
     *
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    /**
     * Get the products filed under this category.
     *
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }
}
