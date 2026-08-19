<?php

declare(strict_types=1);

namespace Medienreaktor\ContentRepository\Commands\Xml;

/**
 * One entry of a seed file's <seed:manifest>: a file to have in the media library, under a name the
 * content refers to.
 *
 * The id is local to the seed file. It exists so that content says image="hero" rather than an
 * asset identifier that changes with every fresh database, which is what makes the file portable
 * and reviewable.
 */
final readonly class ParsedAsset
{
    public function __construct(
        public string $id,
        public string $href,
        public ?string $title,
        public int $line,
    ) {
    }
}
