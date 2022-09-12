<?php

/*
 * This file is part of the Neos.ContentGraph.DoctrineDbalAdapter package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Neos\ContentGraph\DoctrineDbalAdapter\Domain\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DBALException;
use Neos\ContentGraph\DoctrineDbalAdapter\Domain\Projection\GraphProjector;
use Neos\ContentGraph\DoctrineDbalAdapter\Domain\Projection\HierarchyRelationRecord;
use Neos\ContentGraph\DoctrineDbalAdapter\Domain\Projection\NodeRecord;
use Neos\ContentGraph\DoctrineDbalAdapter\Domain\Projection\NodeRelationAnchorPoint;
use Neos\ContentRepository\Infrastructure\DbalClientInterface;
use Neos\ContentRepository\SharedModel\Node\OriginDimensionSpacePoint;
use Neos\ContentRepository\SharedModel\Workspace\ContentStreamIdentifier;
use Neos\ContentRepository\DimensionSpace\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\DimensionSpace\DimensionSpace\DimensionSpacePointSet;
use Neos\ContentRepository\SharedModel\Node\NodeAggregateIdentifier;
use Neos\ContentRepository\SharedModel\Node\NodeName;

/**
 * The read only content graph for use by the {@see GraphProjector}. This is the class for low-level operations
 * within the projector, where implementation details of the graph structure are known.
 *
 * This is NO PUBLIC API in any way.
 */
class ProjectionContentGraph
{
    public function __construct(
        private readonly DbalClientInterface $client
    ) {
    }

    /**
     * @param ContentStreamIdentifier $contentStreamIdentifier
     * @param NodeAggregateIdentifier $childNodeAggregateIdentifier
     * @param OriginDimensionSpacePoint $originDimensionSpacePoint
     * @return NodeRecord|null
     * @throws DBALException
     * @throws \Exception
     */
    public function findParentNode(
        ContentStreamIdentifier $contentStreamIdentifier,
        NodeAggregateIdentifier $childNodeAggregateIdentifier,
        OriginDimensionSpacePoint $originDimensionSpacePoint
    ): ?NodeRecord {
        $params = [
            'contentStreamIdentifier' => (string)$contentStreamIdentifier,
            'childNodeAggregateIdentifier' => (string)$childNodeAggregateIdentifier,
            'originDimensionSpacePointHash' => $originDimensionSpacePoint->hash
        ];
        $nodeRow = $this->getDatabaseConnection()->executeQuery(
            'SELECT p.*, ph.contentstreamidentifier, ph.name FROM neos_contentgraph_node p
 INNER JOIN neos_contentgraph_hierarchyrelation ph ON ph.childnodeanchor = p.relationanchorpoint
 INNER JOIN neos_contentgraph_hierarchyrelation ch ON ch.parentnodeanchor = p.relationanchorpoint
 INNER JOIN neos_contentgraph_node c ON ch.childnodeanchor = c.relationanchorpoint
 WHERE c.nodeaggregateidentifier = :childNodeAggregateIdentifier
 AND c.origindimensionspacepointhash = :originDimensionSpacePointHash
 AND ph.contentstreamidentifier = :contentStreamIdentifier
 AND ch.contentstreamidentifier = :contentStreamIdentifier
 AND ph.dimensionspacepointhash = :originDimensionSpacePointHash
 AND ch.dimensionspacepointhash = :originDimensionSpacePointHash',
            $params
        )->fetchAssociative();

        return $nodeRow ? NodeRecord::fromDatabaseRow($nodeRow) : null;
    }

