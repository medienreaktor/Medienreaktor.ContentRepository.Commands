<?php

declare(strict_types=1);

namespace Medienreaktor\ContentRepository\Commands\Command;

use Medienreaktor\ContentRepository\Commands\Import\XmlSeedImporter;
use Medienreaktor\ContentRepository\Commands\Input\PropertyValuesParser;
use Medienreaktor\ContentRepository\Commands\Input\VariantSelectionStrategyParser;
use Medienreaktor\ContentRepository\Commands\Media\AssetImporter;
use Medienreaktor\ContentRepository\Commands\Xml\SeedXmlParser;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeRemoval\Command\RemoveNodeAggregate;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\AbsoluteNodePath;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeVariantSelectionStrategy;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Utility\TypeHandling;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The Content Repository Command Controller
 *
 * The write commands each build one Content Repository command and hand it over; the Content
 * Repository validates it and emits the resulting event. The find commands read the graph. None of
 * those prompts for confirmation or offers a dry run, because they are scripting primitives meant
 * to be composed in seed and import scripts.
 *
 * cr:importxml is the exception, and deliberately so: it takes a file describing a whole content
 * tree rather than a single operation, so there is a real question of whether that file reads, and
 * --dry-run answers it.
 *
 * **The output contract: stdout carries data, stderr carries everything else.** A command that has
 * a result — the ID of a created node, the IDs a query matched — writes it to stdout, one value per
 * line, with no markup and nothing else. Progress and errors go to stderr. So a caller captures a
 * value directly and never parses:
 *
 *     ID=$(./flow cr:createnodeaggregate ...)
 *
 * That contract is why the write commands do not take a node aggregate ID: creation reports the one
 * it minted, which is the same information without a second way to get it wrong. It also means any
 * future line written to stdout breaks every caller, so new output belongs on stderr unless it is
 * the command's result.
 *
 * Two things worth knowing before pointing these at anything but a seed:
 *
 * - They write to whichever workspace they are told to. Removal in particular is a *hard* removal
 *   via RemoveNodeAggregate, which Neos itself only issues on "live" — the UI tags a subtree as
 *   removed instead, so that the change can still be published or discarded. A hard removal in a
 *   user workspace cannot be discarded.
 * - Failures are reported as a single error line, so a caller reads a sentence rather than a stack
 *   trace. Reaching for the trace means reproducing the command in a debugger.
 */
#[Flow\Scope('singleton')]
final class CrCommandController extends CommandController
{
    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected PropertyValuesParser $propertyValuesParser;

    #[Flow\Inject]
    protected AssetImporter $assetImporter;

    #[Flow\Inject]
    protected SeedXmlParser $seedXmlParser;

    #[Flow\Inject]
    protected XmlSeedImporter $xmlSeedImporter;

    /**
     * Create node aggregate
     *
     * Prints the ID of the created node aggregate to stdout, so that children can be hung off it:
     *
     *     GRID=$(./flow cr:createnodeaggregate ... --property-values='{}')
     *
     * @param string $contentRepository Identifier of the Content Repository
     * @param string $workspaceName The workspace in which the create operation is to be performed
     * @param string $originDimensionSpacePoint The dimension space point in which the new node should be created
     * @param string $nodeTypeName Name of the node type of the new node
     * @param string $parentNodeId The identifier of the node aggregate underneath which the new node is added
     * @param string $propertyValues The property key/value pairs to write to the new node
     */
    public function createNodeAggregateCommand(
        string $contentRepository,
        string $workspaceName,
        string $originDimensionSpacePoint,
        string $nodeTypeName,
        string $parentNodeId,
        string $propertyValues
    ): void {
        try {
            $cr = $this->contentRepositoryRegistry->get(ContentRepositoryId::fromString($contentRepository));
            $nodeType = $cr->getNodeTypeManager()->getNodeType(NodeTypeName::fromString($nodeTypeName));
            $nodeAggregateId = NodeAggregateId::create();

            $cr->handle(CreateNodeAggregateWithNode::create(
                workspaceName: WorkspaceName::fromString($workspaceName),
                nodeAggregateId: $nodeAggregateId,
                nodeTypeName: NodeTypeName::fromString($nodeTypeName),
                originDimensionSpacePoint: OriginDimensionSpacePoint::fromJsonString($originDimensionSpacePoint),
                parentNodeAggregateId: NodeAggregateId::fromString($parentNodeId),
                initialPropertyValues: $this->propertyValuesParser->parse($propertyValues, self::propertyTypesOf($nodeType))
            ));

            $this->outputMessage('<success>Created node %s of type %s in workspace %s.</success>', [$nodeAggregateId->value, $nodeTypeName, $workspaceName]);
            $this->outputResult($nodeAggregateId->value);
        } catch (\Exception $exception) {
            $this->fail($exception);
        }
    }

