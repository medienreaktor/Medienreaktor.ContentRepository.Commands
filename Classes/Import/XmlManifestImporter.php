<?php

declare(strict_types=1);

namespace Medienreaktor\ContentRepository\Commands\Import;

use Medienreaktor\ContentRepository\Commands\Input\PropertyStringConverter;
use Medienreaktor\ContentRepository\Commands\Media\AssetImporter;
use Medienreaktor\ContentRepository\Commands\Xml\ParsedAsset;
use Medienreaktor\ContentRepository\Commands\Xml\ParsedManifest;
use Medienreaktor\ContentRepository\Commands\Xml\ParsedNode;
use Medienreaktor\ContentRepository\Commands\Xml\ParsedPage;
use Medienreaktor\ContentRepository\Commands\Xml\ParsedSite;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\Feature\NodeRemoval\Command\RemoveNodeAggregate;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\NodeType\TetheredNodeTypeDefinition;
use Neos\ContentRepository\Core\Projection\ContentGraph\AbsoluteNodePath;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\ContentRepository\Core\SharedModel\Node\NodeVariantSelectionStrategy;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Media\Domain\Model\AssetInterface;

/**
 * Makes the content tree a {@see ParsedManifest} describes exist.
 *
 * The file is the desired state, so the importer's job is to make the graph match it. What that
 * means differs by level, and the level says which without the file having to:
 *
 * - **A document addressed by a page path is matched, never created.** In Neos 9 the site node is
 *   itself a document — the homepage — and `site:create` made it. Recreating it would take the site
 *   with it.
 * - **Content is rebuilt.** The children of every container the file describes are removed and
 *   written again in document order, because a seed that appended would double its content on the
 *   second run and one that merged would need identity the file does not carry. Editor changes under
 *   a seeded collection are therefore lost on import, which is the trade a seed makes.
 *
 * **Where content goes is derived from the node type, never hardcoded.** `main` is not a Neos fact:
 * core declares no tethered node by that name, and each site package repeats the convention for
 * itself. So for a node whose children are content:
 *
 * 1. the node type *is* a content collection — children go directly into it;
 * 2. it has exactly one tethered content collection — children go there;
 * 3. it has several — the file has to say which, with crm:name;
 * 4. it has none — children go directly into it.
 *
 * That covers `main` on every document type without naming it, `content` on a hero, and the columns
 * element whose two collections make a guess a coin toss.
 */
#[Flow\Scope('singleton')]
final class XmlManifestImporter
{
    /**
     * The node type every content collection is one of, and the root the site nodes sit under.
     *
     * Written out rather than taken from Neos.Neos' NodeTypeNameFactory, which would make this
     * package depend on the whole of Neos.Neos for two strings it can spell.
     */
    private const string CONTENT_COLLECTION_NODE_TYPE = 'Neos.Neos:ContentCollection';
    private const string SITES_NODE_TYPE = 'Neos.Neos:Sites';

    public function __construct(
        private readonly ContentRepositoryRegistry $contentRepositoryRegistry,
        private readonly AssetImporter $assetImporter,
        private readonly PropertyStringConverter $propertyStringConverter,
    ) {
    }

