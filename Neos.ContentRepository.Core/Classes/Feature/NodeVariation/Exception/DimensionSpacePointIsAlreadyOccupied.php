<?php

/*
 * This file is part of the Neos.ContentRepository package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Feature\NodeVariation\Exception;

/**
 * The exception to be thrown if a dimension space point is already occupied by a node in a node aggregate
 * but is supposed not to be
 *
 * @api
 */
final class DimensionSpacePointIsAlreadyOccupied extends \DomainException
{
}