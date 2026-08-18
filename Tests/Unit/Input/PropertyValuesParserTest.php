<?php

declare(strict_types=1);

namespace Medienreaktor\ContentRepository\Commands\Tests\Unit\Input;

use Medienreaktor\ContentRepository\Commands\Input\PropertyValuesParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PropertyValuesParserTest extends TestCase
{
    public function testAJsonObjectBecomesTheSameSetOfValues(): void
    {
        $values = PropertyValuesParser::parse('{"title":"Ein Titel","uriPathSegment":"ein-titel"}');

        self::assertSame(['title' => 'Ein Titel', 'uriPathSegment' => 'ein-titel'], $values->values);
    }

    public function testAnEmptyObjectIsAllowed(): void
    {
        self::assertSame([], PropertyValuesParser::parse('{}')->values);
    }

    /**
     * Only date properties are touched, so every other JSON type has to survive as it arrived —
     * including null, which the Content Repository reads as "unset this property".
     */
    public function testEveryOtherJsonTypeIsPassedThroughUntouched(): void
    {
        $values = PropertyValuesParser::parse(
            '{"text":"hello","count":3,"ratio":1.5,"enabled":true,"cleared":null,"tags":["a","b"],"nested":{"a":1}}',
            ['text' => 'string', 'count' => 'integer', 'tags' => 'array']
        );

        self::assertSame(
            ['text' => 'hello', 'count' => 3, 'ratio' => 1.5, 'enabled' => true, 'cleared' => null, 'tags' => ['a', 'b'], 'nested' => ['a' => 1]],
            $values->values
        );
    }

    /**
     * The declarations PropertyType::tryFromString() reads as a date. All six have to convert, not
     * just "DateTime" — the Content Repository rejects a plain string for any of them.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function dateDeclarations(): iterable
    {
        yield 'DateTime' => ['DateTime'];
        yield 'leading backslash' => ['\DateTime'];
        yield 'DateTimeImmutable' => ['DateTimeImmutable'];
        yield 'leading backslash immutable' => ['\DateTimeImmutable'];
        yield 'DateTimeInterface' => ['DateTimeInterface'];
        yield 'leading backslash interface' => ['\DateTimeInterface'];
    }

    #[DataProvider('dateDeclarations')]
    public function testADatePropertyIsConvertedFromItsIsoString(string $declaration): void
    {
        $values = PropertyValuesParser::parse('{"publishedAt":"2026-08-19T12:30:00+02:00"}', ['publishedAt' => $declaration]);

        self::assertInstanceOf(\DateTimeImmutable::class, $values->values['publishedAt']);
        self::assertSame('2026-08-19T12:30:00+02:00', $values->values['publishedAt']->format(\DATE_ATOM));
    }

    public function testADateLookingStringStaysAStringWhenItsPropertyIsNotADate(): void
    {
        $values = PropertyValuesParser::parse('{"title":"2026-08-19"}', ['title' => 'string']);

        self::assertSame(['title' => '2026-08-19'], $values->values);
    }

    /**
     * A property the caller did not describe is passed through, because guessing from the shape of
     * the value is how a title of "2026" ends up as a date.
     */
    public function testAnUndeclaredPropertyIsPassedThrough(): void
    {
        $values = PropertyValuesParser::parse('{"publishedAt":"2026-08-19"}');

        self::assertSame(['publishedAt' => '2026-08-19'], $values->values);
    }

    public function testANonStringValueForADatePropertyIsLeftToTheContentRepository(): void
    {
        $values = PropertyValuesParser::parse('{"publishedAt":null}', ['publishedAt' => 'DateTime']);

        self::assertSame(['publishedAt' => null], $values->values);
    }

    public function testAnUnreadableDateNamesTheProperty(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Property "publishedAt" is a date, but "not a date" could not be read as one');

        PropertyValuesParser::parse('{"publishedAt":"not a date"}', ['publishedAt' => 'DateTime']);
    }

    public function testMalformedJsonNamesThePayload(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to JSON-decode the property values "{oops"');

        PropertyValuesParser::parse('{oops');
    }

    /**
     * Both would otherwise reach PropertyValuesToWrite::fromArray() and raise a TypeError, which is
     * an Error rather than an Exception — so the command controller could not report it and the
     * caller got a fatal instead of a sentence.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function payloadsThatAreNotAnObject(): iterable
    {
        yield 'array' => ['["a","b"]'];
        yield 'number' => ['5'];
        yield 'string' => ['"a string"'];
        yield 'null' => ['null'];
    }

    #[DataProvider('payloadsThatAreNotAnObject')]
    public function testAPayloadThatIsNotAnObjectIsRejected(string $json): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('have to be a JSON object');

        PropertyValuesParser::parse($json);
    }
}
