<?php

declare(strict_types=1);

namespace Drupal\moderation_helpers;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\content_moderation\StateTransitionValidationInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;
use Drupal\workflows\StateInterface;

/**
 * Helper to manage moderation of content.
 */
class ModerationHelper {

  use StringTranslationTrait;
  use MessengerTrait;

  public function __construct(
    protected readonly AccountInterface $currentUser,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly ModerationInformationInterface $moderationInformation,
    protected readonly StateTransitionValidationInterface $validator,
    protected readonly TimeInterface $time,
  ) {}

  /**
   * Get the full moderation state context for a node and optional revision.
   *
   * @param \Drupal\node\NodeInterface|null $node
   *   The node entity (default revision from route).
   * @param \Drupal\node\NodeInterface|null $rev
   *   The specific revision being viewed, if any.
   *
   * @return \Drupal\moderation_helpers\NodeModerationState|null
   *   The moderation state context, or NULL if not applicable.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getModerationState(?NodeInterface $node, ?NodeInterface $rev): ?NodeModerationState {
    if (!$node) {
      return NULL;
    }
    $workflow = $this->moderationInformation->getWorkflowForEntity($node);

    if (!$workflow) {
      return NULL;
    }

    $getState = function (NodeInterface $it) use ($workflow): array {
      return [
        $it,
        $workflow->getTypePlugin()->getState($it->moderation_state->value),
      ];
    };

    $storage = $this->entityTypeManager->getStorage('node');
    $latestRevisionId = $storage->getLatestTranslationAffectedRevisionId(
      $node->id(),
      $node->language()->getId(),
    );

    $nodeState = $getState($node)[1];

    $state = new NodeModerationState($workflow);

    if ($rev) {
      $state->setCurrent(...$getState($rev));
      if ($rev->getLoadedRevisionId() === $latestRevisionId) {
        $state->setLatest($state->current->node, $state->current->state);
      }
    }
    else {
      $state->setCurrent($node, $nodeState);
    }

    if (!$state->latest && $node->getLoadedRevisionId() === $latestRevisionId) {
      $state->setLatest($node, $nodeState);
    }

    if ($nodeState->isDefaultRevisionState()) {
      $state->setDefault($node, $nodeState);
    }
    else {
      $defaultId = $this->moderationInformation->getDefaultRevisionId('node', $node->id());
      if ($defaultId) {
        $defaultRevision = $storage->loadRevision($defaultId);
        $state->setDefault(...$getState($defaultRevision));

        if ($defaultId === $latestRevisionId) {
          $state->setLatest($state->default->node, $state->default->state);
        }
      }
    }

    if (!$state->latest) {
      $latest = $storage->loadRevision($latestRevisionId);
      $state->setLatest(...$getState($latest));
    }

    $state->setTransitions($this->getValidTransitions($state->current->node, $this->currentUser));
    $state->setLatestTransitions($this->getValidTransitions($state->latest->node, $this->currentUser));

    return $state;
  }

  /**
   * Load a specific node revision.
   */
  public function loadRevision(string|int $vid): NodeInterface {
    return $this->entityTypeManager->getStorage('node')->loadRevision($vid);
  }

  /**
   * Get the valid transitions for a node and user.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node entity.
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The user account.
   *
   * @return \Drupal\workflows\Transition[]
   *   The valid transitions.
   */
  public function getValidTransitions(NodeInterface $node, AccountInterface $user): array {
    return $this->validator->getValidTransitions($node, $user);
  }

  /**
   * Transition a content entity to a new moderation state.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity to transition.
   * @param \Drupal\workflows\StateInterface $state
   *   The target workflow state.
   * @param string|null $logMessage
   *   Optional custom revision log message. Defaults to the state label.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The saved entity revision.
   */
  public function transition(ContentEntityInterface $entity, StateInterface $state, ?string $logMessage = NULL): ContentEntityInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    $entity = $storage->createRevision($entity, $state->isDefaultRevisionState());

    $entity->set('moderation_state', $state->id());

    if ($entity instanceof RevisionLogInterface) {
      $entity->setRevisionCreationTime($this->time->getRequestTime());
      $entity->setRevisionLogMessage(
        ($logMessage ?? (string) $state->label()) . ' from moderation toolbar.'
      );
      $entity->setRevisionUserId($this->currentUser->id());
    }
    $entity->save();
    return $entity;
  }

  /**
   * Transition a content entity to a new state by state ID.
   *
   * Resolves the state from the entity's workflow and delegates to transition().
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity to transition.
   * @param string $stateId
   *   The target state machine name.
   * @param string|null $logMessage
   *   Optional custom revision log message.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The saved entity revision.
   */
  public function transitionTo(ContentEntityInterface $entity, string $stateId, ?string $logMessage = NULL): ContentEntityInterface {
    $state = $this->moderationInformation->getWorkflowForEntity($entity)
      ->getTypePlugin()
      ->getState($stateId);

    return $this->transition($entity, $state, $logMessage);
  }

}
