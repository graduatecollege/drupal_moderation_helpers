<?php

declare(strict_types=1);

namespace Drupal\moderation_helpers;

use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Drupal\workflows\WorkflowInterface;
use Drupal\workflows\StateInterface;

/**
 * Tracks the moderation state of a node across current, default, and latest.
 */
class NodeModerationState {

  public ?NodeInstanceState $current = NULL;
  public ?NodeInstanceState $default = NULL;
  public ?NodeInstanceState $latest = NULL;
  public ?UserInterface $latestRevisionUser = NULL;

  /**
   * @var \Drupal\workflows\Transition[]
   */
  public array $transitions = [];

  /**
   * Valid target state IDs for the currently viewed revision.
   *
   * @var array<string, string>
   *   Keyed by state ID, value is the state label.
   */
  public array $targetStates = [];

  /**
   * Valid target state IDs for the latest revision.
   *
   * @var array<string, string>
   *   Keyed by state ID, value is the state label.
   */
  public array $latestTargetStates = [];

  public function __construct(
    public readonly WorkflowInterface $workflow,
  ) {}

  public function setCurrent(NodeInterface $node, StateInterface $state): void {
    $this->current = new NodeInstanceState($node, $state);
  }

  public function setDefault(NodeInterface $node, StateInterface $state): void {
    $this->default = new NodeInstanceState($node, $state);
  }

  public function setLatest(NodeInterface $node, StateInterface $state): void {
    $this->latest = new NodeInstanceState($node, $state);
    $this->latestRevisionUser = $node->getRevisionUser();
  }

  /**
   * @param \Drupal\workflows\Transition[] $transitions
   */
  public function setTransitions(array $transitions): void {
    $this->transitions = $transitions;
    foreach ($transitions as $transition) {
      $this->targetStates[$transition->to()->id()] = $transition->to()->label();
    }
  }

  /**
   * @param \Drupal\workflows\Transition[] $transitions
   */
  public function setLatestTransitions(array $transitions): void {
    foreach ($transitions as $transition) {
      $this->latestTargetStates[$transition->to()->id()] = $transition->to()->label();
    }
  }

  /**
   * Whether the currently viewed revision is the latest revision.
   */
  public function isLatest(): bool {
    return $this->currentRevision() === $this->latestRevision();
  }

  /**
   * Whether the currently viewed revision is the default revision.
   */
  public function isDefault(): bool {
    return $this->currentRevision() === $this->defaultRevision();
  }

  /**
   * Whether the latest revision is the default revision.
   */
  public function isLatestDefault(): bool {
    return $this->defaultRevision() === $this->latestRevision();
  }

  /**
   * Whether the default revision is in a published state.
   */
  public function isNodePublished(): bool {
    return (bool) $this->default?->state->isPublishedState();
  }

  /**
   * Whether the default revision is in a non-published default state.
   *
   * This covers states like "archived" where the content has been explicitly
   * unpublished (a default revision state that is not published).
   */
  public function isDefaultUnpublished(): bool {
    if (!$this->default) {
      return FALSE;
    }
    return $this->default->state->isDefaultRevisionState()
      && !$this->default->state->isPublishedState();
  }

  /**
   * Whether the latest revision is in a non-default, non-published state.
   *
   * This covers states like "needs_review" or "draft" that represent
   * a pending revision awaiting action.
   */
  public function isLatestPending(): bool {
    if (!$this->latest) {
      return FALSE;
    }
    return !$this->latest->state->isDefaultRevisionState();
  }

  public function currentRevision(): string|int|null {
    return $this->current?->node->getLoadedRevisionId();
  }

  public function latestRevision(): string|int|null {
    return $this->latest?->node->getLoadedRevisionId();
  }

  public function defaultRevision(): string|int|null {
    return $this->default?->node?->getLoadedRevisionId();
  }

  public function currentLabel(): string {
    return (string) $this->current->state->label();
  }

  /**
   * Whether the current revision can transition to the given state.
   */
  public function can(string $stateId): bool {
    return $this->current->state->canTransitionTo($stateId)
      && isset($this->targetStates[$stateId]);
  }

  /**
   * Whether the latest revision can transition to the given state.
   */
  public function canLatest(string $stateId): bool {
    return $this->latest->state->canTransitionTo($stateId)
      && isset($this->latestTargetStates[$stateId]);
  }

}
