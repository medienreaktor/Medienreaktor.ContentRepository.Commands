<?php

declare(strict_types=1);

namespace Medienreaktor\ContentRepository\Commands\Xml;

/**
 * One entry of a manifest's <crm:assets> that names an asset the media library already holds,
 * rather than a file to put into it.
 *
 * This ties the manifest to one database, which is the price of reusing an image an editor has
 * already cropped: the crop lives in an image variant, and no file on disk reproduces it.
 */
final readonly class ParsedExistingAsset
{
    public function __construct(
        public string $id,
        public string $identifier,
        public int $line,
    ) {
    }
}
