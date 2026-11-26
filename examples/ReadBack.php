<?php

declare(strict_types=1);

namespace MoayaShowcase\ReadBack;

/**
 * Read-back check — after pushing stock, read the listing back and compare.
 *
 * Lesson from production: an early version compared the pushed number with
 * the marketplace's "available" figure. During a campaign part of the stock
 * is locked for the promotion, so "available" looked too low, the monitor
 * raised a red alert and paused stock sync for no reason. The fix: compare
 * against TOTAL stock (available + locked for promotion) and only treat a
 * promo lock as information.
 *
 * Rewritten for this showcase (not the production code).
 */
final class ReadBackResult
{
    public function __construct(
        public readonly string $level,   // GREEN | YELLOW | RED
        public readonly int $expected,
        public readonly int $listingTotal,
        public readonly string $message,
    ) {
    }

    public function shouldPauseSync(): bool
    {
        return $this->level === 'RED';
    }
}

final class ReadBackCheck
{
    /**
     * @param int $pushed            number we pushed last time
     * @param int $soldSincePush     units ordered on this listing after that push
     * @param int $listingAvailable  "available" as read from the marketplace
     * @param int $listingPromoLocked units the marketplace holds for a promotion
     */
    public static function check(int $pushed, int $soldSincePush, int $listingAvailable, int $listingPromoLocked = 0): ReadBackResult
    {
        $expected = max(0, $pushed - $soldSincePush);
        $total = $listingAvailable + $listingPromoLocked;

        if ($total !== $expected) {
            return new ReadBackResult(
                'RED',
                $expected,
                $total,
                sprintf('listing shows %d in total, expected %d — another tool may be overwriting stock', $total, $expected),
            );
        }

        if ($listingPromoLocked > 0) {
            return new ReadBackResult(
                'YELLOW',
                $expected,
                $total,
                sprintf('%d of %d units are locked for a promotion (not an error)', $listingPromoLocked, $total),
            );
        }

        return new ReadBackResult('GREEN', $expected, $total, 'listing matches');
    }
}
