<?php

declare(strict_types=1);

namespace Drupal\moderation_helpers;

use Drupal\node\NodeInterface;
use Drupal\workflows\StateInterface;

/**
 * Represents a node revision paired with its moderation state.
 */
class NodeInstanceState {

  public function __construct(
    public readonly NodeInterface $node,
    public readonly StateInterface $state,
  ) {}

}
