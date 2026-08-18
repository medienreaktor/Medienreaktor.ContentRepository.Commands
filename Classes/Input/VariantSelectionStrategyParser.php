<?php

declare(strict_types=1);

namespace Medienreaktor\ContentRepository\Commands\Input;

use Neos\ContentRepository\Core\SharedModel\Node\NodeVariantSelectionStrategy;

/**
 * Turns the CLI spelling of a variant selection strategy into the enum.
 *
 * The strategy decides which dimension space points a removal reaches beyond the one named on the
 * command line, so getting it wrong deletes content nobody asked about. It is worth spelling out
 * which is which, because the names are close and the difference only shows up in a site that has
 * more than one dimension value:
 *
 *   allSpecializations — the named point and everything more specific than it. This is what the
 *                        Neos UI issues when an editor deletes a node, so it is the conservative
 *                        choice: peer variants ("de" and "fr" alongside each other) survive.
 *   allVariants        — every point the aggregate covers, peers included. Whole-aggregate removal.
 *
 * @see NodeVariantSelectionStrategy for the variation graph these are resolved against
 */
final class VariantSelectionStrategyParser
{
    /**
     * @throws \InvalidArgumentException if the value does not name a strategy
     */
    public static function parse(string $value): NodeVariantSelectionStrategy
    {
        return NodeVariantSelectionStrategy::tryFrom($value) ?? throw new \InvalidArgumentException(
            sprintf(
                'Unknown node variant selection strategy "%s". Expected one of: %s.',
                $value,
                implode(', ', self::names())
            ),
            1787097600
        );
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(
            static fn(NodeVariantSelectionStrategy $strategy): string => $strategy->value,
            NodeVariantSelectionStrategy::cases()
        );
    }
}
