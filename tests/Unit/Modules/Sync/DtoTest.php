<?php

declare(strict_types=1);

use App\Modules\Sync\DTOs\CategoryDto;
use App\Modules\Sync\DTOs\OrderDto;
use App\Modules\Sync\DTOs\OrderItemDto;
use App\Modules\Sync\DTOs\ProductDto;
use App\Modules\Sync\DTOs\RefundDto;
use App\Modules\Sync\DTOs\RefundItemDto;
use App\Modules\Sync\DTOs\VariationDto;
use Carbon\CarbonImmutable;

/*
| DTOs are typed, immutable data with no behaviour. Building one from a raw Woo payload is the
| mappers' job; here only the shape guarantees are proven.
*/

const DTO_CLASSES = [
    CategoryDto::class, ProductDto::class, VariationDto::class,
    OrderDto::class, OrderItemDto::class, RefundDto::class, RefundItemDto::class,
];

function dtoInstances(): array
{
    $at = CarbonImmutable::parse('2026-05-10 08:30:00', 'UTC');
    $item = new OrderItemDto(9001, 101, null, 'SYN-TEE-001', 'تی‌شرت', 1, 403880, 403880, 403880);
    $refundItem = new RefundItemDto(9101, 101, null, 'SYN-TEE-001', 1, 100000, 9001);

    return [
        'category' => new CategoryDto(31, 'پوشاک', 'apparel', null),
        'product' => new ProductDto(101, 'تی‌شرت', 'tee', 'simple', 'publish', 'SYN-TEE-001', 403880, $at, [31]),
        'variation' => new VariationDto(1031, 103, 'SYN-S', 250000, 'publish', ['سایز' => 'S']),
        'order item' => $item,
        'order' => new OrderDto(5001, '5001', 'completed', 'IRT', 11, 'الف', 'ب', '09000000001', 403880, 0, 0, 0, ['synth10'], 'cod', $at, $at, null, $at, [$item]),
        'refund item' => $refundItem,
        'refund' => new RefundDto(7001, 5001, 100000, null, $at, [$refundItem]),
    ];
}

it('is final, readonly, and carries no behaviour beyond its constructor', function (string $class) {
    $reflection = new ReflectionClass($class);
    $methods = array_map(fn (ReflectionMethod $m) => $m->getName(), $reflection->getMethods());

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue()
        ->and($methods)->toBe(['__construct']);
})->with(DTO_CLASSES);

it('declares a type on every property and never mixed', function (string $class) {
    foreach ((new ReflectionClass($class))->getProperties() as $property) {
        expect($property->hasType())->toBeTrue("{$class}::\${$property->getName()} is untyped")
            ->and((string) $property->getType())->not->toContain('mixed')->not->toContain('float');
    }
})->with(DTO_CLASSES);

it('cannot be modified after construction', function (string $name) {
    $dto = dtoInstances()[$name];
    $property = (new ReflectionClass($dto))->getProperties()[0]->getName();

    expect(function () use ($dto, $property) {
        $dto->{$property} = 'changed';
    })->toThrow(Error::class);
})->with(['category', 'product', 'variation', 'order item', 'order', 'refund item', 'refund']);

it('cannot gain properties either', function () {
    $dto = dtoInstances()['category'];

    expect(function () use ($dto) {
        $dto->extra = 1;
    })->toThrow(Error::class);
});

it('is built from valid data with the right types', function () {
    $order = dtoInstances()['order'];

    expect($order->wooOrderId)->toBeInt()
        ->and($order->total)->toBeInt()
        ->and($order->orderedAt)->toBeInstanceOf(CarbonImmutable::class)
        ->and($order->items[0])->toBeInstanceOf(OrderItemDto::class)
        ->and($order->paidAt)->toBeInstanceOf(CarbonImmutable::class)
        ->and($order->completedAt)->toBeNull();
});

it('allows null only where Woo genuinely has no value', function () {
    $d = dtoInstances();

    expect($d['category']->parentWooCategoryId)->toBeNull()
        ->and($d['order item']->wooVariationId)->toBeNull()
        ->and($d['refund']->reason)->toBeNull()
        ->and((new RefundItemDto(9102, null, null, null, 1, 1, null))->originalWooItemId)->toBeNull();
});

it('rejects wrong types at construction: money is never a string or a float', function (callable $build) {
    expect($build)->toThrow(TypeError::class);
})->with([
    'money as float' => [fn () => new OrderItemDto(1, 1, null, null, 'n', 1, 1.5, 1, 1)],
    'money as string' => [fn () => new OrderItemDto(1, 1, null, null, 'n', 1, '100', 1, 1)],
    'id as string' => [fn () => new CategoryDto('31', 'n', null, null)],
    'null name' => [fn () => new CategoryDto(31, null, null, null)],
    'date as string' => [fn () => new RefundDto(1, 1, 1, null, '2026-05-10', [])],
]);

it('rejects an incomplete construction: a required value is never defaulted', function () {
    new CategoryDto(31, 'name');
})->throws(ArgumentCountError::class);
