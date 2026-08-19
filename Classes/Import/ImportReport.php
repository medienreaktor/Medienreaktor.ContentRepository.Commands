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
}
