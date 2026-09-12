<?php

namespace App\Concerns;

use App\Models\Shop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Shared behaviour for the names a product is filed under.
 *
 * Brands and categories are the same kind of thing: a name belonging to one
 * shop, told apart from its siblings by a slug, and optionally tied to the
 * row it came from in a remote store.
 *
 * @phpstan-require-extends Model
 */
trait IsCatalogTerm
{
    /**
     * Get the shop's terms of this kind.
     *
     * @return HasMany<self, Shop>
     */
    abstract protected static function termsOf(Shop $shop): HasMany;

    /**
     * Keep the slug in step with the name.
     */
    public static function bootIsCatalogTerm(): void
    {
        static::saving(function (Model $term) {
            if ($term->isDirty('name') && ! $term->isDirty('slug')) {
                $term->setAttribute('slug', Str::slug((string) $term->getAttribute('name')));
            }
        });
    }

    /**
     * Get the shop's term of this name, creating it when it is new.
     *
     * Terms arrive as typed or imported text, so they are matched on the
     * slug: that keeps "Acme", "acme" and " ACME " on one row instead of
     * three. A name with nothing sluggable in it is treated as no term.
     */
    public static function findOrCreateFor(Shop $shop, ?string $name): ?self
    {
        $name = trim((string) $name);
        $slug = Str::slug($name);

        if ($slug === '') {
            return null;
        }

        return static::termsOf($shop)->firstOrCreate(['slug' => $slug], ['name' => $name]);
    }

    /**
     * Create or update the term a remote store knows by this id.
     *
     * Matching on the remote id is what lets a term renamed in the store
     * keep its products, instead of the rename reading as a new term and
     * leaving the old one orphaned.
     *
     * The store's own slug is preferred, since that is what lines the row
     * up with the term as the store knows it. A slug already taken by
     * another of the shop's terms falls back to one derived from the name,
     * and then to the remote id, rather than failing the import.
     */
    public static function findOrCreateForRemote(
        Shop $shop,
        int $remoteId,
        string $name,
        ?string $remoteSlug = null,
    ): ?self {
        if ($remoteId === 0 || Str::slug($name) === '') {
            return null;
        }

        $term = static::termsOf($shop)->firstOrNew(['remote_id' => $remoteId]);

        foreach (static::slugCandidates($remoteSlug, $name, $remoteId) as $slug) {
            $taken = static::termsOf($shop)
                ->where('slug', $slug)
                ->when($term->exists, fn ($query) => $query->whereKeyNot($term->getKey()))
                ->exists();

            if ($taken) {
                continue;
            }

            $term->fill(['name' => $name, 'slug' => $slug])->save();

            return $term;
        }

        return null;
    }

    /**
     * Get the slugs to try for a term, in order of preference.
     *
     * @return array<int, string>
     */
    protected static function slugCandidates(?string $remoteSlug, string $name, int $remoteId): array
    {
        $candidates = [
            Str::slug((string) $remoteSlug),
            Str::slug($name),
            Str::slug($name).'-'.$remoteId,
        ];

        return array_values(array_unique(array_filter($candidates)));
    }
}
