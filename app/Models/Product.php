<?php

namespace App\Models;

use App\Enums\NaldaListing;
use App\Enums\ProductStatus;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $shop_id
 * @property int|null $brand_id
 * @property int|null $remote_id
 * @property string $name
 * @property string $name_sort
 * @property string $sku
 * @property string|null $gtin
 * @property string|null $description
 * @property string|null $short_description
 * @property string|null $image_url
 * @property array<int, string>|null $gallery_image_urls
 * @property string|null $permalink
 * @property string $price
 * @property int $stock
 * @property ProductStatus $status
 * @property NaldaListing $nalda_listing
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Shop $shop
 * @property-read Brand|null $brand
 * @property-read Collection<int, Category> $categories
 */
#[Fillable([
    'brand_id',
    'remote_id',
    'name',
    'sku',
    'gtin',
    'description',
    'short_description',
    'image_url',
    'gallery_image_urls',
    'permalink',
    'price',
    'stock',
    'status',
    'nalda_listing',
    'last_synced_at',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * Keep the name's sort key in step with the name on every save.
     *
     * Every write of a name goes through the model, the import included,
     * so this is the one place the key is kept.
     */
    protected static function booted(): void
    {
        static::saving(function (Product $product) {
            $product->name_sort = self::nameSortKey($product->name);
        });
    }

    /**
     * Products at or below this stock level are considered low on stock.
     */
    public const int LOW_STOCK_THRESHOLD = 5;

    /**
     * The columns a product list may be ordered by.
     *
     * Sorting is restricted to this list so a query string cannot order by
     * an arbitrary column.
     *
     * @var array<string, string>
     */
    public const array SORTABLE = [
        'name' => 'name_sort',
        'price' => 'price',
        'stock' => 'stock',
        'updated' => 'updated_at',
    ];

    /**
     * The column a product list is ordered by when none is chosen.
     */
    public const string DEFAULT_SORT = 'name';

    /**
     * Get the order a sortable column starts in.
     *
     * Text reads best ascending and figures descending, which is the order
     * each is normally wanted in.
     */
    public static function defaultDirection(string $sort): string
    {
        return $sort === 'name' ? 'asc' : 'desc';
    }

    /**
     * The longest name sort key stored; later characters never decide an order.
     */
    public const int NAME_SORT_LENGTH = 500;

    /**
     * Places each number in a name is padded to in its sort key.
     */
    private const int NAME_SORT_DIGITS = 20;

    /**
     * Get the key a name sorts by, so numbers in it order by their value.
     *
     * Every run of digits is padded with zeros, which puts "2 pens" before
     * "10 pens". The key is lowercased so every database orders it the same
     * way, whatever its collation.
     */
    public static function nameSortKey(string $name): string
    {
        $key = preg_replace_callback(
            '/\d+/',
            fn (array $digits) => str_pad(ltrim($digits[0], '0'), self::NAME_SORT_DIGITS, '0', STR_PAD_LEFT),
            mb_strtolower($name),
        );

        return mb_substr((string) $key, 0, self::NAME_SORT_LENGTH);
    }

    /**
     * Narrow the query to products matching a search term.
     *
     * The term is matched against everything a person is likely to have to
     * hand: what the product is called, either of its codes, and the brand
     * on the box.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeMatching(Builder $query, string $term): void
    {
        if ($term === '') {
            return;
        }

        $query->where(fn (Builder $query) => $query
            ->where('name', 'like', "%{$term}%")
            ->orWhere('sku', 'like', "%{$term}%")
            ->orWhere('gtin', 'like', "%{$term}%")
            ->orWhereHas('brand', fn (Builder $brand) => $brand->where('name', 'like', "%{$term}%")));
    }

    /**
     * Narrow the query to products filed under any of these categories.
     *
     * @param  Builder<Product>  $query
     * @param  array<int, int>  $categoryIds
     */
    public function scopeInCategories(Builder $query, array $categoryIds): void
    {
        $query->whereHas('categories', fn (Builder $category) => $category->whereIn('categories.id', $categoryIds));
    }

    /**
     * Narrow the query to products filed under nothing at all.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeUncategorized(Builder $query): void
    {
        $query->whereDoesntHave('categories');
    }

    /**
     * Order the query by one of the sortable columns.
     *
     * The id is always the final tiebreak, so a page boundary cannot land
     * in the middle of a group of equal values and repeat or skip a row.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeSorted(Builder $query, ?string $sort, string $direction): void
    {
        $column = self::SORTABLE[$sort] ?? self::SORTABLE[self::DEFAULT_SORT];
        $direction = $direction === 'asc' ? 'asc' : 'desc';

        $query->orderBy($column, $direction)->orderBy('id', $direction);
    }

    /**
     * Get the shop that owns the product.
     *
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * Get the product's brand, if it has been given one.
     *
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Get the categories the product is filed under.
     *
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'stock' => 'integer',
            'status' => ProductStatus::class,
            'nalda_listing' => NaldaListing::class,
            'remote_id' => 'integer',
            'gallery_image_urls' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }
}