    /**
     * Resolves a node's parent in another dimension space point
     * @throws DBALException
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    public function findParentNodeRecordByOriginInDimensionSpacePoint(
        ContentStreamIdentifier $contentStreamIdentifier,
        OriginDimensionSpacePoint $originDimensionSpacePoint,
        DimensionSpacePoint $coveredDimensionSpacePoint,
        NodeAggregateIdentifier $childNodeAggregateIdentifier
    ): ?NodeRecord {
        $query = /** @lang MariaDB */
            '
            /**
             * Second, find the node record with the same node aggregate identifier in the selected DSP
             */
            SELECT p.*
            FROM ' . NodeRecord::TABLE_NAME . ' p
            JOIN ' . HierarchyRelationRecord::TABLE_NAME . ' ph ON p.relationanchorpoint = ph.childnodeanchor
            WHERE ph.contentstreamidentifier = :contentStreamIdentifier
            AND ph.dimensionspacepointhash = :coveredDimensionSpacePointHash
            AND p.nodeaggregateidentifier = (
                /**
                 * First, find the origin\'s parent node aggregate identifier
                 */
                SELECT orgp.nodeaggregateidentifier FROM ' . NodeRecord::TABLE_NAME . ' orgp
                    JOIN ' . HierarchyRelationRecord::TABLE_NAME . ' orgh
                        ON orgh.parentnodeanchor = orgp.relationanchorpoint
                    JOIN ' . NodeRecord::TABLE_NAME . ' orgn ON orgn.relationanchorpoint = orgh.childnodeanchor
                WHERE orgh.contentstreamidentifier = :contentStreamIdentifier
                    AND orgh.dimensionspacepointhash = :originDimensionSpacePointHash
                    AND orgn.nodeaggregateidentifier = :childNodeAggregateIdentifier
            )';

        $parameters = [
            'contentStreamIdentifier' => (string)$contentStreamIdentifier,
            'coveredDimensionSpacePointHash' => $coveredDimensionSpacePoint->hash,
            'originDimensionSpacePointHash' => $originDimensionSpacePoint->hash,
            'childNodeAggregateIdentifier' => (string)$childNodeAggregateIdentifier
        ];

        $result = $this->getDatabaseConnection()
            ->executeQuery($query, $parameters)
            ->fetchAssociative();