    /**
     * @param \Closure(string):void $onMessage Called with each progress line
     * @throws \RuntimeException with a message naming the line in the file that caused it
     */
    public function import(
        ParsedManifest $manifest,
        string $workspaceName,
        string $baseDirectory,
        \Closure $onMessage,
        bool $dryRun = false,
    ): ImportReport {
        $report = new ImportReport();

        // Assets first, and independently of any site: the media library is global, and a manifest
        // is allowed to seed it and nothing else.
        $assets = $this->importAssets($manifest->assets, $baseDirectory, $onMessage, $report, $dryRun);
        $declaredAssetIds = [];

        foreach ($manifest->assets as $asset) {
            $declaredAssetIds[$asset->id] = $asset->line;
        }

        $site = $manifest->site;

        if ($site === null) {
            return $report;
        }

        $contentRepository = $this->contentRepositoryRegistry->get(ContentRepositoryId::fromString($site->contentRepositoryId));
        $workspace = WorkspaceName::fromString($workspaceName);
        $dimensionSpacePoint = DimensionSpacePoint::fromArray($site->dimensionSpacePoint);
        $origin = OriginDimensionSpacePoint::fromDimensionSpacePoint($dimensionSpacePoint);

        $subgraph = $contentRepository->getContentGraph($workspace)->getSubgraph(
            $dimensionSpacePoint,
            // createEmpty() rather than the default: a soft-removed node still occupies the
            // collection, and a rebuild that cannot see it leaves it behind.
            VisibilityConstraints::createEmpty()
        );

        foreach ($site->pages as $page) {
            $document = $this->resolveDocument($site, $page, $subgraph);
            $report->pagesVisited++;

            $onMessage(sprintf('Page %s: %s', $page->path, $document->nodeTypeName->value));

            if ($dryRun) {
                $documentNodeType = self::requireNodeType($contentRepository, $document->nodeTypeName, $page->document->line);

                $this->propertyValuesOf($page->document, $documentNodeType, $assets, $declaredAssetIds, true, $report);
                $this->validateChildren($page->document, $documentNodeType, $contentRepository, $declaredAssetIds, $report);

                continue;
            }

            $this->reconcileProperties($page->document, $document, $contentRepository, $workspace, $assets, $declaredAssetIds, $report);

            $this->importChildren(
                $page->document,
                $document,
                $contentRepository,
                $subgraph,
                $workspace,
                $origin,
                $dimensionSpacePoint,
                $assets,
                $declaredAssetIds,
                $onMessage,
                $report,
            );
        }

        return $report;
    }

    /**
     * @param array<int,ParsedAsset> $declared
     * @return array<string,AssetInterface>
     */
    private function importAssets(array $declared, string $baseDirectory, \Closure $onMessage, ImportReport $report, bool $dryRun): array
    {
        $assets = [];

        foreach ($declared as $asset) {
            if ($dryRun) {
                // Nothing is imported, but a manifest pointing at a file that is not there is worth
                // knowing about now rather than halfway through a real run. A URL is left alone —
                // checking it would mean fetching it, which a dry run should not do.
                if (!str_contains($asset->href, '://')) {
                    $file = str_starts_with($asset->href, '/') ? $asset->href : rtrim($baseDirectory, '/') . '/' . $asset->href;

                    if (!is_file($file) || !is_readable($file)) {
                        throw new \RuntimeException(
                            sprintf('Line %d: the asset "%s" points at "%s", which does not exist or cannot be read.', $asset->line, $asset->id, $file),
                            1787097670
                        );
                    }
                }

                continue;
            }

            try {
                $result = $this->assetImporter->import($asset->href, $asset->title, $baseDirectory);
            } catch (\Exception $exception) {
                throw new \RuntimeException(
                    sprintf('Line %d: the asset "%s" could not be imported: %s', $asset->line, $asset->id, $exception->getMessage()),
                    1787097671,
                    $exception
                );
            }

            $assets[$asset->id] = $result['asset'];
            $result['reused'] ? $report->assetsReused++ : $report->assetsImported++;
        }

        if (!$dryRun && $declared !== []) {
            // The nodes referencing these assets are written in this same process, but the
            // Content Repository serializes a reference by identifier and the object has to be
            // findable under it.
            $this->assetImporter->persist();
            $onMessage(sprintf('Assets: %d imported, %d reused.', $report->assetsImported, $report->assetsReused));
        }

        return $assets;
    }

