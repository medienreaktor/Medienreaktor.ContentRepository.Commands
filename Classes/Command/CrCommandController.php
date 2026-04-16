<?php
declare(strict_types=1);

namespace Medienreaktor\ContentRepository\Commands\Command;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
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
        $this->contentRepositoryRegistry
            ->get(ContentRepositoryId::fromString($contentRepository))
            ->handle(
                CreateNodeAggregateWithNode::create(
                    workspaceName: WorkspaceName::fromString($workspaceName),
                    nodeAggregateId: NodeAggregateId::create(),
                    nodeTypeName: NodeTypeName::fromString($nodeTypeName),
                    originDimensionSpacePoint: OriginDimensionSpacePoint::fromJsonString($originDimensionSpacePoint),
                    parentNodeAggregateId: NodeAggregateId::fromString($parentNodeId),
                    initialPropertyValues: PropertyValuesToWrite::fromJsonString($propertyValues)
                )
            );

        $this->outputLine(
            '<success>Created node of type %s in workspace %s.</success>',
            [$nodeTypeName, $workspaceName]
        );
    }
}
