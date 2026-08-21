<?php

declare(strict_types=1);

namespace Medienreaktor\ContentRepository\Commands\Xml;

/**
 * A whole manifest file: the files it wants in the media library, and the site it describes.
 *
 * Assets sit here rather than inside the site because the Neos media library is global. An asset is
 * not owned by a site, and a manifest that only lists assets is a legitimate thing to write — it
 * seeds the media library and no content.
 *
 * $site is nullable for that reason, and singular because the importer handles one site.
 *
 * @see ManifestXmlParser for the format
 */
final readonly class ParsedManifest
{
    /**
     * @param array<int,ParsedAsset> $assets
     */
    public function __construct(
        public array $assets,
        public ?ParsedSite $site,
    ) {
    }
}
