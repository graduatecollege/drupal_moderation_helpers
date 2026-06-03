<?php

declare(strict_types=1);

namespace Drupal\moderation_helpers\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Overview form listing all content moderation workflows.
 */
class ModerationHelpersSettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'moderation_helpers_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['moderation_helpers.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('moderation_helpers.settings');
    $workflows = $this->entityTypeManager->getStorage('workflow')->loadMultiple();
    $workflowSettings = $config->get('workflows') ?? [];

    $form['workflows'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Workflow'),
        $this->t('Type'),
        $this->t('Moderation Helpers'),
        $this->t('Operations'),
      ],
      '#empty' => $this->t('No workflows found. Create a workflow first.'),
    ];

    foreach ($workflows as $id => $workflow) {
      $enabled = !empty($workflowSettings[$id]['enabled']);

      $form['workflows'][$id]['label'] = [
        '#markup' => $workflow->label(),
      ];
      $form['workflows'][$id]['type'] = [
        '#markup' => $workflow->getTypePlugin()->label(),
      ];
      $form['workflows'][$id]['enabled'] = [
        '#markup' => $enabled ? $this->t('Enabled') : $this->t('Disabled'),
      ];
      $form['workflows'][$id]['operations'] = [
        '#type' => 'operations',
        '#links' => [
          'edit' => [
            'title' => $this->t('Edit'),
            'url' => Url::fromRoute('moderation_helpers.workflow', [
              'workflow' => $id,
            ]),
          ],
        ],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    parent::submitForm($form, $form_state);
  }

}
