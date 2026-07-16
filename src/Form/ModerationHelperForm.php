<?php

declare(strict_types=1);

namespace Drupal\moderation_helpers\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\moderation_helpers\ModerationHelper;
use Drupal\moderation_helpers\NodeInstanceState;
use Drupal\moderation_helpers\NodeModerationState;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a moderation form for changing content moderation state.
 *
 * @internal
 */
class ModerationHelperForm extends FormBase {

  public function __construct(
    protected readonly ModerationHelper $helper,
    protected readonly TimeInterface $time,
    protected readonly CurrentRouteMatch $route,
    protected readonly DateFormatterInterface $dateFormatter,
    ConfigFactoryInterface $configFactory,
  ) {
    $this->configFactory = $configFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('moderation_helpers.moderation'),
      $container->get('datetime.time'),
      $container->get('current_route_match'),
      $container->get('date.formatter'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'moderation_helper_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(
    array $form, FormStateInterface $form_state, $extra = NULL,
  ): array {
    $form['#theme'] = ['moderation_helper_form'];
    $form['#attached']['library'][] = 'moderation_helpers/moderation';

    if (!$extra || !isset($extra['node'])) {
      return $form;
    }

    /** @var \Drupal\node\NodeInterface $node */
    $node = $extra['node'];
    /** @var \Drupal\node\NodeInterface|null $rev */
    $rev = $extra['rev'];

    $form['edit'] = [
      '#theme' => 'moderation_helper_buttons',
      '#edit' => [
        'title' => $this->t('Edit'),
        'url' => Url::fromRoute('entity.node.edit_form', [
          'node' => $node->id(),
        ]),
      ],
      '#history' => [
        'title' => $this->t('Version History'),
        'url' => Url::fromRoute('entity.node.version_history', [
          'node' => $node->id(),
        ]),
      ],
    ];

    $state = $this->helper->getModerationState($node, $rev);

    if (!$state) {
      return $form;
    }

    $workflowId = $state->workflow->id();
    $workflowSettings = $this->configFactory->get('moderation_helpers.settings')
      ->get("workflows.$workflowId") ?? [];

    // Bail out if moderation helpers are not enabled for this workflow.
    if (empty($workflowSettings['enabled'])) {
      return $form;
    }

    // Helper to look up the configured display class for a state.
    $display = static function (string $stateId, string $fallback) use ($workflowSettings): string {
      return $workflowSettings['states'][$stateId]['display'] ?? $fallback;
    };

    // Configured transitions for the current state (NULL = show all).
    $currentStateId = $state->current->state->id();
    $allowedTransitions = $workflowSettings['states'][$currentStateId]['transitions'] ?? NULL;

    $form_state->set('vid', $state->currentRevision());

    $form['current_state'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['moderation-current']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Moderation state'),
      ],
    ];

    // These flags indicate that panels for the default or latest revision
    // should be populated later, when the current revision differs from them.
    $defaultState = NULL;
    $latestState = NULL;

    // Transition buttons for the current revision.
    $transitionButtons = $this->buildTransitionButtons($state, $allowedTransitions);

    // Common links.
    $viewHistory = [
      '#type' => 'link',
      '#title' => $this->t('Revision History'),
      '#url' => Url::fromRoute('entity.node.version_history', [
        'node' => $node->id(),
      ]),
    ];
    $viewLatest = [
      '#type' => 'link',
      '#title' => $state->isLatestPending()
        ? $this->t('Review Draft')
        : $this->t('View Latest'),
      '#url' => Url::fromRoute('entity.node.revision', [
        'node' => $node->id(),
        'node_revision' => $state->latestRevision(),
      ]),
    ];
    $viewDefault = [
      '#type' => 'link',
      '#title' => $state->isNodePublished()
        ? $this->t('View Published')
        : $this->t('View Default'),
      '#url' => Url::fromRoute(
        'entity.node.canonical', ['node' => $node->id()]
      ),
    ];

    $viewCompare = function (string $title, string|int $left, string|int $right) use ($node): array {
      return [
        '#type' => 'link',
        '#title' => $this->t($title),
        '#url' => Url::fromRoute('diff.revisions_diff', [
          'node' => $node->id(),
          'left_revision' => $left,
          'right_revision' => $right,
          'filter' => 'split_fields',
        ]),
      ];
    };

    if (!$state->isDefault() && !$state->isLatest()) {
      // Viewing a historical revision (not default, not latest).
      $compareWith = $state->isNodePublished()
        ? $state->default->node->getLoadedRevisionId()
        : $state->latestRevision();
      $compareLabel = $state->isNodePublished() ? 'Compare' : 'Compare With Latest';

      $bottom = [
        'view' => $viewCompare($compareLabel, $state->currentRevision(), $compareWith),
      ];
      if ($state->can('published') && $state->current->node->access('revert')) {
        $bottom['revert'] = $this->buildActionButton('revert', $this->t('Revert'));
      }

      $currentState = $this->renderState(
        $state->current, 'old', $this->t('Old Version'), $bottom,
      );
      $defaultState = TRUE;
      $latestState = TRUE;
    }
    elseif ($state->isDefault() && $state->isLatest()) {
      // Viewing the revision that is both default and latest.
      if ($state->isNodePublished()) {
        $currentState = $this->renderState(
          $state->current, $display($currentStateId, 'published'), $this->t('Published'), $transitionButtons,
        );
      }
      elseif ($state->isDefaultUnpublished() && $state->current->state->isDefaultRevisionState()) {
        // Default revision state but not published (e.g., archived).
        $bottom = $transitionButtons;
        $currentState = $this->renderState(
          $state->current, $display($currentStateId, 'archived'), $state->currentLabel(), $bottom,
        );
      }
      elseif ($state->isLatestPending()) {
        // Pending state (e.g., needs_review, draft).
        $currentState = $this->renderState(
          $state->current, $display($currentStateId, 'draft'), $this->t('Unpublished, @state', [
            '@state' => $state->currentLabel(),
          ]), $transitionButtons,
        );
      }
      else {
        $currentState = $this->renderState(
          $state->current, $display($currentStateId, 'draft'), $this->t('Unpublished, Latest Draft'), $transitionButtons,
        );
      }
    }
    elseif ($state->isDefault()) {
      // Viewing the default revision, but a newer revision exists.
      if ($state->isNodePublished()) {
        $currentState = $this->renderState(
          $state->current, $display($currentStateId, 'published'), $this->t('Published'), $transitionButtons,
        );
      }
      else {
        $currentState = $this->renderState(
          $state->current, 'not-published', $this->t('No Published Version'),
          $transitionButtons,
        );
      }
      // A separate latest panel is only useful when it identifies a pending
      // revision. Published revisions do not need to duplicate this panel.
      $latestState = $state->isLatestPending();
    }
    else {
      // Viewing the latest revision (not default) — a forward revision.
      $view = $viewCompare(
        'Compare', $state->defaultRevision(), $state->currentRevision(),
      );
      $bottom = array_merge(['view' => $view], $transitionButtons);

      $cssClass = $display($currentStateId, $state->isLatestPending() ? 'review' : 'draft');
      $message = $cssClass === 'review'
        ? $this->t('In Review')
        : $this->t('Latest Draft');

      $currentState = $this->renderState(
        $state->current, $cssClass, $message, $bottom,
      );
      $defaultState = TRUE;
    }

    $form['current_state']['#attributes']['class'][] = $currentState['#state'];
    $form['current_state']['state'] = $currentState;

    if ($latestState === TRUE) {
      $form['latest_state'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['moderation-latest']],
        'content' => [
          'heading' => [
            '#type' => 'html_tag',
            '#tag' => 'h2',
            '#value' => $this->t('Latest version state'),
          ],
        ],
      ];

      if ($state->isLatestPending()) {
        $latestState = $this->renderState(
          $state->latest, $display($state->latest->state->id(), 'review'), $this->t('Update Pending: @state', [
            '@state' => (string) $state->latest->state->label(),
          ]), ['view' => $viewLatest],
        );
      }
      elseif ($state->isDefaultUnpublished()) {
        $latestState = $this->renderState(
          $state->current, $display($currentStateId, 'archived'), $state->currentLabel(), [
            'view' => [
              '#type' => 'link',
              '#title' => $this->t('View Current Version'),
              '#url' => Url::fromRoute(
                'entity.node.canonical', ['node' => $node->id()]
              ),
            ],
          ],
        );
      }
      else {
        $latestState = $this->renderState(
          $state->latest,
          $display($state->latest->state->id(), 'published'),
          $this->t('Latest Version: @state', [
            '@state' => (string) $state->latest->state->label(),
          ]), [
            'view' => $viewLatest,
          ],
        );
      }

      $form['latest_state']['content']['state'] = $latestState;
      $form['latest_state']['#attributes']['class'][] = $latestState['#state'];
    }

