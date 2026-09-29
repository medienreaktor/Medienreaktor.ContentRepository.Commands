<?php

declare(strict_types=1);

namespace Medienreaktor\ContentRepository\Commands\Xml;

/**
 * One <crm:page> of a manifest: which document it means, and the document itself.
 *
 * A path is relative to the site node, so "/" is the site node — which in Neos 9 is a document in
 * its own right, the homepage. The importer matches this document rather than creating it.
 */
final readonly class ParsedPage
{
    public function __construct(
        public PagePath|PageNode $address,
        public ParsedNode $document,
        public int $line,
    ) {
    }
}