    /**
     * Set node properties
     *
     * @param string $contentRepository Identifier of the Content Repository
     * @param string $workspaceName The workspace in which the set properties operation is to be performed
     * @param string $nodeAggregateId The identifier of the node aggregate to set the properties for
     * @param string $originDimensionSpacePoint The dimension space point the properties should be changed in
     * @param string $propertyValues The property key/value pairs to set
     */
    public function setNodePropertiesCommand(
        string $contentRepository,
        string $workspaceName,
        string $nodeAggregateId,
        string $originDimensionSpacePoint,
        string $propertyValues
    ): void {
        try {
            $cr = $this->contentRepositoryRegistry->get(ContentRepositoryId::fromString($contentRepository));

            // The node type is only needed to recognise date properties, and the aggregate may not
            // exist at all — in which case handling reports that, which is the more useful error.
            $nodeAggregate = $cr->getContentGraph(WorkspaceName::fromString($workspaceName))
                ->findNodeAggregateById(NodeAggregateId::fromString($nodeAggregateId));
            $nodeType = $nodeAggregate !== null
                ? $cr->getNodeTypeManager()->getNodeType($nodeAggregate->nodeTypeName)
                : null;

            $cr->handle(SetNodeProperties::create(
                workspaceName: WorkspaceName::fromString($workspaceName),
                nodeAggregateId: NodeAggregateId::fromString($nodeAggregateId),
                originDimensionSpacePoint: OriginDimensionSpacePoint::fromJsonString($originDimensionSpacePoint),
                propertyValues: $this->propertyValuesParser->parse($propertyValues, self::propertyTypesOf($nodeType))
            ));

            $this->outputMessage('<success>Set node properties of node %s in workspace %s.</success>', [$nodeAggregateId, $workspaceName]);
        } catch (\Exception $exception) {
            $this->fail($exception);
        }
    }

    /**
     * Remove node aggregate
     *
     * This is a hard removal: the node is gone from the workspace it is removed in, rather than
     * being tagged as removed the way the Neos UI does it. Prefer the UI for editorial deletions.
     *
     * @param string $contentRepository Identifier of the Content Repository
     * @param string $workspaceName The workspace in which the remove operation is to be performed
     * @param string $nodeAggregateId The identifier of the node aggregate to remove
     * @param string $coveredDimensionSpacePoint The dimension space point the node should be removed in
     * @param string $nodeVariantSelectionStrategy Which further dimension space points to remove in: allVariants (default, every point the aggregate covers) or allSpecializations (the given point and everything more specific, as the Neos UI does it)
     */
    public function removeNodeAggregateCommand(
        string $contentRepository,
        string $workspaceName,
        string $nodeAggregateId,
        string $coveredDimensionSpacePoint,
        string $nodeVariantSelectionStrategy = NodeVariantSelectionStrategy::STRATEGY_ALL_VARIANTS->value
    ): void {
        try {
            $cr = $this->contentRepositoryRegistry->get(ContentRepositoryId::fromString($contentRepository));

            $cr->handle(RemoveNodeAggregate::create(
                workspaceName: WorkspaceName::fromString($workspaceName),
                nodeAggregateId: NodeAggregateId::fromString($nodeAggregateId),
                coveredDimensionSpacePoint: DimensionSpacePoint::fromJsonString($coveredDimensionSpacePoint),
                nodeVariantSelectionStrategy: VariantSelectionStrategyParser::parse($nodeVariantSelectionStrategy)
            ));

            $this->outputMessage('<success>Removed node %s in workspace %s.</success>', [$nodeAggregateId, $workspaceName]);
        } catch (\Exception $exception) {
            $this->fail($exception);
        }
    }