    /**
     * The document a page path names, which has to be there already.
     */
    private function resolveDocument(ParsedSite $site, ParsedPage $page, ContentSubgraphInterface $subgraph): Node
    {
        $path = self::absolutePathOf($site->siteNodeName, $page->path);
        $document = $subgraph->findNodeByAbsolutePath(AbsoluteNodePath::fromString($path));

        if ($document === null) {
            throw new \RuntimeException(
                sprintf('Line %d: no document exists at "%s" (%s in workspace %s). A seed fills a page in; it does not create one.', $page->line, $page->path, $path, $subgraph->getWorkspaceName()->value),
                1787097672
            );
        }

        // The file names the document's node type, so a file written for a different page — or a
        // site created with another type than the file assumes — is caught before anything is
        // removed, rather than after.
        if ($document->nodeTypeName->value !== $page->document->nodeTypeName) {
            throw new \RuntimeException(
                sprintf('Line %d: the document at "%s" is a %s, but the file describes a %s.', $page->document->line, $page->path, $document->nodeTypeName->value, $page->document->nodeTypeName),
                1787097673
            );
        }

        return $document;
    }

    /**
     * Writes the children of one parsed node under the node that stands for it.
     *
     * @param array<string,AssetInterface> $assets
     * @param array<string,int> $declaredAssetIds
     */
    private function importChildren(
        ParsedNode $parsed,
        Node $node,
        ContentRepository $contentRepository,
        ContentSubgraphInterface $subgraph,
        WorkspaceName $workspace,
        OriginDimensionSpacePoint $origin,
        DimensionSpacePoint $dimensionSpacePoint,
        array $assets,
        array $declaredAssetIds,
        \Closure $onMessage,
        ImportReport $report,
    ): void {
        $nodeType = self::requireNodeType($contentRepository, $node->nodeTypeName, $parsed->line);

        [$tethered, $content] = self::partitionChildren($parsed->children);

        // Which content collections the file spoke about. Whatever is left over is emptied below:
        // the file is the whole truth about this node, so a collection it says nothing about holds
        // nothing, and an import onto a populated instance ends up where a fresh one does.
        $addressed = [];

        foreach ($tethered as $child) {
            $definition = self::tetheredDefinitionFor($child, $nodeType, $node->nodeTypeName->value);
            $target = $this->requireChildByName($node, $definition->name, $subgraph, $child->line);
            $addressed[$definition->name->value] = true;

            $this->reconcileProperties($child, $target, $contentRepository, $workspace, $assets, $declaredAssetIds, $report);
            $this->importChildren($child, $target, $contentRepository, $subgraph, $workspace, $origin, $dimensionSpacePoint, $assets, $declaredAssetIds, $onMessage, $report);
        }

        if ($content !== []) {
            $collection = $this->contentCollectionOf($parsed, $nodeType, $contentRepository);
            $container = $collection === null ? $node : $this->requireChildByName($node, $collection->name, $subgraph, $parsed->line);

            if ($collection !== null) {
                $addressed[$collection->name->value] = true;
            }

            $this->removeChildrenOf($container, $subgraph, $contentRepository, $workspace, $dimensionSpacePoint, $report);

            foreach ($content as $child) {
                $this->create($child, $container, $contentRepository, $subgraph, $workspace, $origin, $dimensionSpacePoint, $assets, $declaredAssetIds, $onMessage, $report);
            }
        } elseif ($nodeType->isOfType(self::CONTENT_COLLECTION_NODE_TYPE)) {
            // The node is a collection and the file gives it nothing, so it holds nothing.
            $this->removeChildrenOf($node, $subgraph, $contentRepository, $workspace, $dimensionSpacePoint, $report);
        }

        // contentCollectionOf() is deliberately not consulted here: it refuses to choose between
        // several collections, and emptying does not have to choose -- every one of them that the
        // file left unmentioned is cleared.
        foreach ($nodeType->tetheredNodeTypeDefinitions as $definition) {
            if (isset($addressed[$definition->name->value])) {
                continue;
            }

            $tetheredNodeType = $contentRepository->getNodeTypeManager()->getNodeType($definition->nodeTypeName);

            if ($tetheredNodeType?->isOfType(self::CONTENT_COLLECTION_NODE_TYPE) !== true) {
                continue;
            }

            $this->removeChildrenOf(
                $this->requireChildByName($node, $definition->name, $subgraph, $parsed->line),
                $subgraph,
                $contentRepository,
                $workspace,
                $dimensionSpacePoint,
                $report
            );
        }
    }

