<?php

declare(strict_types=1);

namespace Medienreaktor\ContentRepository\Commands\Media;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Media\Domain\Strategy\AssetModelMappingStrategyInterface;

/**
 * Puts a file into the media library, or finds the asset that is already there for it.
 *
 * A node property declared as an asset holds the asset itself, so seeding such a node needs the file
 * imported first. Neos has no service for that: media:importresources picks up resources Flow
 * already knows about, which is the second half of the job.
 *
 * Importing the same file twice returns the asset from the first time rather than a second copy, so
 * re-running a seed leaves the media library as it was. Sameness is the SHA-1 of the content, which
 * is what the media library deduplicates on: a renamed copy of an imported file is the same asset,
 * an edited one is a new asset.
 */
#[Flow\Scope('singleton')]
final class AssetImporter
{
    /**
     * The schemes {@see import()} fetches rather than reads off disk.
     */
    private const array REMOTE_SCHEMES = ['http', 'https'];

    public function __construct(
        private readonly ResourceManager $resourceManager,
        private readonly AssetRepository $assetRepository,
        private readonly AssetModelMappingStrategyInterface $assetModelMappingStrategy,
        private readonly PersistenceManagerInterface $persistenceManager,
    ) {
    }

    /**
     * @param string $href A path, absolute or relative to $baseDirectory, or an http(s) URL
     * @param string|null $title Title as it appears in the media browser; defaults to the file name
     * @param string|null $baseDirectory Directory a relative path is resolved against; defaults to the current one
     * @return array{asset:AssetInterface,reused:bool}
     * @throws \RuntimeException if the file cannot be read or fetched
     */
    public function import(string $href, ?string $title = null, ?string $baseDirectory = null): array
    {
        $isRemote = self::isRemote($href);
        $file = $isRemote ? $this->fetch($href) : self::resolve($href, $baseDirectory);

        try {
            $sha1 = sha1_file($file);
            $existing = $sha1 === false ? null : $this->assetRepository->findOneByResourceSha1($sha1);

            if ($existing instanceof AssetInterface) {
                return ['asset' => $existing, 'reused' => true];
            }

            // importResource() reads the file name off the path, so the asset arrives in the media
            // browser under the name it has on disk.
            $resource = $this->resourceManager->importResource($file);

            if ($isRemote) {
                // A fetched file is named after the temporary copy, which says nothing. The URL's
                // own last segment is what a person would recognise.
                $resource->setFilename(self::filenameOf($href));
            }

            $className = $this->assetModelMappingStrategy->map($resource);

            /** @var AssetInterface $asset */
            $asset = new $className($resource);
            $asset->setTitle($title ?? self::filenameOf($href));

            $this->assetRepository->add($asset);

            return ['asset' => $asset, 'reused' => false];
        } finally {
            if ($isRemote) {
                @unlink($file);
            }
        }
    }

    /**
     * The identifier of an asset, as a property value refers to it.
     */
    public function identifierOf(AssetInterface $asset): string
    {
        return (string)$this->persistenceManager->getIdentifierByObject($asset);
    }

    /**
     * Writes everything imported so far to the database.
     *
     * Each ./flow call is its own process, so an asset a later command references has to be stored
     * before that command starts.
     */
    public function persist(): void
    {
        $this->persistenceManager->persistAll();
    }

    private static function isRemote(string $href): bool
    {
        $scheme = parse_url($href, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), self::REMOTE_SCHEMES, true);
    }

    private static function resolve(string $href, ?string $baseDirectory): string
    {
        $file = self::isAbsolutePath($href) || $baseDirectory === null
            ? $href
            : rtrim($baseDirectory, '/') . '/' . $href;

        if (!is_file($file) || !is_readable($file)) {
            throw new \RuntimeException(sprintf('The file "%s" does not exist or cannot be read.', $file), 1787097650);
        }

        return $file;
    }

    /**
     * Fetches a remote file into a temporary copy, which the caller's finally block removes.
     */
    private function fetch(string $url): string
    {
        $file = tempnam(sys_get_temp_dir(), 'seed-asset-');

        if ($file === false) {
            throw new \RuntimeException('Could not create a temporary file to download into.', 1787097651);
        }

        $contents = @file_get_contents($url, false, stream_context_create([
            'http' => ['timeout' => 30, 'follow_location' => 1, 'max_redirects' => 5],
        ]));

        if ($contents === false || $contents === '') {
            @unlink($file);

            throw new \RuntimeException(sprintf('Could not download "%s".', $url), 1787097652);
        }

        if (file_put_contents($file, $contents) === false) {
            @unlink($file);

            throw new \RuntimeException(sprintf('Could not write the download of "%s" to disk.', $url), 1787097653);
        }

        return $file;
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }

    /**
     * The last path segment of a path or URL, without a query string.
     */
    private static function filenameOf(string $href): string
    {
        $path = parse_url($href, PHP_URL_PATH);

        return basename(is_string($path) && $path !== '' ? $path : $href);
    }
}
