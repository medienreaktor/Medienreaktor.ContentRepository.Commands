<?php

declare(strict_types=1);

namespace Medienreaktor\ContentRepository\Commands\Tests\Unit\Input;

use Medienreaktor\ContentRepository\Commands\Input\PropertyStringConverter;
use PHPUnit\Framework\TestCase;

final class PropertyStringConverterTest extends TestCase
{
    private PropertyStringConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new PropertyStringConverter();
    }

    public function testAStringIsPassedThroughUntouched(): void
    {
        self::assertSame('  spaced  ', $this->converter->convert('title', '  spaced  ', 'string'));
    }

    /**
     * The whole reason this class exists: under (bool) casting "false" is true, which renders a dash
     * nobody asked for and reads as correct in the file.
     */
    public function testFalseIsFalse(): void
    {
        self::assertFalse($this->converter->convert('showDash', 'false', 'boolean'));
        self::assertFalse($this->converter->convert('showDash', 'FALSE', 'boolean'));
        self::assertFalse($this->converter->convert('showDash', '0', 'boolean'));
        self::assertFalse($this->converter->convert('showDash', 'no', 'boolean'));
        self::assertFalse($this->converter->convert('showDash', 'off', 'boolean'));
    }

    public function testTrueIsTrue(): void
    {
        self::assertTrue($this->converter->convert('showDash', 'true', 'boolean'));
        self::assertTrue($this->converter->convert('showDash', '1', 'boolean'));
        self::assertTrue($this->converter->convert('showDash', 'yes', 'boolean'));
        self::assertTrue($this->converter->convert('showDash', 'on', 'boolean'));
    }

    public function testAnUnreadableBooleanIsRejectedRatherThanGuessed(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Property "showDash" is a boolean, but "maybe" is neither true nor false.');

        $this->converter->convert('showDash', 'maybe', 'boolean');
    }

    public function testAnIntegerIsConverted(): void
    {
        self::assertSame(7, $this->converter->convert('width', '7', 'integer'));
        self::assertSame(-3, $this->converter->convert('offset', ' -3 ', 'int'));
    }

    /**
     * (int) would answer 7 here and 0 for an empty value, and a property quietly holding the wrong
     * number is worse than an import that stops.
     */
    public function testANumberWithATailIsNotAnInteger(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Property "width" is an integer, but "7 columns" is not a whole number.');

        $this->converter->convert('width', '7 columns', 'integer');
    }

    public function testAFloatIsConverted(): void
    {
        self::assertSame(1.5, $this->converter->convert('ratio', '1.5', 'float'));
    }

    public function testAnArrayIsReadAsJson(): void
    {
        self::assertSame(['a', 'b'], $this->converter->convert('tags', '["a","b"]', 'array'));
    }

    public function testAJsonScalarIsNotAnArray(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Property "tags" is an array, but "42" is not one.');

        $this->converter->convert('tags', '42', 'array');
    }

    /**
     * The Content Repository validates a date property against \DateTimeInterface and rejects the
     * ISO string, so every spelling a node type may use has to be recognised here.
     */
    public function testEverySpellingOfADateIsConverted(): void
    {
        foreach (['DateTime', '\DateTime', 'DateTimeImmutable', '\DateTimeImmutable', 'DateTimeInterface', '\DateTimeInterface'] as $declaration) {
            $converted = $this->converter->convert('publishedAt', '2026-08-19 12:00:00', $declaration);

            self::assertInstanceOf(\DateTimeImmutable::class, $converted, $declaration);
            self::assertSame('2026-08-19 12:00:00', $converted->format('Y-m-d H:i:s'), $declaration);
        }
    }

    public function testAnUnreadableDateIsReported(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Property "publishedAt" is a date, but "not a date" could not be read as one');

        $this->converter->convert('publishedAt', 'not a date', 'DateTime');
    }

    /**
     * An asset cannot be written as text, and the message has to say what to do instead — the
     * mistake it follows is writing a file name straight onto the property.
     */
    public function testAnObjectTypeSaysToUseTheManifest(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('declare the file in <manifest> and refer to it by its id');

        $this->converter->convert('image', 'hero.png', 'Neos\Media\Domain\Model\ImageInterface');
    }
}
