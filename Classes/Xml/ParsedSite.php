<?php

declare(strict_types=1);

namespace Medienreaktor\ContentRepository\Commands\Xml;

/**
 * One <site> of a manifest: which site and dimension it describes, and its pages.
 *
 * Assets are not here — they belong to the {@see ParsedManifest}, because the media library is
 * global rather than per-site.
 *
 * @see ManifestXmlParser for the format
 */
final readonly class ParsedSite
{
    /**
     * @param array<string,string> $dimensionSpacePoint
     * @param array<int,ParsedPage> $pages
     */
    public function __construct(
        public string $siteNodeName,
        public string $contentRepositoryId,
        public array $dimensionSpacePoint,
        public array $pages,
        public int $line,
    ) {
    }
}