    /**
     * Creates one node and, recursively, everything beneath it.
     *
     * @param array<string,AssetInterface> $assets
     * @param array<string,int> $declaredAssetIds
     */
    private function create(
        ParsedNode $parsed,
        Node $parent,
        ContentRepository $contentRepository,
        ContentSubgraphInterface $subgraph,
        WorkspaceName $workspace,
        OriginDimensionSpacePoint $origin,
        DimensionSpacePoint $dimensionSpacePoint,
        array $assets,
        array $declaredAssetIds,
        \Closure $onMessage,
        ImportReport $report,
    ): void {
        $nodeTypeName = NodeTypeName::fromString($parsed->nodeTypeName);
        $nodeType = self::requireNodeType($contentRepository, $nodeTypeName, $parsed->line);
        $propertyValues = PropertyValuesToWrite::fromArray($this->propertyValuesOf($parsed, $nodeType, $assets, $declaredAssetIds, false, $report));
        $nodeAggregateId = NodeAggregateId::create();

        try {
            $contentRepository->handle(CreateNodeAggregateWithNode::create(
                workspaceName: $workspace,
                nodeAggregateId: $nodeAggregateId,
                nodeTypeName: $nodeTypeName,
                originDimensionSpacePoint: $origin,
                parentNodeAggregateId: $parent->aggregateId,
                initialPropertyValues: $propertyValues,
            ));
        } catch (\Exception $exception) {
            // The Content Repository is the authority on whether a node may sit where the file puts
            // it, and its message says why. What it cannot know is where in the file that was.
            throw new \RuntimeException(
                sprintf('Line %d: <%s> could not be created under %s: %s', $parsed->line, $parsed->nodeTypeName, $parent->nodeTypeName->value, $exception->getMessage()),
                1787097674,
                $exception
            );
        }

        $report->nodesCreated++;

        // The subgraph is a read model over the graph as it was, so the node just written has to be
        // read back before anything can be hung off it.
        $node = $subgraph->findNodeById($nodeAggregateId);

        if ($node === null) {
            throw new \RuntimeException(
                sprintf('Line %d: <%s> was created as %s but cannot be read back.', $parsed->line, $parsed->nodeTypeName, $nodeAggregateId->value),
                1787097675
            );
        }

        $this->importChildren($parsed, $node, $contentRepository, $subgraph, $workspace, $origin, $dimensionSpacePoint, $assets, $declaredAssetIds, $onMessage, $report);
    }

    /**
     * Checks one parsed node and everything beneath it without writing anything.
     *
     * This walks the whole tree, because everything it checks — that a node type exists, that a
     * property is declared and its value fits, that an asset reference is declared, that it is clear
     * which collection children belong in — follows from the node types rather than from any node
     * existing. Only the tethered *lookup* needs a real node, and a node type says what the tethered
     * node's type is, which is all the level below needs.
     *
     * What it cannot check is the Content Repository's own constraints: whether this node type may
     * sit under that one is answered by handling the command, and there is no command here. So a
     * clean dry run means the file reads, not that the import will succeed.
     *
     * @param array<string,int> $declaredAssetIds
     */
    private function validateChildren(
        ParsedNode $parsed,
        NodeType $nodeType,
        ContentRepository $contentRepository,
        array $declaredAssetIds,
        ImportReport $report,
    ): void {
        if ($parsed->children === []) {
            return;
        }

        [$tethered, $content] = self::partitionChildren($parsed->children);

        foreach ($tethered as $child) {
            $definition = self::tetheredDefinitionFor($child, $nodeType, $nodeType->name->value);

            $this->validateChildren($child, self::requireNodeType($contentRepository, $definition->nodeTypeName, $child->line), $contentRepository, $declaredAssetIds, $report);
        }

        if ($content === []) {
            return;
        }

        // Resolved for its own sake: this is where the "which collection" question is answered, and
        // an ambiguous node type has to fail in a dry run rather than only in a real one.
        $this->contentCollectionOf($parsed, $nodeType, $contentRepository);

        foreach ($content as $child) {
            $childNodeType = self::requireNodeType($contentRepository, NodeTypeName::fromString($child->nodeTypeName), $child->line);

            $this->propertyValuesOf($child, $childNodeType, [], $declaredAssetIds, true, $report);
            $report->nodesCreated++;

            $this->validateChildren($child, $childNodeType, $contentRepository, $declaredAssetIds, $report);
        }
    }

