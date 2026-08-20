<?php

declare(strict_types=1);

namespace Medienreaktor\ContentRepository\Commands\Import;

/**
 * What an import did, for the closing summary.
 */
final class ImportReport
{
    public int $assetsImported = 0;
    public int $assetsReused = 0;
    public int $nodesRemoved = 0;
    public int $nodesCreated = 0;
    public int $pagesVisited = 0;
    public int $nodesReconciled = 0;

    /**
     * Things the file asked for that were skipped rather than done.
     *
     * A warning is for what the format cannot express yet, not for what the author got wrong: a
     * misspelled property is an error, because it is a typo and the import should stop. Reaching a
     * limit of the importer should not stop the other 47 nodes from being seeded, but it must not
     * pass in silence either.
     *
     * @var array<int,string>
     */
    public array $warnings = [];
}
