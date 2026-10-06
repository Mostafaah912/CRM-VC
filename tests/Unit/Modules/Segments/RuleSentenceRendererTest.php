<?php

declare(strict_types=1);

use App\Modules\Segments\Support\RuleSentenceRenderer;
use Database\Seeders\DefaultSegmentSeeder;

/*
| P6-18 phase 5 (TEST FIRST): the metrics guide page renders every one of the 12 real system segments
| (DefaultSegmentSeeder) as a plain Persian sentence — "بدون JSON/کلید انگلیسی". Field/operator labels
| come from RuleWhitelistPresenter (the Rule Builder's own source), not a second hand-built list.
*/

function renderRule(array $rule): string
{
    return (new RuleSentenceRenderer)->render($rule);
}

it('renders a simple equality condition', function () {
    expect(renderRule(['field' => 'rfm_segment', 'operator' => '=', 'value' => 'champion']))
        ->toBe('سگمنت RFM برابر است با قهرمان');
});

it('renders an "in" list condition with every value translated', function () {
    expect(renderRule(['field' => 'churn_risk_level', 'operator' => 'in', 'value' => ['medium', 'high']]))
        ->toBe('سطح ریسک ریزش شامل یکی از: ریسک متوسط، ریسک بالا');
});

it('renders an AND group with both children in parentheses', function () {
    expect(renderRule([
        'op' => 'AND',
        'children' => [
            ['field' => 'm_score', 'operator' => '=', 'value' => 5],
            ['field' => 'f_score', 'operator' => '>=', 'value' => 4],
        ],
    ]))->toBe('(امتیاز ارزش (M) برابر است با 5) و (امتیاز تکرار (F) بزرگتر یا مساوی 4)');
});

it('renders within_days_of_now with the ± day count', function () {
    expect(renderRule(['field' => 'expected_next_order_at', 'operator' => 'within_days_of_now', 'value' => 7]))
        ->toBe('تاریخ تخمینی خرید بعدی در بازه‌ی ± 7 روز از امروز');
});

it('renders a numeric field without an enum value translation', function () {
    expect(renderRule(['field' => 'total_orders', 'operator' => '=', 'value' => 1]))
        ->toBe('تعداد سفارش برابر است با 1');
});

it('never leaks a JSON key or an English field/operator name', function (array $rule) {
    $sentence = renderRule($rule);

    expect($sentence)->not->toContain('field')->not->toContain('operator')->not->toContain('=')
        ->and(preg_match('/\p{Arabic}/u', $sentence))->toBe(1);
})->with([
    'equality' => [['field' => 'rfm_segment', 'operator' => '=', 'value' => 'champion']],
    'in list' => [['field' => 'churn_risk_level', 'operator' => 'in', 'value' => ['medium', 'high']]],
    'within days' => [['field' => 'expected_next_order_at', 'operator' => 'within_days_of_now', 'value' => 7]],
]);

it('renders all 12 real system segments without error, each a non-empty Persian sentence', function () {
    foreach (DefaultSegmentSeeder::definitions() as $definition) {
        $sentence = renderRule($definition['rule']);

        expect($sentence)->not->toBe('')
            ->and(preg_match('/\p{Arabic}/u', $sentence))->toBe(1, "segment \"{$definition['name']}\" rendered no Persian text: {$sentence}");
    }
});

it('renders each of the 8 RFM-segment seed conditions with the exact Persian segment name, not the stored slug', function (string $segmentName, string $expectedSentence) {
    $definition = collect(DefaultSegmentSeeder::definitions())->firstWhere('name', $segmentName);

    expect(renderRule($definition['rule']))->toBe($expectedSentence);
})->with([
    ['قهرمانان', 'سگمنت RFM برابر است با قهرمان'],
    ['وفادار', 'سگمنت RFM برابر است با وفادار'],
    ['نویدبخش', 'سگمنت RFM برابر است با امیدبخش'],
    ['مشتری جدید', 'سگمنت RFM برابر است با مشتری جدید'],
    ['نباید از دست برود', 'سگمنت RFM برابر است با نباید از دست برود'],
    ['خوابیده', 'سگمنت RFM برابر است با رخوت‌زده'],
    ['از دست رفته', 'سطح ریسک ریزش برابر است با ازدست‌رفته'],
    ['تک‌خرید', 'تعداد سفارش برابر است با 1'],
    ['پرارزش', 'امتیاز ارزش (M) برابر است با 5'],
]);