    /**
     * The tethered content collection the content children of a node belong in, or null for the node
     * itself.
     *
     * `main` is not a Neos fact — core declares no tethered node by that name, and each site package
     * repeats the convention — so this asks the node type rather than assuming a name.
     */
    private function contentCollectionOf(ParsedNode $parsed, NodeType $nodeType, ContentRepository $contentRepository): ?TetheredNodeTypeDefinition
    {
        if ($nodeType->isOfType(self::CONTENT_COLLECTION_NODE_TYPE)) {
            return null;
        }

        $collections = [];

        foreach ($nodeType->tetheredNodeTypeDefinitions as $definition) {
            $tetheredNodeType = $contentRepository->getNodeTypeManager()->getNodeType($definition->nodeTypeName);

            if ($tetheredNodeType?->isOfType(self::CONTENT_COLLECTION_NODE_TYPE) === true) {
                $collections[] = $definition;
            }
        }

        if (count($collections) > 1) {
            $names = array_map(static fn (TetheredNodeTypeDefinition $definition): string => $definition->name->value, $collections);

            throw new \RuntimeException(
                sprintf('Line %d: %s has %d content collections (%s), so the file has to say which one the children go into, with crm:name.', $parsed->line, $parsed->nodeTypeName, count($collections), implode(', ', $names)),
                1787097676
            );
        }

        // Neither a collection nor holding one: the children are simply this node's children, and the
        // Content Repository's constraints decide whether that is allowed.
        return $collections[0] ?? null;
    }

    /**
     * The tethered node a crm:name child stands for.
     */
    private static function tetheredDefinitionFor(ParsedNode $parsed, NodeType $nodeType, string $parentNodeTypeName): TetheredNodeTypeDefinition
    {
        $name = (string)$parsed->tetheredName;
        $definition = $nodeType->tetheredNodeTypeDefinitions->get($name);

        if ($definition === null) {
            $available = array_map(
                static fn (TetheredNodeTypeDefinition $candidate): string => $candidate->name->value,
                iterator_to_array($nodeType->tetheredNodeTypeDefinitions)
            );

            throw new \RuntimeException(
                sprintf('Line %d: %s has no tethered node named "%s"%s.', $parsed->line, $parentNodeTypeName, $name, $available === [] ? ' and none at all' : ', only ' . implode(', ', $available)),
                1787097677
            );
        }

        if ($definition->nodeTypeName->value !== $parsed->nodeTypeName) {
            throw new \RuntimeException(
                sprintf('Line %d: the tethered node "%s" of %s is a %s, but the file writes it as a %s.', $parsed->line, $name, $parentNodeTypeName, $definition->nodeTypeName->value, $parsed->nodeTypeName),
                1787097678
            );
        }

        return $definition;
    }

    private function requireChildByName(Node $node, NodeName $name, ContentSubgraphInterface $subgraph, int $line): Node
    {
        $child = $subgraph->findNodeByPath($name, $node->aggregateId);

        if ($child === null) {
            throw new \RuntimeException(
                sprintf('Line %d: %s declares a tethered node "%s", but the node %s does not have it. Run structureadjustments:fix.', $line, $node->nodeTypeName->value, $name->value, $node->aggregateId->value),
                1787097679
            );
        }

        return $child;
    }

