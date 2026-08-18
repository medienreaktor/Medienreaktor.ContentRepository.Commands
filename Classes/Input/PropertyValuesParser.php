<?php

declare(strict_types=1);

namespace Medienreaktor\ContentRepository\Commands\Input;

use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;

/**
 * Turns a JSON object of property values from the command line into a writable set.
 *
 * JSON knows six types and the Content Repository knows many more, so a value that arrives as a
 * string is not necessarily meant as one. Dates are the case that actually bites: the Content
 * Repository validates a date property against \DateTimeInterface and rejects the ISO string that
 * is the only way to express a date in JSON. So a value whose property is declared as a date is
 * converted here, and everything else is passed through untouched — the Content Repository is the
 * authority on whether it fits, and it reports better than a guess made here would.
 */
final class PropertyValuesParser
{
    /**
     * The declarations the Content Repository reads as "date".
     *
     * Kept in sync with PropertyType::tryFromString(); the original of this parser only knew
     * "DateTime", so a node type spelling it "DateTimeImmutable" got the raw string and was
     * rejected on handling.
     *
     * @see \Neos\ContentRepository\Core\Infrastructure\Property\PropertyType
     */
    private const array DATE_DECLARATIONS = [
        'DateTime',
        '\DateTime',
        'DateTimeImmutable',
        '\DateTimeImmutable',
        'DateTimeInterface',
        '\DateTimeInterface',
    ];

    /**
     * @param array<string,string> $propertyTypes Property name => declared type, for as many properties as are known. Unknown names are passed through.
     * @throws \RuntimeException if the JSON is not an object, or a date value cannot be read
     */
    public static function parse(string $json, array $propertyTypes = []): PropertyValuesToWrite
    {
        try {
            $rawValues = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                sprintf('Failed to JSON-decode the property values "%s": %s', $json, $exception->getMessage()),
                1787097601,
                $exception
            );
        }

        // Without this, a scalar payload reaches PropertyValuesToWrite::fromArray() and raises a
        // TypeError — which is an Error, not an Exception, so the command controller cannot report it.
        if (!is_array($rawValues) || ($rawValues !== [] && array_is_list($rawValues))) {
            throw new \RuntimeException(
                sprintf('The property values have to be a JSON object of property name => value, got "%s".', $json),
                1787097602
            );
        }

        $values = [];
        foreach ($rawValues as $propertyName => $value) {
            $propertyName = (string)$propertyName;
            $values[$propertyName] = self::convert($propertyName, $value, $propertyTypes[$propertyName] ?? null);
        }

        return PropertyValuesToWrite::fromArray($values);
    }

    private static function convert(string $propertyName, mixed $value, ?string $propertyType): mixed
    {
        if (!is_string($value) || $propertyType === null || !in_array($propertyType, self::DATE_DECLARATIONS, true)) {
            return $value;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $exception) {
            throw new \RuntimeException(
                sprintf('Property "%s" is a date, but "%s" could not be read as one: %s', $propertyName, $value, $exception->getMessage()),
                1787097603,
                $exception
            );
        }
    }
}