        return $result ? NodeRecord::fromDatabaseRow($result) : null;
    }

    /**
     * @param ContentStreamIdentifier $contentStreamIdentifier
     * @param NodeAggregateIdentifier $nodeAggregateIdentifier
     * @param DimensionSpacePoint $coveredDimensionSpacePoint
     * @return NodeRecord|null
     * @throws DBALException
     * @throws \Exception
     */
    public function findNodeInAggregate(
        ContentStreamIdentifier $contentStreamIdentifier,
        NodeAggregateIdentifier $nodeAggregateIdentifier,
        DimensionSpacePoint $coveredDimensionSpacePoint
    ): ?NodeRecord {
        $nodeRow = $this->getDatabaseConnection()->executeQuery(
            'SELECT n.*, h.name FROM neos_contentgraph_node n
 INNER JOIN neos_contentgraph_hierarchyrelation h ON h.childnodeanchor = n.relationanchorpoint
 WHERE n.nodeaggregateidentifier = :nodeAggregateIdentifier
 AND h.contentstreamidentifier = :contentStreamIdentifier
 AND h.dimensionspacepointhash = :dimensionSpacePointHash',
            [
                'contentStreamIdentifier' => (string)$contentStreamIdentifier,
                'nodeAggregateIdentifier' => (string)$nodeAggregateIdentifier,
                'dimensionSpacePointHash' => $coveredDimensionSpacePoint->hash
            ]
        )->fetchAssociative();

        return $nodeRow ? NodeRecord::fromDatabaseRow($nodeRow) : null;
    }

    /**
     * @param ContentStreamIdentifier $contentStreamIdentifier
     * @param NodeAggregateIdentifier $nodeAggregateIdentifier
     * @param OriginDimensionSpacePoint $originDimensionSpacePoint
     * @return NodeRecord|null
     * @throws \Exception
     */
    public function findNodeByIdentifiers(
        ContentStreamIdentifier $contentStreamIdentifier,
        NodeAggregateIdentifier $nodeAggregateIdentifier,
        OriginDimensionSpacePoint $originDimensionSpacePoint
    ): ?NodeRecord {
        $nodeRow = $this->getDatabaseConnection()->executeQuery(
            'SELECT n.*, h.name FROM neos_contentgraph_node n
 INNER JOIN neos_contentgraph_hierarchyrelation h ON h.childnodeanchor = n.relationanchorpoint
 WHERE n.nodeaggregateidentifier = :nodeAggregateIdentifier
 AND n.origindimensionspacepointhash = :originDimensionSpacePointHash
 AND h.contentstreamidentifier = :contentStreamIdentifier
 AND h.dimensionspacepointhash = :originDimensionSpacePointHash',
            [
                'contentStreamIdentifier' => (string)$contentStreamIdentifier,
                'nodeAggregateIdentifier' => (string)$nodeAggregateIdentifier,
                'originDimensionSpacePointHash' => $originDimensionSpacePoint->hash
            ]
        )->fetchAssociative();

        return $nodeRow ? NodeRecord::fromDatabaseRow($nodeRow) : null;
    }

    /**
     * @param NodeAggregateIdentifier $nodeAggregateIdentifier
     * @param OriginDimensionSpacePoint $originDimensionSpacePoint
     * @param ContentStreamIdentifier $contentStreamIdentifier
     * @return NodeRelationAnchorPoint|null
     * @throws DBALException
     */
    public function getAnchorPointForNodeAndOriginDimensionSpacePointAndContentStream(
        NodeAggregateIdentifier $nodeAggregateIdentifier,
        OriginDimensionSpacePoint $originDimensionSpacePoint,
        ContentStreamIdentifier $contentStreamIdentifier
    ): ?NodeRelationAnchorPoint {
        $rows = $this->getDatabaseConnection()->executeQuery(
            'SELECT DISTINCT n.relationanchorpoint FROM neos_contentgraph_node n
 INNER JOIN neos_contentgraph_hierarchyrelation h ON h.childnodeanchor = n.relationanchorpoint
 WHERE n.nodeaggregateidentifier = :nodeAggregateIdentifier
 AND n.origindimensionspacepointhash = :originDimensionSpacePointHash
 AND h.contentstreamidentifier = :contentStreamIdentifier',
            [
                'nodeAggregateIdentifier' => (string)$nodeAggregateIdentifier,
                'originDimensionSpacePointHash' => $originDimensionSpacePoint->hash,
                'contentStreamIdentifier' => (string)$contentStreamIdentifier,
            ]
        )->fetchAllAssociative();

        if (count($rows) > 1) {
            throw new \Exception(
                'TODO: I believe this shall not happen; but we need to think this through in detail if it does!!!'
            );
        }

        if (count($rows) === 1) {
            return NodeRelationAnchorPoint::fromString($rows[0]['relationanchorpoint']);
        } else {
            return null;
        }
    }

    /**
     * @param NodeAggregateIdentifier $nodeAggregateIdentifier
     * @param ContentStreamIdentifier $contentStreamIdentifier
     * @return NodeRelationAnchorPoint[]
     * @throws DBALException
     */
    public function getAnchorPointsForNodeAggregateInContentStream(
        NodeAggregateIdentifier $nodeAggregateIdentifier,
        ContentStreamIdentifier $contentStreamIdentifier
    ): iterable {
        $rows = $this->getDatabaseConnection()->executeQuery(
            'SELECT DISTINCT n.relationanchorpoint FROM neos_contentgraph_node n
 INNER JOIN neos_contentgraph_hierarchyrelation h ON h.childnodeanchor = n.relationanchorpoint
 WHERE n.nodeaggregateidentifier = :nodeAggregateIdentifier
 AND h.contentstreamidentifier = :contentStreamIdentifier',
            [
                'nodeAggregateIdentifier' => (string)$nodeAggregateIdentifier,
                'contentStreamIdentifier' => (string)$contentStreamIdentifier,
            ]
        )->fetchAllAssociative();

        return array_map(
            fn ($row) => NodeRelationAnchorPoint::fromString($row['relationanchorpoint']),
            $rows
        );
    }

    /**
     * @param NodeRelationAnchorPoint $nodeRelationAnchorPoint
     * @return NodeRecord|null
     * @throws DBALException
     */
    public function getNodeByAnchorPoint(NodeRelationAnchorPoint $nodeRelationAnchorPoint): ?NodeRecord
    {
        $nodeRow = $this->getDatabaseConnection()->executeQuery(
            'SELECT n.* FROM neos_contentgraph_node n
 WHERE n.relationanchorpoint = :relationAnchorPoint',
            [
                'relationAnchorPoint' => (string)$nodeRelationAnchorPoint,
            ]
        )->fetchAssociative();

        return $nodeRow ? NodeRecord::fromDatabaseRow($nodeRow) : null;
    }

    /**
     * @param NodeRelationAnchorPoint|null $parentAnchorPoint
     * @param NodeRelationAnchorPoint|null $childAnchorPoint
     * @param NodeRelationAnchorPoint|null $succeedingSiblingAnchorPoint
     * @param ContentStreamIdentifier $contentStreamIdentifier
     * @param DimensionSpacePoint $dimensionSpacePoint
     * @return int
     * @throws DBALException
     */
    public function determineHierarchyRelationPosition(
        ?NodeRelationAnchorPoint $parentAnchorPoint,
        ?NodeRelationAnchorPoint $childAnchorPoint,
        ?NodeRelationAnchorPoint $succeedingSiblingAnchorPoint,
        ContentStreamIdentifier $contentStreamIdentifier,
        DimensionSpacePoint $dimensionSpacePoint
    ): int {
        if (!$parentAnchorPoint && !$childAnchorPoint) {
            throw new \InvalidArgumentException(
                'You must specify either parent or child node anchor to determine a hierarchy relation position',
                1519847447
            );
        }
        if ($succeedingSiblingAnchorPoint) {
            /** @var array<string,mixed> $succeedingSiblingRelation */
            $succeedingSiblingRelation = $this->getDatabaseConnection()->executeQuery(
                'SELECT h.* FROM neos_contentgraph_hierarchyrelation h
                          WHERE h.childnodeanchor = :succeedingSiblingAnchorPoint
                          AND h.contentstreamidentifier = :contentStreamIdentifier
                          AND h.dimensionspacepointhash = :dimensionSpacePointHash',
                [
                    'succeedingSiblingAnchorPoint' => (string)$succeedingSiblingAnchorPoint,
                    'contentStreamIdentifier' => (string)$contentStreamIdentifier,
                    'dimensionSpacePointHash' => $dimensionSpacePoint->hash
                ]
            )->fetchAssociative();

            $succeedingSiblingPosition = (int)$succeedingSiblingRelation['position'];
            $parentAnchorPoint = $succeedingSiblingRelation['parentnodeanchor'];

            $precedingSiblingData = $this->getDatabaseConnection()->executeQuery(
                'SELECT MAX(h.position) AS position FROM neos_contentgraph_hierarchyrelation h
                          WHERE h.parentnodeanchor = :anchorPoint
                          AND h.contentstreamidentifier = :contentStreamIdentifier
                          AND h.dimensionspacepointhash = :dimensionSpacePointHash
                          AND h.position < :position',
                [
                    'anchorPoint' => $parentAnchorPoint,
                    'contentStreamIdentifier' => (string)$contentStreamIdentifier,
                    'dimensionSpacePointHash' => $dimensionSpacePoint->hash,
                    'position' => $succeedingSiblingPosition
                ]
            )->fetchAssociative();
            $precedingSiblingPosition = $precedingSiblingData ? ($precedingSiblingData['position'] ?? null) : null;
            if (!is_null($precedingSiblingPosition)) {
                $precedingSiblingPosition = (int) $precedingSiblingPosition;
            }

            if (is_null($precedingSiblingPosition)) {
                $position = $succeedingSiblingPosition - GraphProjector::RELATION_DEFAULT_OFFSET;
            } else {
                $position = ($succeedingSiblingPosition + $precedingSiblingPosition) / 2;
            }
        } else {
            if (!$parentAnchorPoint) {
                /** @var array<string,mixed> $childHierarchyRelationData */
                $childHierarchyRelationData = $this->getDatabaseConnection()->executeQuery(
                    'SELECT h.parentnodeanchor FROM neos_contentgraph_hierarchyrelation h
                      WHERE h.childnodeanchor = :childAnchorPoint
                      AND h.contentstreamidentifier = :contentStreamIdentifier
                      AND h.dimensionspacepointhash = :dimensionSpacePointHash',
                    [
                        'childAnchorPoint' => $childAnchorPoint,
                        'contentStreamIdentifier' => (string)$contentStreamIdentifier,
                        'dimensionSpacePointHash' => $dimensionSpacePoint->hash
                    ]
                )->fetchAssociative();
                $parentAnchorPoint = NodeRelationAnchorPoint::fromString(
                    $childHierarchyRelationData['parentnodeanchor']
                );
            }
            $rightmostSucceedingSiblingRelationData = $this->getDatabaseConnection()->executeQuery(
                'SELECT MAX(h.position) AS position FROM neos_contentgraph_hierarchyrelation h
                      WHERE h.parentnodeanchor = :parentAnchorPoint
                      AND h.contentstreamidentifier = :contentStreamIdentifier
                      AND h.dimensionspacepointhash = :dimensionSpacePointHash',
                [
                    'parentAnchorPoint' => $parentAnchorPoint,
                    'contentStreamIdentifier' => (string)$contentStreamIdentifier,
                    'dimensionSpacePointHash' => $dimensionSpacePoint->hash
                ]
            )->fetchAssociative();

            if ($rightmostSucceedingSiblingRelationData) {
                $position = ((int)$rightmostSucceedingSiblingRelationData['position'])
                    + GraphProjector::RELATION_DEFAULT_OFFSET;
            } else {
                $position = 0;
            }
        }

        return $position;
    }

    /**
     * @param NodeRelationAnchorPoint $parentAnchorPoint
     * @param ContentStreamIdentifier $contentStreamIdentifier
     * @param DimensionSpacePoint $dimensionSpacePoint
     * @return HierarchyRelationRecord[]
     * @throws DBALException
     */
    public function getOutgoingHierarchyRelationsForNodeAndSubgraph(
        NodeRelationAnchorPoint $parentAnchorPoint,
        ContentStreamIdentifier $contentStreamIdentifier,
        DimensionSpacePoint $dimensionSpacePoint
    ): array {
        $relations = [];
        foreach (
            $this->getDatabaseConnection()->executeQuery(
                'SELECT h.* FROM neos_contentgraph_hierarchyrelation h
                          WHERE h.parentnodeanchor = :parentAnchorPoint
                          AND h.contentstreamidentifier = :contentStreamIdentifier
                          AND h.dimensionspacepointhash = :dimensionSpacePointHash',
                [
                    'parentAnchorPoint' => (string)$parentAnchorPoint,
                    'contentStreamIdentifier' => (string)$contentStreamIdentifier,
                    'dimensionSpacePointHash' => $dimensionSpacePoint->hash
                ]
            )->fetchAllAssociative() as $relationData
        ) {
            $relations[] = $this->mapRawDataToHierarchyRelation($relationData);
        }

        return $relations;
    }

    /**
     * @param NodeRelationAnchorPoint $childAnchorPoint
     * @param ContentStreamIdentifier $contentStreamIdentifier
     * @param DimensionSpacePoint $dimensionSpacePoint
     * @return HierarchyRelationRecord[]
     * @throws DBALException
     */
    public function getIngoingHierarchyRelationsForNodeAndSubgraph(
        NodeRelationAnchorPoint $childAnchorPoint,
        ContentStreamIdentifier $contentStreamIdentifier,
        DimensionSpacePoint $dimensionSpacePoint
    ): array {
        $relations = [];
        foreach (
            $this->getDatabaseConnection()->executeQuery(
                'SELECT h.* FROM neos_contentgraph_hierarchyrelation h
                          WHERE h.childnodeanchor = :childAnchorPoint
                          AND h.contentstreamidentifier = :contentStreamIdentifier
                          AND h.dimensionspacepointhash = :dimensionSpacePointHash',
                [
                    'childAnchorPoint' => (string)$childAnchorPoint,
                    'contentStreamIdentifier' => (string)$contentStreamIdentifier,
                    'dimensionSpacePointHash' => $dimensionSpacePoint->hash
                ]
            )->fetchAllAssociative() as $relationData
        ) {
            $relations[] = $this->mapRawDataToHierarchyRelation($relationData);
        }

        return $relations;
    }

    /**
     * @param NodeRelationAnchorPoint $childAnchorPoint
     * @param ContentStreamIdentifier $contentStreamIdentifier
     * @param DimensionSpacePointSet|null $restrictToSet
     * @return HierarchyRelationRecord[]
     * @throws DBALException
     */
    public function findIngoingHierarchyRelationsForNode(
        NodeRelationAnchorPoint $childAnchorPoint,
        ContentStreamIdentifier $contentStreamIdentifier,
        DimensionSpacePointSet $restrictToSet = null
    ): array {
        $relations = [];
        $query = 'SELECT h.* FROM neos_contentgraph_hierarchyrelation h
    WHERE h.childnodeanchor = :childAnchorPoint
    AND h.contentstreamidentifier = :contentStreamIdentifier';
        $parameters = [
            'childAnchorPoint' => (string)$childAnchorPoint,
            'contentStreamIdentifier' => (string)$contentStreamIdentifier
        ];
        $types = [];

        if ($restrictToSet) {
            $query .= '
    AND h.dimensionspacepointhash IN (:dimensionSpacePointHashes)';
            $parameters['dimensionSpacePointHashes'] = $restrictToSet->getPointHashes();
            $types['dimensionSpacePointHashes'] = Connection::PARAM_STR_ARRAY;
        }
        foreach (
            $this->getDatabaseConnection()->executeQuery($query, $parameters, $types)
                ->fetchAllAssociative() as $relationData
        ) {
            $relations[$relationData['dimensionspacepointhash']] = $this->mapRawDataToHierarchyRelation($relationData);
        }

        return $relations;
    }

    /**
     * @param NodeRelationAnchorPoint $parentAnchorPoint
     * @param ContentStreamIdentifier $contentStreamIdentifier
     * @param DimensionSpacePointSet|null $restrictToSet
     * @return HierarchyRelationRecord[]
     * @throws DBALException
     */
    public function findOutgoingHierarchyRelationsForNode(
        NodeRelationAnchorPoint $parentAnchorPoint,
        ContentStreamIdentifier $contentStreamIdentifier,
        DimensionSpacePointSet $restrictToSet = null
    ): array {
        $relations = [];
        $query = 'SELECT h.* FROM neos_contentgraph_hierarchyrelation h
    WHERE h.parentnodeanchor = :parentAnchorPoint
    AND h.contentstreamidentifier = :contentStreamIdentifier';
        $parameters = [
            'parentAnchorPoint' => (string)$parentAnchorPoint,
            'contentStreamIdentifier' => (string)$contentStreamIdentifier
        ];
        $types = [];

        if ($restrictToSet) {
            $query .= '
    AND h.dimensionspacepointhash IN (:dimensionSpacePointHashes)';
            $parameters['dimensionSpacePointHashes'] = $restrictToSet->getPointHashes();
            $types['dimensionSpacePointHashes'] = Connection::PARAM_STR_ARRAY;
        }
        foreach (
            $this->getDatabaseConnection()->executeQuery($query, $parameters, $types)
                 ->fetchAllAssociative() as $relationData
        ) {
            $relations[] = $this->mapRawDataToHierarchyRelation($relationData);
        }

        return $relations;
    }

    /**
     * @param ContentStreamIdentifier $contentStreamIdentifier
     * @param NodeAggregateIdentifier $nodeAggregateIdentifier
     * @param DimensionSpacePointSet $dimensionSpacePointSet
     * @return array|HierarchyRelationRecord[]
     * @throws DBALException
     */
    public function findOutgoingHierarchyRelationsForNodeAggregate(
        ContentStreamIdentifier $contentStreamIdentifier,
        NodeAggregateIdentifier $nodeAggregateIdentifier,
        DimensionSpacePointSet $dimensionSpacePointSet
    ): array {
        $relations = [];
        foreach (
            $this->getDatabaseConnection()->executeQuery(
                'SELECT h.* FROM neos_contentgraph_hierarchyrelation h
     INNER JOIN neos_contentgraph_node n ON h.parentnodeanchor = n.relationanchorpoint
     WHERE n.nodeaggregateidentifier = :nodeAggregateIdentifier
     AND h.contentstreamidentifier = :contentStreamIdentifier
     AND h.dimensionspacepointhash IN (:dimensionSpacePointHashes)',
                [
                    'nodeAggregateIdentifier' => (string)$nodeAggregateIdentifier,
                    'contentStreamIdentifier' => (string)$contentStreamIdentifier,
                    'dimensionSpacePointHashes' => $dimensionSpacePointSet->getPointHashes()
                ],
                [
                    'dimensionSpacePointHashes' => Connection::PARAM_STR_ARRAY
                ]
            )->fetchAllAssociative() as $relationData
        ) {
            $relations[] = $this->mapRawDataToHierarchyRelation($relationData);
        }

        return $relations;
    }

    /**
     * @param ContentStreamIdentifier $contentStreamIdentifier
     * @param NodeAggregateIdentifier $nodeAggregateIdentifier
     * @param DimensionSpacePointSet|null $dimensionSpacePointSet
     * @return array|HierarchyRelationRecord[]
     * @throws DBALException
     */
    public function findIngoingHierarchyRelationsForNodeAggregate(
        ContentStreamIdentifier $contentStreamIdentifier,
        NodeAggregateIdentifier $nodeAggregateIdentifier,
        DimensionSpacePointSet $dimensionSpacePointSet = null
    ): array {
        $relations = [];

        $query = 'SELECT h.* FROM neos_contentgraph_hierarchyrelation h
            INNER JOIN neos_contentgraph_node n ON h.childnodeanchor = n.relationanchorpoint
            WHERE n.nodeaggregateidentifier = :nodeAggregateIdentifier
            AND h.contentstreamidentifier = :contentStreamIdentifier';
        $parameters = [
            'nodeAggregateIdentifier' => (string)$nodeAggregateIdentifier,
            'contentStreamIdentifier' => (string)$contentStreamIdentifier,
        ];
        $types = [];

        if ($dimensionSpacePointSet !== null) {
            $query .= '
                AND h.dimensionspacepointhash IN (:dimensionSpacePointHashes)';
            $parameters['dimensionSpacePointHashes'] = $dimensionSpacePointSet->getPointHashes();
            $types['dimensionSpacePointHashes'] = Connection::PARAM_STR_ARRAY;
        }

        foreach (
            $this->getDatabaseConnection()->executeQuery($query, $parameters, $types)
                ->fetchAllAssociative() as $relationData
        ) {
            $relations[] = $this->mapRawDataToHierarchyRelation($relationData);
        }

        return $relations;
    }

    /**
     * @return array<int,ContentStreamIdentifier>
     * @throws \Doctrine\DBAL\Driver\Exception|\Doctrine\DBAL\Exception
     */
    public function getAllContentStreamIdentifiersAnchorPointIsContainedIn(
        NodeRelationAnchorPoint $nodeRelationAnchorPoint
    ): array {
        $contentStreamIdentifiers = [];
        foreach (
            $this->getDatabaseConnection()->executeQuery(
                'SELECT DISTINCT h.contentstreamidentifier
                          FROM neos_contentgraph_hierarchyrelation h
                          WHERE h.childnodeanchor = :nodeRelationAnchorPoint',
                [
                    'nodeRelationAnchorPoint' => (string)$nodeRelationAnchorPoint,
                ]
            )->fetchAllAssociative() as $row
        ) {
            $contentStreamIdentifiers[] = ContentStreamIdentifier::fromString($row['contentstreamidentifier']);
        }

        return $contentStreamIdentifiers;
    }

    /**
     * Finds all descendant node aggregate identifiers, indexed by dimension space point hash
     *
     * @param ContentStreamIdentifier $contentStreamIdentifier
     * @param NodeAggregateIdentifier $entryNodeAggregateIdentifier
     * @param DimensionSpacePointSet $affectedDimensionSpacePoints
     * @return array|NodeAggregateIdentifier[][]
     * @throws DBALException
     */
    public function findDescendantNodeAggregateIdentifiers(
        ContentStreamIdentifier $contentStreamIdentifier,
        NodeAggregateIdentifier $entryNodeAggregateIdentifier,
        DimensionSpacePointSet $affectedDimensionSpacePoints
    ): array {
        $rows = $this->getDatabaseConnection()->executeQuery(
            '
            -- ProjectionContentGraph::findDescendantNodeAggregateIdentifiers

            WITH RECURSIVE nestedNodes AS (
                    -- --------------------------------
                    -- INITIAL query: select the root nodes
                    -- --------------------------------
                    SELECT
                       n.nodeaggregateidentifier,
                       n.relationanchorpoint,
                       h.dimensionspacepointhash
                    FROM
                        neos_contentgraph_node n
                    INNER JOIN neos_contentgraph_hierarchyrelation h
                        on h.childnodeanchor = n.relationanchorpoint
                    WHERE n.nodeaggregateidentifier = :entryNodeAggregateIdentifier
                    AND h.contentstreamidentifier = :contentStreamIdentifier
                    AND h.dimensionspacepointhash IN (:affectedDimensionSpacePointHashes)

                UNION
                    -- --------------------------------
                    -- RECURSIVE query: do one "child" query step
                    -- --------------------------------
                    SELECT
                        c.nodeaggregateidentifier,
                        c.relationanchorpoint,
                       h.dimensionspacepointhash
                    FROM
                        nestedNodes p
                    INNER JOIN neos_contentgraph_hierarchyrelation h
                        on h.parentnodeanchor = p.relationanchorpoint
                    INNER JOIN neos_contentgraph_node c
                        on h.childnodeanchor = c.relationanchorpoint
                    WHERE
                        h.contentstreamidentifier = :contentStreamIdentifier
                        AND h.dimensionspacepointhash IN (:affectedDimensionSpacePointHashes)
            )
            select nodeaggregateidentifier, dimensionspacepointhash from nestedNodes
            ',
            [
                'entryNodeAggregateIdentifier' => (string)$entryNodeAggregateIdentifier,
                'contentStreamIdentifier' => (string)$contentStreamIdentifier,
                'affectedDimensionSpacePointHashes' => $affectedDimensionSpacePoints->getPointHashes()
            ],
            [
                'affectedDimensionSpacePointHashes' => Connection::PARAM_STR_ARRAY
            ]
        )->fetchAllAssociative();

        $nodeAggregateIdentifiers = [];
        foreach ($rows as $row) {
            $nodeAggregateIdentifiers[$row['nodeaggregateidentifier']][$row['dimensionspacepointhash']]
                = NodeAggregateIdentifier::fromString($row['nodeaggregateidentifier']);
        }

        return $nodeAggregateIdentifiers;
    }

    /**
     * @param array<string,string> $rawData
     */
    protected function mapRawDataToHierarchyRelation(array $rawData): HierarchyRelationRecord
    {
        return new HierarchyRelationRecord(
            NodeRelationAnchorPoint::fromString($rawData['parentnodeanchor']),
            NodeRelationAnchorPoint::fromString($rawData['childnodeanchor']),
            $rawData['name'] ? NodeName::fromString($rawData['name']) : null,
            ContentStreamIdentifier::fromString($rawData['contentstreamidentifier']),
            DimensionSpacePoint::fromJsonString($rawData['dimensionspacepoint']),
            $rawData['dimensionspacepointhash'],
            (int)$rawData['position']
        );
    }

    /**
     * @return Connection
     */
    protected function getDatabaseConnection(): Connection
    {
        return $this->client->getConnection();
    }
}