<?php

use App\Models\TemuConnection;
use App\Services\Temu\TemuClient;
use App\Services\Temu\TemuException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('requests are signed exactly as temu documents', function () {
    // The worked example from Temu's "Signature Method for API request" guide.
    $params = [
        'access_token' => '2nifvmpyymvypwmcms5ct4uqqudrwgpmzbcnmkt1jzjkuaf3x56iixym',
        'app_key' => 'f9d5cc9313893a20d5aa85c654e8f503',
        'data_type' => 'JSON',
        'sendRequestList' => [[
            'orderSendInfoList' => [[
                'quantity' => 1,
                'orderSn' => '211-21905473070712792',
                'parentOrderSn' => 'PO-211-21905452099192792',
                'goodsId' => 601099548666279,
                'skuId' => 17592352673534,
            ]],
            'carrierId' => '699272611',
            'trackingNumber' => '270324232756',
        ]],
        'sendType' => 0,
        'timestamp' => 1711009072,
        'type' => 'bg.logistics.shipment.confirm',
    ];

    expect(TemuClient::sign($params, 'c7e0a1a63542be4de3cb5488f9fba8149e8fc290'))
        ->toBe('4CCF219942D4180C6DDA3CE36C1B838F');
});

test('a call sends the common parameters with a valid signature and returns the result', function () {
    $connection = TemuConnection::factory()->make();

    Http::fake(['*' => Http::response(['success' => true, 'errorCode' => 1000000, 'result' => ['mallId' => 42]])]);

    $result = (new TemuClient($connection))->call('bg.open.accesstoken.info.get', ['goodsId' => 7, 'skip' => null]);

    expect($result)->toBe(['mallId' => 42]);

    Http::assertSent(function (Request $request) use ($connection) {
        $data = $request->data();

        return $request->url() === 'https://openapi-b-eu.temu.com/openapi/router'
            && $data['type'] === 'bg.open.accesstoken.info.get'
            && $data['app_key'] === $connection->app_key
            && $data['access_token'] === $connection->access_token
            && $data['goodsId'] === 7
            && ! array_key_exists('skip', $data)
            && $data['sign'] === TemuClient::sign($data, (string) $connection->app_secret);
    });
});

test('a refused token is a connection problem', function () {
    Http::fake(['*' => Http::response(['success' => false, 'errorCode' => 7000016, 'errorMsg' => 'access_token is invalid'])]);

    try {
        (new TemuClient(TemuConnection::factory()->make()))->call('bg.local.goods.list.query');
        $this->fail('Expected a Temu exception.');
    } catch (TemuException $exception) {
        expect($exception->isConnectionProblem())->toBeTrue()
            ->and($exception->isRetryable())->toBeFalse()
            ->and($exception->userMessage())->toContain('access_token is invalid');
    }
});

test('a refusal of one request carries temu\'s own message', function () {
    Http::fake(['*' => Http::response(['success' => false, 'errorCode' => 150010028, 'errorMsg' => 'Quantity must be between 0 and 1000000'])]);

    try {
        (new TemuClient(TemuConnection::factory()->make()))->call('bg.local.goods.stock.edit');
        $this->fail('Expected a Temu exception.');
    } catch (TemuException $exception) {
        expect($exception->isConnectionProblem())->toBeFalse()
            ->and($exception->temuCode())->toBe(150010028)
            ->and($exception->userMessage())->toBe('Quantity must be between 0 and 1000000');
    }
});

test('rate limits and server errors can be retried', function (int $status) {
    Http::fake(['*' => Http::response('', $status)]);

    expect(fn () => (new TemuClient(TemuConnection::factory()->make()))->call('bg.local.goods.list.query'))
        ->toThrow(fn (TemuException $exception) => expect($exception->isRetryable())->toBeTrue());
})->with([429, 502]);