    /**
     * Import a file into the media library
     *
     * A node property declared as an asset holds the asset itself, so a script that seeds such a
     * node needs the file in the media library first. Neos has no command for that — media:importresources
     * picks up resources Flow already knows about, which is the second half of the job, not this one.
     *
     * Prints the asset identifier to stdout, or with --reference the property value that carries
     * it, ready to be dropped into the JSON that cr:createnodeaggregate takes:
     *
     *     IMAGE=$(./flow cr:importasset --file hero.png --reference)
     *     ./flow cr:createnodeaggregate ... --property-values="{\"image\":$IMAGE}"
     *
     * Importing the same file twice returns the asset from the first time rather than a second
     * copy of it, so re-running a seed script leaves the media library as it was. Sameness is the
     * SHA-1 of the content, which is what the media library itself deduplicates on: a renamed copy
     * of a file already imported is the same asset, and an edited one is a new asset.
     *
     * @param string $file Path to the file to import, absolute or relative to the current directory, or an http(s) URL
     * @param string|null $title Title of the asset, as it appears in the media browser. Defaults to the file name.
     * @param bool $reference Print the property value {"__flow_object_type": …, "__identifier": …} instead of the bare identifier
     */
    public function importAssetCommand(string $file, ?string $title = null, bool $reference = false): void
    {
        try {
            $result = $this->assetImporter->import($file, $title);
            $asset = $result['asset'];

            if ($result['reused']) {
                $this->outputMessage('<success>Reused the asset already imported from %s.</success>', [$file]);
            } else {
                $this->assetImporter->persist();
                $this->outputMessage('<success>Imported %s as %s.</success>', [$file, TypeHandling::getTypeForValue($asset)]);
            }

            $identifier = $this->assetImporter->identifierOf($asset);
            $this->outputResult($reference ? self::referenceTo($asset, $identifier) : $identifier);
        } catch (\Exception $exception) {
            $this->fail($exception);
        }
    }

