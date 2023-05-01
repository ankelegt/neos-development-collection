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

namespace Neos\ContentRepository\Core\SharedModel\Node;

/**
 * The Node name is the "path part" of the node; i.e. when accessing the node "/foo" via path,
 * the node name is "foo".
 *
 * Semantically it describes the hierarchical relation of a node to its parent, e.g. "main" denotes the main child node.
 *
 * @api
 */
final class NodeName implements \JsonSerializable
{
    public const PATTERN = '/^[a-z0-9\-]+$/';

    private function __construct(
        public readonly string $value
    ) {
        if (preg_match(self::PATTERN, $this->value) !== 1) {
            throw new \InvalidArgumentException(
                'Invalid node name "' . $this->value
                    . '" (a node name must only contain lowercase characters, numbers and the "-" sign).',
                1364290748
            );
        }
    }

    public static function fromString(string $value): self
    {
        return new self(strtolower($value));
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    public function equals(NodeName $other): bool
    {
        return $this->value === $other->value;
    }
}