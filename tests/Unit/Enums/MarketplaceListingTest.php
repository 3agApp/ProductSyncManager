<?php

use App\Enums\NaldaDefaultBehavior;
use App\Enums\NaldaListing;
use App\Enums\TemuDefaultBehavior;
use App\Enums\TemuListing;

dataset('listing choices', [
    // product, brand, shop includes all, listed
    'product include wins over brand exclude' => ['include', 'exclude', false, true],
    'product exclude wins over brand include' => ['exclude', 'include', true, false],
    'brand include wins over shop exclude all' => ['default', 'include', false, true],
    'brand exclude wins over shop include all' => ['default', 'exclude', true, false],
    'brand following along leaves the shop include all' => ['default', 'default', true, true],
    'brand following along leaves the shop exclude all' => ['default', 'default', false, false],
    'no brand leaves the shop include all' => ['default', null, true, true],
    'no brand leaves the shop exclude all' => ['default', null, false, false],
]);

test('the most specific temu choice wins', function (string $product, ?string $brand, bool $includeAll, bool $listed) {
    $default = $includeAll ? TemuDefaultBehavior::IncludeAll : TemuDefaultBehavior::ExcludeAll;

    expect(TemuListing::from($product)->includes($default, $brand ? TemuListing::from($brand) : null))->toBe($listed);
})->with('listing choices');

test('the most specific nalda choice wins', function (string $product, ?string $brand, bool $includeAll, bool $listed) {
    $default = $includeAll ? NaldaDefaultBehavior::IncludeAll : NaldaDefaultBehavior::ExcludeAll;

    expect(NaldaListing::from($product)->includes($default, $brand ? NaldaListing::from($brand) : null))->toBe($listed);
})->with('listing choices');
