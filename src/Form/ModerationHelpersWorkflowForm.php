<?php

declare(strict_types=1);

namespace Drupal\moderation_helpers\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\workflows\StateInterface;
use Drupal\workflows\WorkflowInterface;

/**
 * Configuration form for a specific workflow's moderation helper settings.
 */
class ModerationHelpersWorkflowForm extends ConfigFormBase {

  /**
   * Display style options for workflow states.
   */
  protected const DISPLAY_OPTIONS = [
    'draft' => 'Draft',
    'review' => 'Review',
    'published' => 'Published',
    'archived' => 'Archived',
  ];

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'moderation_helpers_workflow';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['moderation_helpers.settings'];
  }

  /**
   * Title callback for the route.
   */
  public function title(WorkflowInterface $workflow): TranslatableMarkup {
    return $this->t('Moderation Helpers: @workflow', [
      '@workflow' => $workflow->label(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?WorkflowInterface $workflow = NULL): array {
    if (!$workflow) {
      return $form;
    }

    $config = $this->config('moderation_helpers.settings');
    $workflowId = $workflow->id();
    $workflowSettings = $config->get("workflows.$workflowId") ?? [];

    $form['workflow_id'] = [
      '#type' => 'value',
      '#value' => $workflowId,
    ];

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Moderation Helpers for this workflow'),
      '#default_value' => !empty($workflowSettings['enabled']),
    ];

    $typePlugin = $workflow->getTypePlugin();
    $states = $typePlugin->getStates();
    $transitions = $typePlugin->getTransitions();

    // Build transition options list.
    $transitionOptions = [];
    foreach ($transitions as $transition) {
      $transitionOptions[$transition->id()] = $transition->label();
    }

    $form['states'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('State'),
        $this->t('Display style'),
        $this->t('Toolbar transitions'),
      ],
      '#empty' => $this->t('No states defined in this workflow.'),
    ];

    foreach ($states as $stateId => $state) {
      $stateSettings = $workflowSettings['states'][$stateId] ?? [];

      // Determine default display based on state properties.
      $defaultDisplay = 'draft';
      if ($state->isDefaultRevisionState() && $state->isPublishedState()) {
        $defaultDisplay = 'published';
      }
      elseif ($state->isDefaultRevisionState()) {
        $defaultDisplay = 'archived';
      }

      // Build list of transitions available from this state.
      $stateTransitions = [];
      foreach ($transitions as $transition) {
        $fromStates = array_map(fn(StateInterface $s) => $s->id(), $transition->from());
        if (in_array($stateId, $fromStates, TRUE)) {
          $stateTransitions[$transition->id()] = $transition->label();
        }
      }

      $form['states'][$stateId]['label'] = [
        '#markup' => $state->label(),
      ];

      $form['states'][$stateId]['display'] = [
        '#type' => 'select',
        '#title' => $this->t('Display style for @state', [
          '@state' => $state->label(),
        ]),
        '#title_display' => 'invisible',
        '#options' => self::DISPLAY_OPTIONS,
        '#default_value' => $stateSettings['display'] ?? $defaultDisplay,
      ];

      $form['states'][$stateId]['transitions'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Toolbar transitions for @state', [
          '@state' => $state->label(),
        ]),
        '#title_display' => 'invisible',
        '#options' => $stateTransitions,
        '#default_value' => $stateSettings['transitions'] ?? array_keys($stateTransitions),
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('moderation_helpers.settings');
    $workflowId = $form_state->getValue('workflow_id');
    $statesInput = $form_state->getValue('states') ?? [];

    $stateSettings = [];
    foreach ($statesInput as $stateId => $values) {
      $stateSettings[$stateId] = [
        'display' => $values['display'],
        'transitions' => array_values(array_filter($values['transitions'])),
      ];
    }

    $config->set("workflows.$workflowId.enabled", (bool) $form_state->getValue('enabled'));
    $config->set("workflows.$workflowId.states", $stateSettings);
    $config->save();

    parent::submitForm($form, $form_state);
  }

}
