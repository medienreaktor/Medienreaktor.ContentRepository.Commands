<?php

declare(strict_types=1);

namespace Medienreaktor\ContentRepository\Commands\Xml;

/**
 * One node in a manifest, as written — not yet checked against a node type.
 *
 * Properties arrive as strings whatever their eventual type, because that is all XML carries; the
 * importer converts them once it knows the node type. $tetheredName is set only where the file
 * names a tethered node explicitly (crm:name), which is needed where a node type has more than one
 * tethered child and the target would otherwise be a guess.
 *
 * $line is the line the element starts on, so that a failure names a place in the file rather than
 * a position in a tree the author cannot see.
 */
final readonly class ParsedNode
{
    /**
     * @param array<string,string> $properties
     * @param array<int,self> $children
     */
    public function __construct(
        public string $nodeTypeName,
        public ?string $tetheredName,
        public array $properties,
        public array $children,
        public int $line,
    ) {
    }
}
