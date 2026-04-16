<?php
declare(strict_types=1);

namespace Medienreaktor\ContentRepository\Commands\Command;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;

/**
 * The Content Repository Command Controller
 *
 * @Flow\Scope("singleton")
 */
class CrCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var ContentRepositoryRegistry
     */
    protected $contentRepositoryRegistry;

    /**
     * Create node aggregate
     *
     * @param string $contentRepository Identifier of the Content Repository
     * @param string $workspaceName The workspace in which the create operation is to be performed
     * @param string $originDimensionSpacePoint The dimension space point in which the new node should be created
     * @param string $nodeTypeName Name of the node type of the new node
     * @param string $parentNodeId The identifier of the node aggregate underneath which the new node is added
     * @param string $propertyValues The property key/value pairs to write to the new node
     * @return void
     */
    public function createNodeAggregateCommand(
        string $contentRepository,
        string $workspaceName,
        string $originDimensionSpacePoint,
        string $nodeTypeName,
        string $parentNodeId,
        string $propertyValues
    ): void
    {
        $cr = $this->contentRepositoryRegistry->get(ContentRepositoryId::fromString($contentRepository));
        $nodeType = $cr->getNodeTypeManager()->getNodeType(NodeTypeName::fromString($nodeTypeName));

        $command = CreateNodeAggregateWithNode::create(
            workspaceName: WorkspaceName::fromString($workspaceName),
            nodeAggregateId: NodeAggregateId::create(),
            nodeTypeName: NodeTypeName::fromString($nodeTypeName),
            originDimensionSpacePoint: OriginDimensionSpacePoint::fromJsonString($originDimensionSpacePoint),
            parentNodeAggregateId: NodeAggregateId::fromString($parentNodeId),
            initialPropertyValues: $this->buildPropertyValuesToWrite($propertyValues, $nodeType)
        );

        try {
            $cr->handle($command);
            $this->outputLine(
                '<success>Created node of type %s in workspace %s.</success>',
                [$nodeTypeName, $workspaceName]
            );
        } catch (\Exception $e) {
            $this->outputLine('<error>Error:</error> %s', [$e->getMessage()]);
            $this->quit(1);
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
     * @return void
     */
    public function setNodePropertiesCommand(
        string $contentRepository,
        string $workspaceName,
        string $nodeAggregateId,
        string $originDimensionSpacePoint,
        string $propertyValues
    ): void
    {
        $cr = $this->contentRepositoryRegistry->get(ContentRepositoryId::fromString($contentRepository));
        $nodeAggregate = $cr->getContentGraph(WorkspaceName::fromString($workspaceName))
            ->findNodeAggregateById(NodeAggregateId::fromString($nodeAggregateId));
        $nodeType = $nodeAggregate !== null
            ? $cr->getNodeTypeManager()->getNodeType($nodeAggregate->nodeTypeName)
            : null;

        $command = SetNodeProperties::create(
            workspaceName: WorkspaceName::fromString($workspaceName),
            nodeAggregateId: NodeAggregateId::fromString($nodeAggregateId),
            originDimensionSpacePoint: OriginDimensionSpacePoint::fromJsonString($originDimensionSpacePoint),
            propertyValues: $this->buildPropertyValuesToWrite($propertyValues, $nodeType)
        );

        try {
            $cr->handle($command);
            $this->outputLine(
                '<success>Set node properties of node %s in workspace %s.</success>',
                [$nodeAggregateId, $workspaceName]
            );
        } catch (\Exception $e) {
            $this->outputLine('<error>Error:</error> %s', [$e->getMessage()]);
            $this->quit(1);
        }
    }

    private function buildPropertyValuesToWrite(string $jsonString, ?NodeType $nodeType): PropertyValuesToWrite
    {
        $rawValues = \json_decode($jsonString, true, 512, JSON_THROW_ON_ERROR);

        if ($nodeType === null) {
            return PropertyValuesToWrite::fromArray($rawValues);
        }

        $convertedValues = [];
        foreach ($rawValues as $propertyName => $value) {
            if (is_string($value) && $nodeType->hasProperty($propertyName)) {
                $propertyType = $nodeType->getPropertyType($propertyName);
                if ($propertyType === 'DateTime') {
                    $value = new \DateTimeImmutable($value);
                }
            }
            $convertedValues[$propertyName] = $value;
        }

        return PropertyValuesToWrite::fromArray($convertedValues);
    }
}