    /**
     * Import a content tree from a seed XML file
     *
     * The file describes the tree it wants to exist, with node types as element names, and the
     * import makes the graph match it:
     *
     *     ./flow cr:importxml --file seed/LandingPage.xml
     *
     * See {@see \Medienreaktor\ContentRepository\Commands\Xml\SeedXmlParser} for the format and
     * {@see \Medienreaktor\ContentRepository\Commands\Import\XmlSeedImporter} for what the import
     * does at each level. **The file is the whole truth about what it describes.** Running it on two
     * instances leaves them with the same tree whatever state they were in: content is rebuilt, a
     * collection the file says nothing about is emptied, and a matched node's properties are brought
     * to exactly what the file gives them — so dropping a property from the XML unsets it rather
     * than leaving the old value behind. **An editor's changes under a seeded page are therefore
     * lost**, which is the trade a seed makes and the reason not to point this at a site being
     * worked on.
     *
     * A document named by a page path is matched rather than created, since in Neos 9 the site node
     * is itself a document and recreating it would take the site with it. Its properties are still
     * written, which is how a site's own settings get seeded.
     *
     * A property the node type does not declare is an error, because it is a typo. A property that
     * is really a reference is a warning: Neos 9 keeps references separate from properties and this
     * format cannot set them yet, which is the importer's limit rather than the file's mistake.
     *
     * Unlike the other commands here this one takes a --dry-run, because it is a whole file rather
     * than a single operation and there is a real question of whether it will read. A dry run walks
     * the whole tree, resolving every node type and property and checking that each asset reference
     * is declared and each manifest file is there, and writes nothing. What it cannot check is the
     * Content Repository's own constraints — whether this node type may sit under that one is
     * answered by handling the command, and a dry run issues none. It is a proofread, not a
     * rehearsal.
     *
     * @param string $file Path to the seed XML file
     * @param string $workspaceName The workspace to write to
     * @param bool $dryRun Report what the import would do, and write nothing
     */
    public function importXmlCommand(string $file, string $workspaceName = 'live', bool $dryRun = false): void
    {
        try {
            $site = $this->seedXmlParser->parseFile($file);

            $this->outputMessage(
                '<success>%s: site "%s", content repository "%s", dimension %s, %d page(s).</success>',
                [
                    $dryRun ? 'Would import' : 'Importing',
                    $site->siteNodeName,
                    $site->contentRepositoryId,
                    $site->dimensionSpacePoint === [] ? '(none)' : json_encode($site->dimensionSpacePoint, JSON_THROW_ON_ERROR),
                    count($site->pages),
                ]
            );

            $report = $this->xmlSeedImporter->import(
                $site,
                $workspaceName,
                // A relative href in the manifest is relative to the file that wrote it, not to
                // wherever the command happens to be run from.
                dirname((string)realpath($file)),
                function (string $message): void {
                    $this->outputMessage('%s', [$message]);
                },
                $dryRun,
            );

            foreach ($report->warnings as $warning) {
                $this->outputMessage('<comment>Warning:</comment> %s', [$warning]);
            }

            $warnings = $report->warnings === []
                ? ''
                : sprintf(' %d warning(s).', count($report->warnings));

            if ($dryRun) {
                $this->outputMessage(
                    '<success>Checked %d node(s) in %d page(s). Nothing was written; the content already there was not read, so no removal count is given.</success>%s',
                    [$report->nodesCreated, $report->pagesVisited, $warnings]
                );

                return;
            }

            $this->outputMessage(
                '<success>Created %d node(s) in %d page(s), reconciled %d matched node(s), removed %d, assets: %d imported, %d reused.</success>%s',
                [
                    $report->nodesCreated,
                    $report->pagesVisited,
                    $report->nodesReconciled,
                    $report->nodesRemoved,
                    $report->assetsImported,
                    $report->assetsReused,
                    $warnings,
                ]
            );
        } catch (\Exception $exception) {
            $this->fail($exception);
        }
    }

    /**
     * Find a node aggregate by its absolute path
     *
     * Prints the node aggregate ID to stdout. An absolute path starts at the root node, written as
     * its node type in angle brackets, and continues with node names:
     *
     *     MAIN=$(./flow cr:findnodeaggregate --content-repository default --workspace-name live \
     *       --dimension-space-point '{"language":"de"}' --path '/<Neos.Neos:Sites>/site/main')
     *
     * @param string $contentRepository Identifier of the Content Repository
     * @param string $workspaceName The workspace to look in
     * @param string $dimensionSpacePoint The dimension space point to look in
     * @param string $path The absolute node path, e.g. /<Neos.Neos:Sites>/site/main
     */
    public function findNodeAggregateCommand(
        string $contentRepository,
        string $workspaceName,
        string $dimensionSpacePoint,
        string $path
    ): void {
        try {
            $node = $this->subgraph($contentRepository, $workspaceName, $dimensionSpacePoint)
                ->findNodeByAbsolutePath(AbsoluteNodePath::fromString($path));

            if ($node === null) {
                throw new \RuntimeException(
                    sprintf('No node exists at "%s" in workspace %s.', $path, $workspaceName),
                    1787097604
                );
            }

            $this->outputResult($node->aggregateId->value);
        } catch (\Exception $exception) {
            $this->fail($exception);
        }
    }

    /**
     * Find the child nodes of a node aggregate
     *
     * Prints one node aggregate ID per line to stdout, in the order the children are arranged, and
     * nothing at all when there are none — so a loop over the output simply does not run:
     *
     *     for id in $(./flow cr:findchildnodeaggregates ... --node-aggregate-id "$MAIN"); do ...
     *
     * @param string $contentRepository Identifier of the Content Repository
     * @param string $workspaceName The workspace to look in
     * @param string $dimensionSpacePoint The dimension space point to look in
     * @param string $nodeAggregateId The identifier of the node aggregate whose children to find
     */
    public function findChildNodeAggregatesCommand(
        string $contentRepository,
        string $workspaceName,
        string $dimensionSpacePoint,
        string $nodeAggregateId
    ): void {
        try {
            $children = $this->subgraph($contentRepository, $workspaceName, $dimensionSpacePoint)
                ->findChildNodes(NodeAggregateId::fromString($nodeAggregateId), FindChildNodesFilter::create());

            foreach ($children as $child) {
                $this->outputResult($child->aggregateId->value);
            }
        } catch (\Exception $exception) {
            $this->fail($exception);
        }
    }

