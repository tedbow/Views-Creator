<?php

namespace Drupal\views_creator\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityNullStorage;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\ContextAwarePluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'ViewsCreatorBlock' block.
 *
 * @Block(
 *  id = "views_creator_block",
 *  admin_label = @Translation("Views creator block"),
 *  context_definitions = {
 *     "entity" = @ContextDefinition("entity"),
 *   }
 * )
 */
class ViewsCreatorBlock extends BlockBase implements ContainerFactoryPluginInterface, ContextAwarePluginInterface {

  const LABEL_DISPLAY = '__label_display';
  /**
   * Drupal\Core\Entity\EntityTypeManagerInterface definition.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new ViewsCreatorBlock object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param string $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The EntityTypeManagerInterface definition.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration['entity_relationship'] = $form_state->getValue('entity_relationship');
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $build = [];
    $entity = $this->getContext('entity')->getContextData()->getValue();
    $views_options = $this->configuration['views_options'];
    list($entity_type_id, $field_name) = explode(':', $views_options['relationship']);
    $query = \Drupal::entityQuery($entity_type_id);
    $query->condition($field_name, $entity->id());
    $bundles = array_filter($views_options['bundles']);
    if (!empty($bundles)) {
      $entity_type = \Drupal::entityTypeManager()->getDefinition($entity_type_id);
      $query->condition($entity_type->getKey('bundle'), $bundles);
    }

    $query->range(0, $views_options['number']);
    $results = $query->execute();
    $display_entities = \Drupal::entityTypeManager()->getStorage($entity_type_id)->loadMultiple($results);
    if ($views_options['display'] === static::LABEL_DISPLAY) {
      foreach ($display_entities as $display_entity) {
        $build[$display_entity->id()] = $display_entity->toLink()->toRenderable();
      }
    }
    else {
      list(,$view_mode) = explode('.', $views_options['display']);
      $view_builder = \Drupal::entityTypeManager()->getViewBuilder($entity_type_id);
      $build = $view_builder->viewMultiple($display_entities, $view_mode);
    }


    return $build;
  }

  /**
   * {@inheritdoc}
   */
  protected function addContextAssignmentElement(ContextAwarePluginInterface $plugin, array $contexts) {
    $element = parent::addContextAssignmentElement($plugin, $contexts);
    // Add the formatter settings to the form via AJAX.
    $this->addAjaxCallBack($element['entity']);
    $element['entity']['#ajax'] = [
      'callback' => [static::class, 'viewsOptionsAjaxCallback'],
      'wrapper' => 'views-options-wrapper',
    ];
    $element['entity']['#description'] = $this->t('Select the entity for which you want to find other related entities.');
    $element['entity']['#options'] = array_merge(['' => '(' . $this->t('Choose an entity') . ')' ], $element['entity']['#options']);
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $this->configuration;

    // Add the formatter settings to the form via AJAX.
    $form['views_options'] = [
      '#prefix' => '<div id="views-options-wrapper">',
      '#suffix' => '</div>',
      '#type' => 'container',
    ];
    if ($form_state instanceof SubformStateInterface) {
      $form_state = $form_state->getCompleteFormState();
      $form_defaults = $this->getFormDefaults($form_state);
      if (!empty($form_defaults['context_mapping']['entity'])) {
        $context_name = $form_defaults['context_mapping']['entity'];
        $contexts = $form_state->getTemporaryValue('gathered_contexts') ?: [];
        /** @var \Drupal\Core\Plugin\Context\EntityContext $context */
        $context = $contexts[$context_name];
        $entity = $context->getContextData()->getValue();
        if ($reference_fields_map = $this->getFieldsThatCanReferenceEntity($entity)) {
          $options = ['' => '(' . $this->t('Choose an field') . ')' ];
          foreach ($reference_fields_map as $entity_type_id => $field_info) {
            $entity_type = \Drupal::entityTypeManager()->getDefinition($entity_type_id);
            if ($entity_type->getStorageClass() === ContentEntityNullStorage::class) {
              continue;
            }
            /** @var  \Drupal\Core\Field\FieldDefinitionInterface[] $field_definitions */
            foreach ($field_info as $field_name => $field_definitions) {
              $bundles = array_keys($field_definitions);
              $options["$entity_type_id:$field_name"] = $entity_type->getPluralLabel() . " via " . $field_definitions[$bundles[0]]->getLabel();
            }
          }
          $form['views_options']['relationship'] = [
            '#type' => 'select',
            '#title' => 'fields',
            '#options' => $options,
            '#default_value' => $form_defaults['views_options']['relationship'],
          ];
          $this->addAjaxCallBack($form['views_options']['relationship']);
          if (!empty($form_defaults['views_options']['relationship'])) {
            list($field_entity_type_id, $field_name) = explode(':', $form_defaults['views_options']['relationship']);
            $selected_field_definitions = $reference_fields_map[$field_entity_type_id][$field_name];
            if (count($selected_field_definitions) > 1) {
              $entity_type = $this->entityTypeManager->getDefinition($field_entity_type_id);
              /** @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info */
              $entity_type_bundle_info = \Drupal::service('entity_type.bundle.info');
              $bundle_info = $entity_type_bundle_info->getBundleInfo($field_entity_type_id);
              foreach (array_keys($selected_field_definitions) as $bundle_name) {
                $bundle_options[$bundle_name] = $bundle_info[$bundle_name]['label'];
              }
              $form['views_options']['bundles'] = [
                '#title' => $entity_type->getBundleLabel(),
                '#type' => 'checkboxes',
                '#options' => $bundle_options,
                '#default_value' => $form_defaults['views_options']['bundles'],
              ];
              /** @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface $ed */
              $ed = \Drupal::service('entity_display.repository');
              $view_modes = $ed->getViewModes($field_entity_type_id);
              if ($view_modes) {
                $view_mode_options = [static::LABEL_DISPLAY => $this->t('Labels only')];
                foreach ($view_modes as $view_mode) {
                  $view_mode_options[$view_mode['id']] = $view_mode['label'];
                }
                $form['views_options']['display'] = [
                  '#title' => $this->t('Display as'),
                  '#type' => 'select',
                  '#options' => $view_mode_options,
                  '#required' => TRUE,
                  '#default_value' => $form_defaults['views_options']['display'],
                ];
              }
            }
            $form['views_options']['number'] = [
              '#title' => $this->t('How many to display'),
              '#type' => 'number',
              '#required' => TRUE,
              '#min' => 1,
              '#default_value' => $form_defaults['views_options']['number'],
            ];
          }
        }
      }
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $this->configuration['views_options'] = $form_state->getValue('views_options');
  }


  /**
   * Render API callback: gets the views_options elements.
   */
  public static function viewsOptionsAjaxCallback(array $form, FormStateInterface $form_state) {
   return $form['settings']['views_options'];
  }

  /**
   * Gets all the fields that can reference this entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface[]
   */
  private function getFieldsThatCanReferenceEntity(ContentEntityInterface $entity) {
    /** @var \Drupal\Core\Field\FieldDefinitionInterface[] $fields */
    $fields = [];
    /** @var \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager */
    $entity_field_manager = \Drupal::service('entity_field.manager');
    $field_map = $entity_field_manager->getFieldMapByFieldType('entity_reference');
    foreach ($field_map as $entity_type_id => $field_infos) {
      foreach ($field_infos as $field_name => $field_info) {
        foreach ($field_info['bundles'] as $bundle) {
          $field_definition = $entity_field_manager->getFieldDefinitions($entity_type_id, $bundle)[$field_name];
          if ($this->fieldCanReferenceEntity($field_definition, $entity)) {
            $fields[$entity_type_id][$field_definition->getName()][$bundle] = $field_definition;
          }
        }

      }
    }
    return $fields;
  }

  /**
   * Determines if a field can reference a particular entity.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *
   * @return bool
   */
  private function fieldCanReferenceEntity(FieldDefinitionInterface $field_definition, ContentEntityInterface $entity) {
    $settings = $field_definition->getSettings();
    if (isset($settings['target_type']) && $settings['target_type'] === $entity->getEntityTypeId()) {
      if ($target_bundles = $settings['handler_settings']['target_bundles'] ?? []) {
        if (in_array($entity->bundle(), $target_bundles)) {
          return TRUE;
        }
      }
      else {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Add the #ajax properties to trigger the form rebuild.
   *
   * @param array $element
   */
  private function addAjaxCallBack(array &$element) {
    $element['#ajax'] = [
      'callback' => [static::class, 'viewsOptionsAjaxCallback'],
      'wrapper' => 'views-options-wrapper',
    ];
  }

  /**
   * Gets the defaults to use with the form.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *
   * @return array
   */
  private function getFormDefaults(FormStateInterface $form_state) {
    $input = $form_state->getUserInput();
    $config = $this->getConfiguration();
    $defaults = [];
    if (isset($input['settings']['views_options'])) {
      $defaults['views_options'] = $input['settings']['views_options'];
    }
    else {
      $defaults['views_options'] = $config['views_options'];
    }
    if (isset($input['settings']['context_mapping'])) {
      $defaults['context_mapping']  = $input['settings']['context_mapping'];
    }
    else {
      $defaults['context_mapping'] = $config['context_mapping'];
    }
    return $defaults;
  }

}
