<?php

use App\Actions\Catalog\DeleteCategory;
use App\Enums\CategoryChildrenAction;
use App\Enums\CategoryProductsAction;
use App\Enums\ShopRole;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->shop = $this->owner->currentShop;
    $this->root = Category::factory()->for($this->shop)->create(['name' => 'Bags']);
    $this->child = Category::factory()->childOf($this->root)->create(['name' => 'Totes']);
    $this->leaf = Category::factory()->childOf($this->child)->create(['name' => 'Canvas']);
    $this->destination = Category::factory()->for($this->shop)->create(['name' => 'Accessories']);
    $this->exclusive = Product::factory()->for($this->shop)->create();
    $this->exclusive->categories()->sync([$this->root->id, $this->child->id]);
    $this->shared = Product::factory()->for($this->shop)->create();
    $this->shared->categories()->sync([$this->child->id, $this->destination->id]);
    $this->nested = Product::factory()->for($this->shop)->create();
    $this->nested->categories()->sync([$this->leaf->id]);
    $this->unrelated = Product::factory()->for($this->shop)->create();
    $this->unrelated->categories()->sync([$this->destination->id]);
    $this->actingAs($this->owner);
});

function deletionOptions(Category $category, string $children = 'delete', string $products = 'keep'): array
{
    return [
        'children' => $children,
        'products' => $products,
        'preview_token' => app(DeleteCategory::class)->plan($category, CategoryChildrenAction::from($children))['token'],
    ];
}

test('preview deduplicates products and reports exclusive and shared counts', function () {
    $this->getJson(route('categories.deletion-preview', [$this->shop, $this->root, 'children' => 'delete']))
        ->assertOk()
        ->assertJsonPath('categoryCount', 3)
        ->assertJsonPath('descendantCount', 2)
        ->assertJsonPath('productCount', 3)
        ->assertJsonPath('exclusiveProductCount', 2)
        ->assertJsonPath('sharedProductCount', 1)
        ->assertJsonCount(1, 'destinations')
        ->assertJsonPath('destinations.0.id', $this->destination->id);

    expect($this->shop->categories()->count())->toBe(4);
});

test('keeping children previews only products directly assigned to the removed category', function () {
    $this->getJson(route('categories.deletion-preview', [$this->shop, $this->root]))
        ->assertOk()
        ->assertJsonPath('categoryCount', 1)
        ->assertJsonPath('productCount', 1)
        ->assertJsonPath('exclusiveProductCount', 0)
        ->assertJsonCount(3, 'destinations');
});

test('deleting a branch keeps products and their surviving assignments by default', function () {
    $this->delete(route('categories.destroy', [$this->shop, $this->root]), deletionOptions($this->root))
        ->assertSessionHasNoErrors();

    expect($this->shop->categories()->pluck('id')->all())->toBe([$this->destination->id])
        ->and($this->shop->products()->count())->toBe(4)
        ->and($this->exclusive->fresh()->categories)->toBeEmpty()
        ->and($this->nested->fresh()->categories)->toBeEmpty()
        ->and($this->shared->fresh()->categories->modelKeys())->toBe([$this->destination->id]);
});

test('moving branch products preserves unrelated links and does not duplicate destination links', function () {
    $other = Category::factory()->for($this->shop)->create();
    $this->exclusive->categories()->attach($other);
    $this->delete(route('categories.destroy', [$this->shop, $this->root]), [
        ...deletionOptions($this->root, products: 'move'),
        'destination_id' => $this->destination->id,
    ])->assertSessionHasNoErrors();

    expect($this->shop->products()->count())->toBe(4)
        ->and($this->shared->fresh()->categories->modelKeys())->toBe([$this->destination->id])
        ->and($this->exclusive->fresh()->categories->modelKeys())->toEqualCanonicalizing([$other->id, $this->destination->id])
        ->and($this->nested->fresh()->categories->modelKeys())->toBe([$this->destination->id]);
});

test('product deletion only removes products exclusive to the deleted branch', function () {
    $this->delete(route('categories.destroy', [$this->shop, $this->root]), [
        ...deletionOptions($this->root, products: 'delete'),
        'confirmation' => $this->root->name,
    ])->assertSessionHasNoErrors();

    expect(Product::find($this->exclusive->id))->toBeNull()
        ->and(Product::find($this->nested->id))->toBeNull()
        ->and($this->shared->fresh()->categories->modelKeys())->toBe([$this->destination->id])
        ->and($this->unrelated->fresh())->not->toBeNull();
});

test('keeping children preserves their products even with exclusive product deletion selected', function () {
    $this->delete(route('categories.destroy', [$this->shop, $this->root]), [
        ...deletionOptions($this->root, children: 'keep', products: 'delete'),
        'confirmation' => $this->root->name,
    ])->assertSessionHasNoErrors();

    expect($this->shop->products()->count())->toBe(4)
        ->and($this->child->fresh()->parent_id)->toBeNull()
        ->and($this->leaf->fresh()->parent_id)->toBe($this->child->id)
        ->and($this->exclusive->fresh()->categories->modelKeys())->toBe([$this->child->id]);
});

test('products can move to a retained child category', function () {
    $this->delete(route('categories.destroy', [$this->shop, $this->root]), [
        ...deletionOptions($this->root, children: 'keep', products: 'move'),
        'destination_id' => $this->leaf->id,
    ])->assertSessionHasNoErrors();

    expect($this->exclusive->fresh()->categories->modelKeys())->toEqualCanonicalizing([$this->child->id, $this->leaf->id]);
});

