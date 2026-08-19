<?php

declare(strict_types=1);

namespace Medienreaktor\ContentRepository\Commands\Input;

use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;

/**
 * Turns a JSON object of property values from the command line into a writable set.
 *
 * JSON knows six types and the Content Repository knows many more, so a value that arrives as a
 * string is not necessarily meant as one. Two cases actually bite:
 *
 * - **Dates.** The Content Repository validates a date property against \DateTimeInterface and
 *   rejects the ISO string that is the only way to express a date in JSON. So a value whose
 *   property is declared as a date is converted here.
 * - **Persisted objects.** A property declared as an entity — an asset, most often — is validated
 *   against that class, so nothing but the object itself is accepted. An identifier alone cannot
 *   express it, because the declared type is usually an interface (`ImageInterface`) that Doctrine
 *   cannot map to a table. The reference therefore carries the concrete class as well:
 *
 *       {"image": {"__flow_object_type": "Neos\\Media\\Domain\\Model\\Image", "__identifier": "…"}}
 *
 *   which is the same shape the Content Repository itself writes when it serializes such a
 *   property, so a value read out of a node can be fed straight back in.
 *
 * Everything else is passed through untouched — the Content Repository is the authority on whether
 * a value fits, and it reports better than a guess made here would. In particular a bare string is
 * never read as an identifier: which properties are entities is not knowable from the value, and
 * silently turning a string into an object lookup would fail in ways nobody could read.
 */
#[Flow\Scope('singleton')]
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
     * The keys that mark a JSON object as a reference to a persisted object rather than a value.
     *
     * @see \Neos\ContentRepositoryRegistry\Infrastructure\Property\Normalizer\DoctrinePersistentObjectNormalizer
     */
    private const string TYPE_KEY = '__flow_object_type';
    private const string IDENTIFIER_KEY = '__identifier';

    public function __construct(private readonly PersistenceManagerInterface $persistenceManager)
    {
    }

    /**
     * @param array<string,string> $propertyTypes Property name => declared type, for as many properties as are known. Unknown names are passed through.
     * @throws \RuntimeException if the JSON is not an object, a date value cannot be read, or a reference does not resolve
     */
    public function parse(string $json, array $propertyTypes = []): PropertyValuesToWrite
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
            $values[$propertyName] = $this->convert($propertyName, $value, $propertyTypes[$propertyName] ?? null);
        }

        return PropertyValuesToWrite::fromArray($values);
    }

    private function convert(string $propertyName, mixed $value, ?string $propertyType): mixed
    {
        if (self::isReference($value)) {
            /** @var array<string,mixed> $value */
            return $this->resolve($propertyName, $value);
        }

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

    /**
     * Both keys have to be there, so that an ordinary array property carrying one of them by
     * coincidence is still passed through as the array it is.
     */
    private static function isReference(mixed $value): bool
    {
        return is_array($value)
            && array_key_exists(self::TYPE_KEY, $value)
            && array_key_exists(self::IDENTIFIER_KEY, $value);
    }

    /**
     * @param array<string,mixed> $reference
     */
    private function resolve(string $propertyName, array $reference): object
    {
        $type = $reference[self::TYPE_KEY];
        $identifier = $reference[self::IDENTIFIER_KEY];

        if (!is_string($type) || $type === '' || !is_string($identifier) || $identifier === '') {
            throw new \RuntimeException(
                sprintf('Property "%s" is a reference, so "%s" and "%s" both have to be non-empty strings.', $propertyName, self::TYPE_KEY, self::IDENTIFIER_KEY),
                1787097605
            );
        }

        try {
            $object = $this->persistenceManager->getObjectByIdentifier($identifier, $type);
        } catch (\Throwable $exception) {
            // Typically a class that is not a mapped entity, which includes the interface a node
            // type declares — the reference has to name the concrete class behind it.
            throw new \RuntimeException(
                sprintf('Property "%s" references a %s, which could not be looked up: %s', $propertyName, $type, $exception->getMessage()),
                1787097606,
                $exception
            );
        }

        // A missing object would arrive as null, and the Content Repository reads null as "unset
        // this property" — so a reference to something deleted would quietly blank the property.
        if ($object === null) {
            throw new \RuntimeException(
                sprintf('Property "%s" references the %s "%s", which does not exist.', $propertyName, $type, $identifier),
                1787097607
            );
        }

        return $object;
    }
}
