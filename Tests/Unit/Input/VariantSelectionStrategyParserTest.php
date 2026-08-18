<?php

declare(strict_types=1);

namespace Medienreaktor\ContentRepository\Commands\Tests\Unit\Input;

use Medienreaktor\ContentRepository\Commands\Input\VariantSelectionStrategyParser;
use Neos\ContentRepository\Core\SharedModel\Node\NodeVariantSelectionStrategy;
use PHPUnit\Framework\TestCase;

final class VariantSelectionStrategyParserTest extends TestCase
{
    public function testBothStrategiesAreAccepted(): void
    {
        self::assertSame(
            NodeVariantSelectionStrategy::STRATEGY_ALL_VARIANTS,
            VariantSelectionStrategyParser::parse('allVariants')
        );
        self::assertSame(
            NodeVariantSelectionStrategy::STRATEGY_ALL_SPECIALIZATIONS,
            VariantSelectionStrategyParser::parse('allSpecializations')
        );
    }

    /**
     * The spelling is the enum's value, not its case name — "STRATEGY_ALL_VARIANTS" is the first
     * thing someone reading the source will try on the command line.
     */
    public function testTheCaseNameIsNotAcceptedAsASpelling(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        VariantSelectionStrategyParser::parse('STRATEGY_ALL_VARIANTS');
    }

    public function testAnUnknownStrategyListsTheValidOnes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown node variant selection strategy "nonsense". Expected one of: allSpecializations, allVariants.');

        VariantSelectionStrategyParser::parse('nonsense');
    }

    /**
     * Guards the help text: the command documents the two strategies by name, so a third case
     * appearing in the Content Repository should fail here rather than go unmentioned.
     */
    public function testTheKnownStrategiesAreTheOnesDocumented(): void
    {
        self::assertSame(['allSpecializations', 'allVariants'], VariantSelectionStrategyParser::names());
    }
}
