<?php

declare(strict_types=1);

namespace Medienreaktor\ContentRepository\Commands\Tests\Unit\Input;

use Medienreaktor\ContentRepository\Commands\Input\PropertyValuesParser;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PropertyValuesParserTest extends TestCase
{
    private PersistenceManagerInterface&MockObject $persistenceManager;

    private PropertyValuesParser $parser;

    protected function setUp(): void
    {
        $this->persistenceManager = $this->createMock(PersistenceManagerInterface::class);
        $this->parser = new PropertyValuesParser($this->persistenceManager);
    }

    public function testAJsonObjectBecomesTheSameSetOfValues(): void
    {
        $values = $this->parser->parse('{"title":"Ein Titel","uriPathSegment":"ein-titel"}');

        self::assertSame(['title' => 'Ein Titel', 'uriPathSegment' => 'ein-titel'], $values->values);
    }

    public function testAnEmptyObjectIsAllowed(): void
    {
        self::assertSame([], $this->parser->parse('{}')->values);
    }

    /**
     * Only date properties are touched, so every other JSON type has to survive as it arrived —
     * including null, which the Content Repository reads as "unset this property".
     */
    public function testEveryOtherJsonTypeIsPassedThroughUntouched(): void
    {
        $values = $this->parser->parse(
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
        $values = $this->parser->parse('{"publishedAt":"2026-08-19T12:30:00+02:00"}', ['publishedAt' => $declaration]);

        self::assertInstanceOf(\DateTimeImmutable::class, $values->values['publishedAt']);
        self::assertSame('2026-08-19T12:30:00+02:00', $values->values['publishedAt']->format(\DATE_ATOM));
    }

    public function testADateLookingStringStaysAStringWhenItsPropertyIsNotADate(): void
    {
        $values = $this->parser->parse('{"title":"2026-08-19"}', ['title' => 'string']);

        self::assertSame(['title' => '2026-08-19'], $values->values);
    }

    /**
     * A property the caller did not describe is passed through, because guessing from the shape of
     * the value is how a title of "2026" ends up as a date.
     */
    public function testAnUndeclaredPropertyIsPassedThrough(): void
    {
        $values = $this->parser->parse('{"publishedAt":"2026-08-19"}');

        self::assertSame(['publishedAt' => '2026-08-19'], $values->values);
    }

    public function testANonStringValueForADatePropertyIsLeftToTheContentRepository(): void
    {
        $values = $this->parser->parse('{"publishedAt":null}', ['publishedAt' => 'DateTime']);

        self::assertSame(['publishedAt' => null], $values->values);
    }

    public function testAnUnreadableDateNamesTheProperty(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Property "publishedAt" is a date, but "not a date" could not be read as one');

        $this->parser->parse('{"publishedAt":"not a date"}', ['publishedAt' => 'DateTime']);
    }

    public function testMalformedJsonNamesThePayload(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to JSON-decode the property values "{oops"');

        $this->parser->parse('{oops');
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

        $this->parser->parse($json);
    }

    /**
     * The reference form the Content Repository itself writes when it serializes an entity
     * property. It carries the concrete class because the declared type is usually an interface,
     * which Doctrine cannot look anything up by.
     */
    public function testAReferenceIsResolvedToTheObjectItNames(): void
    {
        $asset = new \stdClass();
        $this->persistenceManager->expects(self::once())
            ->method('getObjectByIdentifier')
            ->with('4711', 'Neos\\Media\\Domain\\Model\\Image')
            ->willReturn($asset);

        $values = $this->parser->parse(
            '{"image":{"__flow_object_type":"Neos\\\\Media\\\\Domain\\\\Model\\\\Image","__identifier":"4711"}}',
            ['image' => 'Neos\\Media\\Domain\\Model\\ImageInterface']
        );

        self::assertSame($asset, $values->values['image']);
    }

    /**
     * A reference is recognised by its shape rather than by the property type, because the type is
     * an interface as often as not — but then an ordinary array property must not be mistaken for
     * one, so both keys are required.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function arraysThatAreNotAReference(): iterable
    {
        yield 'only the type' => ['{"data":{"__flow_object_type":"Some\\\\Class"}}'];
        yield 'only the identifier' => ['{"data":{"__identifier":"4711"}}'];
        yield 'neither' => ['{"data":{"a":1}}'];
    }

    #[DataProvider('arraysThatAreNotAReference')]
    public function testAnArrayMissingEitherKeyStaysAnArray(string $json): void
    {
        $this->persistenceManager->expects(self::never())->method('getObjectByIdentifier');

        self::assertIsArray($this->parser->parse($json, ['data' => 'array'])->values['data']);
    }

    /**
     * The Content Repository reads null as "unset this property", so a reference to something that
     * has been deleted would quietly blank the property instead of failing.
     */
    public function testAReferenceToSomethingThatNoLongerExistsIsRejected(): void
    {
        $this->persistenceManager->method('getObjectByIdentifier')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Property "image" references the Some\Class "4711", which does not exist.');

        $this->parser->parse('{"image":{"__flow_object_type":"Some\\\\Class","__identifier":"4711"}}');
    }

    /**
     * Naming an interface is the mistake to expect, and Doctrine reports it by throwing on lookup.
     */
    public function testAFailedLookupIsReportedAgainstTheProperty(): void
    {
        $this->persistenceManager->method('getObjectByIdentifier')
            ->willThrowException(new \RuntimeException('Class "X" is not a valid entity or mapped super class.'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Property "image" references a Some\Interface, which could not be looked up');

        $this->parser->parse('{"image":{"__flow_object_type":"Some\\\\Interface","__identifier":"4711"}}');
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function incompleteReferences(): iterable
    {
        yield 'empty type' => ['{"image":{"__flow_object_type":"","__identifier":"4711"}}'];
        yield 'empty identifier' => ['{"image":{"__flow_object_type":"Some\\\\Class","__identifier":""}}'];
        yield 'null type' => ['{"image":{"__flow_object_type":null,"__identifier":"4711"}}'];
        yield 'numeric identifier' => ['{"image":{"__flow_object_type":"Some\\\\Class","__identifier":4711}}'];
    }

    #[DataProvider('incompleteReferences')]
    public function testAReferenceMissingEitherValueIsRejected(string $json): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('both have to be non-empty strings');

        $this->parser->parse($json);
    }
}
