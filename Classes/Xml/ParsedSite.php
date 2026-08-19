<?php

declare(strict_types=1);

namespace Medienreaktor\ContentRepository\Commands\Xml;

/**
 * A whole seed file: which site and dimension it describes, the files it needs, and its pages.
 *
 * @see SeedXmlParser for the format
 */
final readonly class ParsedSite
{
    /**
     * @param array<string,string> $dimensionSpacePoint
     * @param array<int,ParsedAsset> $assets
     * @param array<int,ParsedPage> $pages
     */
    public function __construct(
        public string $siteNodeName,
        public string $contentRepositoryId,
        public array $dimensionSpacePoint,
        public array $assets,
        public array $pages,
    ) {
    }
}