    /**
     * Clears a container, leaving its tethered nodes where they are.
     *
     * A tethered node cannot be removed — the Content Repository rejects it, rightly, since the node
     * type says it is always there. Its *content* is rebuilt when the file describes it.
     */
    private function removeChildrenOf(
        Node $container,
        ContentSubgraphInterface $subgraph,
        ContentRepository $contentRepository,
        WorkspaceName $workspace,
        DimensionSpacePoint $dimensionSpacePoint,
        ImportReport $report,
    ): void {
        foreach ($subgraph->findChildNodes($container->aggregateId, FindChildNodesFilter::create()) as $child) {
            if ($child->classification->isTethered()) {
                continue;
            }

            $contentRepository->handle(RemoveNodeAggregate::create(
                workspaceName: $workspace,
                nodeAggregateId: $child->aggregateId,
                coveredDimensionSpacePoint: $dimensionSpacePoint,
                nodeVariantSelectionStrategy: NodeVariantSelectionStrategy::STRATEGY_ALL_VARIANTS,
            ));

            $report->nodesRemoved++;
        }
    }

    /**
     * The property values of one parsed node, converted to what its node type declares.
     *
     * Two things can go wrong with a property, and they are not the same kind of problem:
     *
     * - **The node type does not declare it at all.** That is a typo, and the import stops. A seed
     *   file is a statement of the desired state, and quietly dropping part of it would mean the
     *   page that comes out is not the page that was asked for.
     * - **It is declared as a reference, not a property.** Neos 9 keeps references separate from
     *   properties — a node type spelling one `type: references` has it normalised out of
     *   `properties` — and this format cannot express them yet. That is a limit of the importer
     *   rather than a mistake in the file, so it warns and carries on with everything else.
     *
     * @param array<string,AssetInterface> $assets
     * @param array<string,int> $declaredAssetIds Manifest id => the line it is declared on
     * @return array<string,mixed>
     */
    private function propertyValuesOf(ParsedNode $parsed, NodeType $nodeType, array $assets, array $declaredAssetIds, bool $dryRun, ImportReport $report): array
    {
        $values = [];

        foreach ($parsed->properties as $propertyName => $raw) {
            if (!$nodeType->hasProperty($propertyName)) {
                if ($nodeType->hasReference($propertyName)) {
                    $this->warn($report, sprintf('Line %d: "%s" of %s is a reference, not a property. This format cannot set references yet, so it was skipped.', $parsed->line, $propertyName, $parsed->nodeTypeName));

                    continue;
                }

                throw new \RuntimeException(
                    sprintf('Line %d: %s has no property "%s".', $parsed->line, $parsed->nodeTypeName, $propertyName),
                    1787097680
                );
            }

            $declaredType = $nodeType->getPropertyType($propertyName);

            // A declared type holding a backslash is a class or interface, so the value names an
            // asset in the manifest rather than being one.
            if (str_contains($declaredType, '\\')) {
                if (!isset($declaredAssetIds[$raw])) {
                    throw new \RuntimeException(
                        sprintf('Line %d: the property "%s" of %s refers to the asset "%s", which is not declared in <manifest>.', $parsed->line, $propertyName, $parsed->nodeTypeName, $raw),
                        1787097682
                    );
                }

                // A dry run imported nothing, so there is no asset to put here — and nothing that
                // would read it either, since no command is built. The reference itself was just
                // checked, which is the part a dry run can answer.
                if (!$dryRun) {
                    $values[$propertyName] = $assets[$raw];
                }

                continue;
            }

            try {
                $values[$propertyName] = $this->propertyStringConverter->convert($propertyName, $raw, $declaredType);
            } catch (\RuntimeException $exception) {
                throw new \RuntimeException(
                    sprintf('Line %d: %s: %s', $parsed->line, $parsed->nodeTypeName, $exception->getMessage()),
                    1787097681,
                    $exception
                );
            }
        }

        return $values;
    }

    /**
     * Records a warning and puts it in front of the reader straight away.
     *
     * Both, because a warning buried in a summary after a hundred lines of progress is a warning
     * nobody reads, and one that only streams past is one nobody can count.
     */
    private function warn(ImportReport $report, string $message): void
    {
        $report->warnings[] = $message;
    }

