<?php

declare(strict_types=1);

namespace Medienreaktor\ContentRepository\Commands\Xml;

/**
 * Which document a <crm:page> means, by its node aggregate id.
 *
 * A path is made of node names, and a document created in the Neos UI has none. Such a document
 * cannot be reached by path at all, so the id is the only way to address it.
 */
final readonly class PageNode
{
    public function __construct(
        public string $nodeAggregateId,
    ) {
    }

    public function describe(): string
    {
        return sprintf('node %s', $this->nodeAggregateId);
    }
}