    /**
     * The subgraph the find commands read, which is the unfiltered one.
     *
     * createEmpty() rather than withoutRestrictions(): the latter is deprecated, and despite its
     * name it still excludes the "removed" subtree tag. A script clearing a collection has to see
     * a soft-removed node — otherwise it is skipped, survives the rebuild, and the result is not
     * the clean tree the script was written to produce.
     */
    private function subgraph(string $contentRepository, string $workspaceName, string $dimensionSpacePoint): ContentSubgraphInterface
    {
        return $this->contentRepositoryRegistry
            ->get(ContentRepositoryId::fromString($contentRepository))
            ->getContentGraph(WorkspaceName::fromString($workspaceName))
            ->getSubgraph(
                DimensionSpacePoint::fromJsonString($dimensionSpacePoint),
                VisibilityConstraints::createEmpty()
            );
    }

    /**
     * Writes one result value to stdout, unformatted and on its own line.
     *
     * OUTPUT_RAW because a result is data: a value containing angle brackets must not be read as
     * markup, and nothing may be added around it.
     */
    private function outputResult(string $value): void
    {
        $this->output->getOutput()->writeln($value, OutputInterface::OUTPUT_RAW);
    }

    /**
     * Writes a message for the reader to stderr.
     *
     * The error stream carries its own formatter, which has none of Flow's styles registered and
     * prints an unknown tag literally instead of stripping it — so the markup is resolved by the
     * main formatter first. Output that is not a console (a buffer in a test, a redirect through
     * something that collapses the streams) has no separate error stream, and falls back to one.
     *
     * @param array<int,string|int> $arguments
     */
    private function outputMessage(string $message, array $arguments = []): void
    {
        $output = $this->output->getOutput();
        $formatted = $output->getFormatter()->format($arguments === [] ? $message : vsprintf($message, $arguments));

        ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->writeln($formatted);
    }

    /**
     * Reports a failure as one error line on stderr and exits non-zero.
     *
     * Everything a command does sits inside the guarded block, value object construction included:
     * WorkspaceName::fromString('Not A Workspace') and DimensionSpacePoint::fromJsonString('nope')
     * reject bad input just as handling rejects an impossible command, and a caller gains nothing
     * from telling the two apart by whether it got a sentence or a trace.
     */
    private function fail(\Exception $exception): void
    {
        $this->outputMessage('<error>Error:</error> %s', [$exception->getMessage()]);
        $this->quit(1);
    }

    /**
     * The property value that refers to a persisted object, as PropertyValuesParser reads it.
     *
     * getTypeForValue() rather than get_class(), for the same reason the Content Repository's own
     * normalizer uses it: a Doctrine proxy answers get_class() with its generated name, and that
     * name means nothing to a later lookup.
     *
     * @throws \JsonException
     */
    private static function referenceTo(object $object, string $identifier): string
    {
        return json_encode([
            '__flow_object_type' => TypeHandling::getTypeForValue($object),
            '__identifier' => $identifier,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * The declared type of every property of a node type, so that property values can be converted.
     *
     * Falls back to 'string' for a property without a declared type, which is what
     * NodeType::getPropertyType() does.
     *
     * @return array<string,string>
     */
    private static function propertyTypesOf(?NodeType $nodeType): array
    {
        if ($nodeType === null) {
            return [];
        }

        $propertyTypes = [];
        foreach ($nodeType->getProperties() as $propertyName => $configuration) {
            $declaration = is_array($configuration) ? ($configuration['type'] ?? null) : null;
            $propertyTypes[(string)$propertyName] = is_string($declaration) ? $declaration : 'string';
        }

        return $propertyTypes;
    }
}