    if ($defaultState === TRUE && !($latestState && $state->isLatestDefault())) {
      $form['default_state'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['moderation-default']],
        'content' => [
          'heading' => [
            '#type' => 'html_tag',
            '#tag' => 'h2',
            '#value' => $this->t('Default version state'),
          ],
        ],
      ];

      if ($state->isNodePublished()) {
        $defaultState = $this->renderState(
          $state->default, $display($state->default->state->id(), 'published'), $this->t('Published Version'), [
            'view' => $viewDefault,
          ],
        );
      }
      elseif ($state->isDefaultUnpublished()) {
        $defaultState = $this->renderState(
          $state->default, $display($state->default->state->id(), 'archived'), (string) $state->default->state->label(), [
            'history' => $viewHistory,
          ],
        );
      }
      else {
        $defaultState = $this->renderState(
          $state->default, 'unpublished', $this->t('Unpublished'), [
            'view' => $viewDefault,
          ],
        );
      }

      $form['default_state']['content']['state'] = $defaultState;
      $form['default_state']['#attributes']['class'][] = $defaultState['#state'];
    }

    // Moderating an entity is allowed in a workspace.
    $form_state->set('workspace_safe', TRUE);

    return $form;
  }

  /**
   * Build transition action buttons from the available valid transitions.
   *
   * @param array|null $allowedTransitions
   *   Transition IDs to include, or NULL to include all valid transitions.
   *
   * @return array
   *   Render array of submit buttons keyed by target state ID.
   */
  protected function buildTransitionButtons(NodeModerationState $state, ?array $allowedTransitions = NULL): array {
    $buttons = [];
    foreach ($state->transitions as $transition) {
      if ($allowedTransitions !== NULL && !in_array($transition->id(), $allowedTransitions, TRUE)) {
        continue;
      }
      $targetId = $transition->to()->id();
      $buttons[$targetId] = [
        '#type' => 'submit',
        '#value' => $transition->label(),
        '#name' => 'transition__' . $targetId,
      ];
    }
    return $buttons;
  }

  /**
   * Build a single action button.
   */
  protected function buildActionButton(string $name, string|TranslatableMarkup $label): array {
    return [
      '#type' => 'submit',
      '#value' => $label,
      '#name' => $name,
    ];
  }

  /**
   * Render a moderation state panel.
   */
  protected function renderState(
    NodeInstanceState $state, string $class, string|TranslatableMarkup $message, ?array $bottom = NULL,
  ): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => [$class]],
      '#state' => $class,
      'state_info' => [
        '#theme' => 'moderation_helper_state',
        '#state_class' => $class,
        '#message' => $message,
        '#time_label' => $this->t('Revision Time'),
        '#time' => $this->dateFormatter->format(
          $state->node->getChangedTime(), 'html_date',
        ),
        '#author_label' => $this->t('Author'),
        '#author' => $state->node->getRevisionUser()->getDisplayName(),
      ],
      'actions' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['moderation-bottom']],
        'content' => $bottom,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $triggeringElement = $form_state->getTriggeringElement();
    $vid = $form_state->get('vid');
    $node = $this->helper->loadRevision($vid);
    $action = $triggeringElement['#name'];

    // Handle generic transition buttons (transition__<state_id>).
    if (str_starts_with($action, 'transition__')) {
      $stateId = substr($action, strlen('transition__'));
      $node = $this->helper->transitionTo($node, $stateId);
    }
    elseif ($action === 'revert') {
      // Revert re-publishes a historical revision.
      $node = $this->helper->transitionTo($node, 'published');
    }

    if ($node->isDefaultRevision()) {
      $form_state->setRedirect('entity.node.canonical', [
        'node' => $node->id(),
      ]);
    }
    else {
      $form_state->setRedirect('entity.node.revision', [
        'node' => $node->id(),
        'node_revision' => $node->getLoadedRevisionId(),
      ]);
    }
  }

}
