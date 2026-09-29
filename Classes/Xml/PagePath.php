<?php

declare(strict_types=1);

namespace Medienreaktor\ContentRepository\Commands\Xml;

/**
 * Where a <crm:page> sits, as node names below the site node; "/" is the site node itself.
 */
final readonly class PagePath
{
    public function __construct(
        public string $value,
    ) {
    }

    public function describe(): string
    {
        return sprintf('"%s"', $this->value);
    }
}
