<?php

declare(strict_types=1);

use App\Modules\Sync\Enums\WooWebhookTopic;

/*
| P2-09 topic routing. Woo itself sends `order.created` (no prefix); the task also names the `woocommerce.`-prefixed
| spelling, so both map to one enum value. Exactly one prefix is understood, nothing is guessed beyond it, and only the
| three order topics exist: no refund, product or coupon topic is routed.
*/

it('routes exactly the three order topics', function () {
    expect(array_map(fn (WooWebhookTopic $t) => $t->value, WooWebhookTopic::cases()))
        ->toBe(['order.created', 'order.updated', 'order.deleted']);
});

it('reads a topic in Woo\'s own spelling and in the woocommerce.-prefixed one, to the same value', function (string $header, WooWebhookTopic $expected) {
    expect(WooWebhookTopic::fromHeader($header))->toBe($expected);
})->with([
    'created' => ['order.created', WooWebhookTopic::OrderCreated],
    'updated' => ['order.updated', WooWebhookTopic::OrderUpdated],
    'deleted' => ['order.deleted', WooWebhookTopic::OrderDeleted],
    'prefixed created' => ['woocommerce.order.created', WooWebhookTopic::OrderCreated],
    'prefixed updated' => ['woocommerce.order.updated', WooWebhookTopic::OrderUpdated],
    'prefixed deleted' => ['woocommerce.order.deleted', WooWebhookTopic::OrderDeleted],
]);

it('routes nothing else: other resources, other events, other prefixes, other spellings', function (?string $header) {
    expect(WooWebhookTopic::fromHeader($header))->toBeNull();
})->with([
    'missing' => [null],
    'empty' => [''],
    'blank' => [' '],
    'resource only' => ['order'],
    'no event' => ['order.'],
    'restored' => ['order.restored'],
    'product' => ['product.created'],
    'refund' => ['refund.created'],
    'prefixed refund' => ['woocommerce.refund.created'],
    'prefix twice' => ['woocommerce.woocommerce.order.created'],
    'another prefix' => ['wc.order.created'],
    'underscored hook name' => ['woocommerce_order_created'],
    'plural' => ['orders.created'],
    'a bare prefix' => ['woocommerce.'],
    'a custom action topic' => ['action.woocommerce_new_order'],
    'wrong case' => ['Order.Created'],
    'wrong case prefix' => ['WOOCOMMERCE.order.created'],
    'trailing space' => ['order.created '],
    'leading space' => [' order.created'],
    'trailing newline' => ["order.created\n"],
]);
