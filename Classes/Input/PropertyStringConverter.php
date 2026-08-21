<?php

declare(strict_types=1);

namespace Medienreaktor\ContentRepository\Commands\Input;

use Neos\Flow\Annotations as Flow;

/**
 * Turns the string a property has in a manifest XML file into the type its node type declares.
 *
 * XML carries strings and nothing else, so `showDash="true"` and `width="7"` arrive as text while
 * the Content Repository validates them against boolean and integer and rejects the strings. The
 * node type is the authority on what each one is meant to be, so conversion is driven by the
 * declared type rather than by guessing from the value — `"1"` is a perfectly good string for one
 * property and a boolean for the next.
 *
 * Objects are the exception that cannot be converted here: an asset property holds the asset, which
 * this class has no way to load. Those are resolved by the caller and passed in as already-made
 * values, so what arrives here is only ever the types XML can express.
 */
#[Flow\Scope('singleton')]
final class PropertyStringConverter
{
    /**
     * The declarations the Content Repository reads as "date".
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
     * What counts as true and false in an attribute.
     *
     * Deliberately short and case-insensitive rather than PHP's own idea of truthiness: under
     * (bool) casting, `showDash="false"` is true, which is the sort of thing that renders a dash
     * nobody asked for and takes an afternoon to find.
     */
    private const array TRUE_VALUES = ['true', '1', 'yes', 'on'];
    private const array FALSE_VALUES = ['false', '0', 'no', 'off'];

    /**
     * @param string $propertyName Only used to name the property in a failure
     * @param string $declaredType The node type's declared type for it
     * @throws \RuntimeException if the value does not fit the declared type
     */
    public function convert(string $propertyName, string $value, string $declaredType): mixed
    {
        $normalized = ltrim($declaredType, '\\');

        if (in_array($declaredType, self::DATE_DECLARATIONS, true)) {
            return self::toDate($propertyName, $value);
        }

        return match ($normalized) {
            'boolean', 'bool' => self::toBoolean($propertyName, $value),
            'integer', 'int' => self::toInteger($propertyName, $value),
            'float', 'double' => self::toFloat($propertyName, $value),
            'array' => self::toArray($propertyName, $value),
            'string' => $value,
            default => self::unsupported($propertyName, $value, $declaredType),
        };
    }

    private static function toBoolean(string $propertyName, string $value): bool
    {
        $normalized = strtolower(trim($value));

        if (in_array($normalized, self::TRUE_VALUES, true)) {
            return true;
        }

        if (in_array($normalized, self::FALSE_VALUES, true)) {
            return false;
        }

        throw new \RuntimeException(
            sprintf('Property "%s" is a boolean, but "%s" is neither true nor false. Write one of %s.', $propertyName, $value, implode(', ', [...self::TRUE_VALUES, ...self::FALSE_VALUES])),
            1787097660
        );
    }

    private static function toInteger(string $propertyName, string $value): int
    {
        $trimmed = trim($value);

        // filter_var rather than (int): the cast turns "7 tiles" into 7 and "" into 0, and a
        // property quietly holding the wrong number is worse than a failed import.
        $integer = filter_var($trimmed, FILTER_VALIDATE_INT);

        if ($integer === false) {
            throw new \RuntimeException(
                sprintf('Property "%s" is an integer, but "%s" is not a whole number.', $propertyName, $value),
                1787097661
            );
        }

        return $integer;
    }

    private static function toFloat(string $propertyName, string $value): float
    {
        $float = filter_var(trim($value), FILTER_VALIDATE_FLOAT);

        if ($float === false) {
            throw new \RuntimeException(
                sprintf('Property "%s" is a float, but "%s" is not a number.', $propertyName, $value),
                1787097662
            );
        }

        return $float;
    }

    /**
     * @return array<mixed>
     */
    private static function toArray(string $propertyName, string $value): array
    {
        try {
            $decoded = json_decode(trim($value), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                sprintf('Property "%s" is an array, so it has to be written as JSON: %s', $propertyName, $exception->getMessage()),
                1787097663,
                $exception
            );
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException(
                sprintf('Property "%s" is an array, but "%s" is not one.', $propertyName, $value),
                1787097664
            );
        }

        return $decoded;
    }

    private static function toDate(string $propertyName, string $value): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable(trim($value));
        } catch (\Exception $exception) {
            throw new \RuntimeException(
                sprintf('Property "%s" is a date, but "%s" could not be read as one: %s', $propertyName, $value, $exception->getMessage()),
                1787097665,
                $exception
            );
        }
    }

    /**
     * Anything else is a class or interface the node type declares, which a string cannot express.
     *
     * An asset is the case that comes up, and it has an answer: declare the file in the manifest and
     * refer to it by its id. Naming that here is worth more than the type name alone, because the
     * mistake is usually writing a file name straight onto the property.
     */
    private static function unsupported(string $propertyName, string $value, string $declaredType): never
    {
        throw new \RuntimeException(
            sprintf('Property "%s" is declared as %s, which cannot be written as text ("%s"). If it is an asset, declare the file in <manifest> and refer to it by its id.', $propertyName, $declaredType, $value),
            1787097666
        );
    }
}