test('a destination in the deleted branch is rejected without writing', function () {
    $this->delete(route('categories.destroy', [$this->shop, $this->root]), [
        ...deletionOptions($this->root, products: 'move'),
        'destination_id' => $this->leaf->id,
    ])->assertSessionHasErrors('destination_id');
    expect($this->shop->categories()->count())->toBe(4);
});

test('another shops destination is rejected', function () {
    $outside = Category::factory()->create();
    $this->delete(route('categories.destroy', [$this->shop, $this->root]), [
        ...deletionOptions($this->root, products: 'move'),
        'destination_id' => $outside->id,
    ])->assertSessionHasErrors('destination_id');
    expect($this->shop->categories()->count())->toBe(4);
});

test('destructive options require a preview and an exact product deletion confirmation', function () {
    $this->delete(route('categories.destroy', [$this->shop, $this->root]), [
        'products' => 'delete', 'confirmation' => 'Wrong name',
    ])->assertSessionHasErrors(['preview_token', 'confirmation']);
    expect($this->shop->products()->count())->toBe(4);
});

test('the action itself refuses destructive choices without a reviewed preview', function (CategoryChildrenAction $children, CategoryProductsAction $products) {
    expect(fn () => app(DeleteCategory::class)->handle($this->root, $children, $products, $this->destination->id, null))
        ->toThrow(ValidationException::class);
    expect($this->shop->categories()->count())->toBe(4)
        ->and($this->shop->products()->count())->toBe(4);
})->with([
    [CategoryChildrenAction::Delete, CategoryProductsAction::Keep],
    [CategoryChildrenAction::Keep, CategoryProductsAction::Move],
    [CategoryChildrenAction::Keep, CategoryProductsAction::Delete],
]);

test('unknown deletion modes fail validation', function () {
    $this->delete(route('categories.destroy', [$this->shop, $this->root]), ['children' => 'unknown', 'products' => 'everything'])
        ->assertSessionHasErrors(['children', 'products']);
});

test('new products invalidate the reviewed preview', function () {
    $options = deletionOptions($this->root, products: 'delete');
    Product::factory()->for($this->shop)->create()->categories()->attach($this->root);
    $this->delete(route('categories.destroy', [$this->shop, $this->root]), [...$options, 'confirmation' => $this->root->name])
        ->assertSessionHasErrors('preview_token');
    expect($this->shop->products()->count())->toBe(5)
        ->and($this->shop->categories()->count())->toBe(4);
});

test('changed shared membership invalidates a destructive preview', function () {
    $options = deletionOptions($this->root, products: 'delete');
    $this->shared->categories()->detach($this->destination);
    $this->delete(route('categories.destroy', [$this->shop, $this->root]), [...$options, 'confirmation' => $this->root->name])
        ->assertSessionHasErrors('preview_token');
    expect($this->shop->products()->count())->toBe(4);
});

test('deep branches are fully deleted without the display depth cap', function () {
    $parent = $this->leaf;
    for ($i = 0; $i < 7; $i++) {
        $parent = Category::factory()->childOf($parent)->create();
    }
    $this->delete(route('categories.destroy', [$this->shop, $this->root]), deletionOptions($this->root))
        ->assertSessionHasNoErrors();
    expect($this->shop->categories()->count())->toBe(1);
});

test('a failed category deletion rolls back product moves and child promotion', function () {
    $this->withoutExceptionHandling();
    DB::listen(function ($query) {
        if (str_starts_with($query->sql, 'delete from "categories"')) {
            throw new RuntimeException('Simulated storage failure');
        }
    });
    expect(fn () => $this->delete(route('categories.destroy', [$this->shop, $this->root]), [
        ...deletionOptions($this->root, children: 'keep', products: 'move'),
        'destination_id' => $this->destination->id,
    ]))->toThrow(RuntimeException::class, 'Simulated storage failure');
    expect($this->root->fresh())->not->toBeNull()
        ->and($this->child->fresh()->parent_id)->toBe($this->root->id)
        ->and($this->exclusive->fresh()->categories->modelKeys())->toEqualCanonicalizing([$this->root->id, $this->child->id]);
});

test('preview is shop scoped and unavailable to members', function () {
    $outside = Category::factory()->create();
    $this->getJson(route('categories.deletion-preview', [$this->shop, $outside]))->assertNotFound();
    $member = User::factory()->create();
    $this->shop->members()->attach($member, ['role' => ShopRole::Member->value]);
    $this->actingAs($member)->getJson(route('categories.deletion-preview', [$this->shop, $this->root]))->assertForbidden();
    $this->delete(route('categories.destroy', [$this->shop, $this->root]), deletionOptions($this->root))->assertForbidden();
});

test('category deletion permission alone cannot authorize permanent product deletion', function () {
    $member = User::factory()->create();
    $this->shop->members()->attach($member, ['role' => ShopRole::Member->value]);
    Gate::before(fn ($user, $ability, $arguments) => $ability === 'delete' && ($arguments[0] ?? null) instanceof Category ? true : null);
    $this->actingAs($member)->delete(route('categories.destroy', [$this->shop, $this->root]), [
        ...deletionOptions($this->root, products: 'delete'),
        'confirmation' => $this->root->name,
    ])->assertForbidden();
    expect($this->shop->products()->count())->toBe(4);
});

test('imported descendants are included in the sync warning', function () {
    $this->leaf->update(['remote_id' => 123]);
    $this->getJson(route('categories.deletion-preview', [$this->shop, $this->root, 'children' => 'delete']))
        ->assertJsonPath('hasSyncedCategories', true);
    $this->getJson(route('categories.deletion-preview', [$this->shop, $this->root]))
        ->assertJsonPath('hasSyncedCategories', false);
});
