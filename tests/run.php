<?php

declare(strict_types=1);

// Tiny dependency-free test runner: `php tests/run.php`

require __DIR__ . '/../examples/StockPush.php';
require __DIR__ . '/../examples/ReadBack.php';

use MoayaShowcase\ReadBack\ReadBackCheck;
use MoayaShowcase\StockPush\Rule;
use MoayaShowcase\StockPush\StockPushCalculator;
use MoayaShowcase\StockPush\Variant;

$failures = 0;
$count = 0;
function check(string $name, bool $ok): void
{
    global $failures, $count;
    $count++;
    if (!$ok) {
        $failures++;
    }
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
}

$backpack = new Variant('DEMO-BP-ALPHA-RED', 'Alpha', 'Red', 'backpack');
$wallet = new Variant('DEMO-WL-BETA-BLUE', 'Beta', 'Blue', 'wallet');

$calc = new StockPushCalculator(
    new Rule('default', percent: 200),
    [
        new Rule('wallets-1x', category: 'wallet', percent: 100),
        new Rule('alpha', article: 'Alpha', percent: 150, zeroBelow: 3, zeroBelowExceptStores: ['webstore']),
        new Rule('alpha-red', article: 'Alpha', color: 'Red', percent: 100, add: 1, zeroBelow: 3),
    ],
);

// --- rule choice ---
check('most specific rule wins (color over article)', $calc->ruleFor($backpack, 'store-a')->id === 'alpha-red');
check('category rule applies to wallets', $calc->ruleFor($wallet, 'store-a')->id === 'wallets-1x');
check('default applies when nothing else matches', $calc->ruleFor(new Variant('X', 'Gamma', 'Green', 'tote'), 'store-a')->id === 'default');

// --- quantities ---
$d = $calc->decide($backpack, 'store-a', 10);
check('floor(10 × 100%) + 1 = 11', $d->status === 'PUSH' && $d->quantity === 11);

$d = (new StockPushCalculator(new Rule('d', percent: 200)))->decide($wallet, 'store-a', 7);
check('200% of 7 = 14', $d->quantity === 14);

$d = (new StockPushCalculator(new Rule('d', percent: 150)))->decide($wallet, 'store-a', 3);
check('rounds down: floor(3 × 150%) = 4', $d->quantity === 4);

$d = $calc->decide($backpack, 'store-a', 2);
check('below threshold shows 0', $d->quantity === 0);

$d = $calc->decide(new Variant('Y', 'Alpha', 'Black', 'backpack'), 'webstore', 2);
check('threshold exception store still shows stock', $d->quantity === 3);

$d = $calc->decide($backpack, 'store-a', -5);
check('negative sellable never pushes negative (and no +add on zero)', $d->quantity === 0);

$d = $calc->decide($backpack, 'store-a', 4, promoReserved: 8);
check('holds when promo reserves more than we would show', $d->status === 'HOLD' && $d->quantity === null);

// --- read-back ---
$r = ReadBackCheck::check(pushed: 20, soldSincePush: 2, listingAvailable: 18);
check('read-back green when totals match', $r->level === 'GREEN');

$r = ReadBackCheck::check(pushed: 20, soldSincePush: 2, listingAvailable: 4, listingPromoLocked: 14);
check('promo lock is yellow, not red (the production lesson)', $r->level === 'YELLOW' && !$r->shouldPauseSync());

$r = ReadBackCheck::check(pushed: 20, soldSincePush: 1, listingAvailable: 26);
check('unexpected higher total is red (someone else is pushing)', $r->level === 'RED' && $r->shouldPauseSync());

echo PHP_EOL . ($count - $failures) . "/$count passed" . PHP_EOL;
exit($failures === 0 ? 0 : 1);
