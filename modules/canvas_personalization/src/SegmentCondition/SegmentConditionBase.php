<?php

declare(strict_types=1);

namespace Drupal\canvas_personalization\SegmentCondition;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Base class for segment condition plugins.
 *
 * Provides `negate` support: subclasses implement ::doEvaluate() and
 * ::evaluate() applies the negation. Also provides the common services every
 * shipped condition needs (request stack, time, config factory); plugins with
 * other dependencies override ::create().
 *
 * @see \Drupal\canvas_personalization\SegmentCondition\SegmentConditionInterface
 *
 * @phpstan-consistent-constructor
 */
abstract class SegmentConditionBase extends PluginBase implements SegmentConditionInterface, ContainerFactoryPluginInterface {

  protected RequestStack $requestStack;
  protected TimeInterface $time;
  protected ConfigFactoryInterface $configFactory;

  public function __construct(array $configuration, string $plugin_id, array $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->setConfiguration($configuration);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->requestStack = $container->get(RequestStack::class);
    $instance->time = $container->get(TimeInterface::class);
    $instance->configFactory = $container->get(ConfigFactoryInterface::class);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  final public function evaluate(): bool {
    $result = $this->doEvaluate();
    return $this->configuration['negate'] ? !$result : $result;
  }

  /**
   * Evaluates the condition, ignoring the `negate` setting.
   */
  abstract protected function doEvaluate(): bool;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'id' => $this->getPluginId(),
      'negate' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguration(): array {
    return $this->configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration): static {
    $this->configuration = $configuration + $this->defaultConfiguration();
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   *
   * Segment conditions MUST NOT set cache tags — final on purpose.
   *
   * @see \Drupal\canvas_personalization\SegmentCondition\SegmentConditionInterface
   */
  final public function getCacheTags(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return Cache::PERMANENT;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['negate'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Negate'),
      '#default_value' => $this->configuration['negate'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['negate'] = (bool) $form_state->getValue('negate');
  }

  /**
   * Returns the request against which conditions evaluate.
   */
  protected function getRequest(): Request {
    $request = $this->requestStack->getCurrentRequest();
    \assert($request !== NULL);
    return $request;
  }

}