    /**
     * Brings a matched node's properties to exactly what the file says they are.
     *
     * A node the import creates gets its properties from the file and nothing else, so it is
     * already authoritative. A node the import only *matches* -- a document, a tethered collection
     * -- carries whatever it happened to have, and writing just the properties the file mentions
     * would leave the rest behind: drop a property from the XML and it would still be set, so the
     * same file would mean different things on two instances.
     *
     * So the whole property set is written: what the file gives, and an explicit unset for every
     * other property the node type declares. Two instances therefore end up the same whatever they
     * held before -- a value that was set becomes unset, one that was already unset stays unset.
     *
     * Node type *defaults* are deliberately not written for the properties the file leaves out. It
     * would make a matched node identical to a freshly created one, which is tempting, but it makes
     * the seed hostage to every default being internally consistent -- and they are not. This very
     * project declares `seo.organization.sameAs` as `type: string` with `defaultValue: [ ]`, which
     * the Content Repository rightly refuses to write. A seed should not fail over a default it was
     * never asked to set. The consequence, stated plainly: a created node starts from its node
     * type's defaults, a matched one starts from nothing. Both are determined by the file, which is
     * what the guarantee actually requires.
     *
     * The origin is the node's own, not the seed's: the node already exists, and its properties
     * belong in the dimension it originates in.
     *
     * @param array<string,AssetInterface> $assets
     * @param array<string,int> $declaredAssetIds
     */
    private function reconcileProperties(
        ParsedNode $parsed,
        Node $node,
        ContentRepository $contentRepository,
        WorkspaceName $workspace,
        array $assets,
        array $declaredAssetIds,
        ImportReport $report,
    ): void {
        $nodeType = self::requireNodeType($contentRepository, $node->nodeTypeName, $parsed->line);
        $declared = $nodeType->getProperties();

        if ($declared === []) {
            return;
        }

        $values = array_merge(
            // Declared but unmentioned: cleared, so that removing a line from the file removes it
            // from the node.
            array_fill_keys(array_map('strval', array_keys($declared)), null),
            $this->propertyValuesOf($parsed, $nodeType, $assets, $declaredAssetIds, false, $report),
        );

        try {
            $contentRepository->handle(SetNodeProperties::create(
                workspaceName: $workspace,
                nodeAggregateId: $node->aggregateId,
                originDimensionSpacePoint: $node->originDimensionSpacePoint,
                propertyValues: PropertyValuesToWrite::fromArray($values),
            ));
        } catch (\Exception $exception) {
            throw new \RuntimeException(
                sprintf('Line %d: the properties of <%s> could not be set: %s', $parsed->line, $parsed->nodeTypeName, $exception->getMessage()),
                1787097684,
                $exception
            );
        }

        $report->nodesReconciled++;
    }

    /**
     * @param array<int,ParsedNode> $children
     * @return array{0:array<int,ParsedNode>,1:array<int,ParsedNode>}
     */
    private static function partitionChildren(array $children): array
    {
        $tethered = [];
        $content = [];

        foreach ($children as $child) {
            if ($child->tetheredName !== null) {
                $tethered[] = $child;
                continue;
            }

            $content[] = $child;
        }

        return [$tethered, $content];
    }

    private static function requireNodeType(ContentRepository $contentRepository, NodeTypeName $nodeTypeName, int $line): NodeType
    {
        $nodeType = $contentRepository->getNodeTypeManager()->getNodeType($nodeTypeName);

        if ($nodeType === null) {
            throw new \RuntimeException(
                sprintf('Line %d: the node type %s does not exist.', $line, $nodeTypeName->value),
                1787097683
            );
        }

        return $nodeType;
    }

    /**
     * The absolute node path of a page, from the site node name and the path relative to it.
     */
    private static function absolutePathOf(string $siteNodeName, string $path): string
    {
        $relative = trim($path, '/');
        $base = sprintf('/<%s>/%s', self::SITES_NODE_TYPE, $siteNodeName);

        return $relative === '' ? $base : $base . '/' . $relative;
    }
}
