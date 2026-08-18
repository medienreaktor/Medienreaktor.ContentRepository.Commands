<?php

declare(strict_types=1);

namespace Medienreaktor\ContentRepository\Commands\Command;

use Medienreaktor\ContentRepository\Commands\Input\PropertyValuesParser;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeRemoval\Command\RemoveNodeAggregate;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeVariantSelectionStrategy;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;

/**
 * The Content Repository Command Controller
 *
 * Each command builds one Content Repository command and hands it over; the Content Repository
 * validates it and emits the resulting event. Nothing here prompts for confirmation or offers a
 * dry run, because these are scripting primitives meant to be composed in seed and import scripts.
 *
 * Two consequences of that worth knowing before pointing them at anything but a seed:
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

    /**
     * Create node aggregate
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

            $cr->handle(CreateNodeAggregateWithNode::create(
                workspaceName: WorkspaceName::fromString($workspaceName),
                nodeAggregateId: NodeAggregateId::create(),
                nodeTypeName: NodeTypeName::fromString($nodeTypeName),
                originDimensionSpacePoint: OriginDimensionSpacePoint::fromJsonString($originDimensionSpacePoint),
                parentNodeAggregateId: NodeAggregateId::fromString($parentNodeId),
                initialPropertyValues: PropertyValuesParser::parse($propertyValues, self::propertyTypesOf($nodeType))
            ));

            $this->outputLine('<success>Created node of type %s in workspace %s.</success>', [$nodeTypeName, $workspaceName]);
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
                propertyValues: PropertyValuesParser::parse($propertyValues, self::propertyTypesOf($nodeType))
            ));

            $this->outputLine('<success>Set node properties of node %s in workspace %s.</success>', [$nodeAggregateId, $workspaceName]);
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
     */
    public function removeNodeAggregateCommand(
        string $contentRepository,
        string $workspaceName,
        string $nodeAggregateId,
        string $coveredDimensionSpacePoint
    ): void {
        try {
            $cr = $this->contentRepositoryRegistry->get(ContentRepositoryId::fromString($contentRepository));

            $cr->handle(RemoveNodeAggregate::create(
                workspaceName: WorkspaceName::fromString($workspaceName),
                nodeAggregateId: NodeAggregateId::fromString($nodeAggregateId),
                coveredDimensionSpacePoint: DimensionSpacePoint::fromJsonString($coveredDimensionSpacePoint),
                nodeVariantSelectionStrategy: NodeVariantSelectionStrategy::STRATEGY_ALL_VARIANTS
            ));

            $this->outputLine('<success>Removed node %s in workspace %s.</success>', [$nodeAggregateId, $workspaceName]);
        } catch (\Exception $exception) {
            $this->fail($exception);
        }
    }

    /**
     * Reports a failure as one error line and exits non-zero.
     *
     * Everything a command does sits inside the guarded block, value object construction included:
     * WorkspaceName::fromString('Not A Workspace') and DimensionSpacePoint::fromJsonString('nope')
     * reject bad input just as handling rejects an impossible command, and a caller gains nothing
     * from telling the two apart by whether it got a sentence or a trace.
     */
    private function fail(\Exception $exception): void
    {
        $this->outputLine('<error>Error:</error> %s', [$exception->getMessage()]);
        $this->quit(1);
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
