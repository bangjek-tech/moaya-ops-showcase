<?php

declare(strict_types=1);

namespace MoayaShowcase\StockPush;

/**
 * Stock push rules — how many units to show on each marketplace listing.
 *
 * Rewritten for this showcase (not the production code). The idea:
 * the warehouse knows the real sellable stock; each store gets a number
 * derived from it by the most specific matching rule.
 *
 *   shown = floor(sellable × percent / 100) + add
 *
 * plus three safety rules:
 *   - "zero below": when sellable stock is under a threshold, show 0
 *     (except on stores listed as exceptions);
 *   - never push a negative number;
 *   - if a campaign has reserved more units than we are about to push,
 *     HOLD instead of pushing — the marketplace would reject it, and the
 *     promo stock itself needs fixing.
 */
final class Variant
{
    public function __construct(
        public readonly string $sku,
        public readonly string $article,
        public readonly string $color,
        public readonly string $category,
    ) {
    }
}

final class Rule
{
    /** @param list<string> $zeroBelowExceptStores */
    public function __construct(
        public readonly string $id,
        public readonly ?string $article = null,
        public readonly ?string $color = null,
        public readonly ?string $category = null,
        public readonly ?string $store = null,
        public readonly int $percent = 100,
        public readonly int $add = 0,
        public readonly int $zeroBelow = 0,
        public readonly array $zeroBelowExceptStores = [],
    ) {
        if ($percent < 0 || $add < 0 || $zeroBelow < 0) {
            throw new \InvalidArgumentException('Rule values must not be negative');
        }
        if ($color !== null && $article === null) {
            throw new \InvalidArgumentException('A color rule must also name its article');
        }
    }

    public function matches(Variant $variant, string $store): bool
    {
        return ($this->article === null || $this->article === $variant->article)
            && ($this->color === null || $this->color === $variant->color)
            && ($this->category === null || $this->category === $variant->category)
            && ($this->store === null || $this->store === $store);
    }

    /** Higher = more specific. Color beats article beats category; a store scope breaks ties. */
    public function specificity(): int
    {
        return ($this->color !== null ? 8 : 0)
            + ($this->article !== null ? 4 : 0)
            + ($this->category !== null ? 2 : 0)
            + ($this->store !== null ? 1 : 0);
    }
}

final class PushDecision
{
    private function __construct(
        public readonly string $status,   // PUSH | HOLD
        public readonly ?int $quantity,
        public readonly string $ruleId,
        public readonly string $reason,
    ) {
    }

    public static function push(int $quantity, string $ruleId, string $reason): self
    {
        return new self('PUSH', $quantity, $ruleId, $reason);
    }

    public static function hold(string $ruleId, string $reason): self
    {
        return new self('HOLD', null, $ruleId, $reason);
    }
}

final class StockPushCalculator
{
    /** @param list<Rule> $rules */
    public function __construct(private readonly Rule $default, private readonly array $rules = [])
    {
    }

    public function ruleFor(Variant $variant, string $store): Rule
    {
        $best = $this->default;
        $bestScore = -1;
        foreach ($this->rules as $rule) {
            if ($rule->matches($variant, $store) && $rule->specificity() > $bestScore) {
                $best = $rule;
                $bestScore = $rule->specificity();
            }
        }

        return $best;
    }

    public function decide(Variant $variant, string $store, int $sellable, int $promoReserved = 0): PushDecision
    {
        $rule = $this->ruleFor($variant, $store);
        $sellable = max(0, $sellable);

        $belowThreshold = $sellable < $rule->zeroBelow
            && !in_array($store, $rule->zeroBelowExceptStores, true);

        $quantity = $belowThreshold
            ? 0
            : intdiv($sellable * $rule->percent, 100) + ($sellable > 0 ? $rule->add : 0);

        if ($promoReserved > $quantity) {
            return PushDecision::hold(
                $rule->id,
                sprintf('campaign reserves %d but only %d would be shown — fix the promo stock first', $promoReserved, $quantity),
            );
        }

        $reason = $belowThreshold
            ? sprintf('sellable %d is below %d → show 0', $sellable, $rule->zeroBelow)
            : sprintf('floor(%d × %d%%) + %d', $sellable, $rule->percent, $sellable > 0 ? $rule->add : 0);

        return PushDecision::push($quantity, $rule->id, $reason);
    }
}
