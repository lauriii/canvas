<?php

declare(strict_types=1);

namespace Drupal\multi_frontend\Element;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Render\Attribute\RenderElement;
use Drupal\Core\Render\Element\RenderElementBase;
use Drupal\multi_frontend\Envelope\ComponentNode;
use Drupal\multi_frontend\Envelope\EnvelopeNodeInterface;
use Drupal\multi_frontend\Envelope\HtmlNode;
use Drupal\multi_frontend\ProducerInvoker;

/**
 * Renders a component from its producer.
 *
 * The one call site a module writes. Core decides, per request, whether this
 * subtree becomes HTML or a node in an envelope, using the same producer
 * call, the same validation and the same cacheability. That is what makes
 * serving the props over HTTP free for the module rather than a second
 * hand-written shape.
 *
 * Usage:
 * @code
 * $build = ProducedComponent::build('album.photo', $media);
 * @endcode
 *
 * The literal render-array form works too, and is what the envelope builder
 * looks for, but it does not carry render cache keys:
 * @code
 * $build = [
 *   '#type' => 'produced_component',
 *   '#producer' => 'album.photo',
 *   '#subject' => $media,
 * ];
 * @endcode
 */
#[RenderElement('produced_component')]
final class ProducedComponent extends RenderElementBase {

  /**
   * {@inheritdoc}
   */
  public function getInfo(): array {
    return [
      '#producer' => NULL,
      '#subject' => NULL,
      '#variant' => NULL,
      '#attributes' => [],
      '#pre_render' => [
        [static::class, 'preRenderProducedComponent'],
      ],
    ];
  }

  /**
   * Builds a produced-component element, with render cache keys.
   *
   * The keys are derived from the producer ID and the subject's identity,
   * both of which are known before the producer runs. On a cache hit the
   * producer is never invoked.
   *
   * @param string $producer_id
   *   The producer ID.
   * @param mixed $subject
   *   The subject to produce props for.
   * @param array<string, mixed> $extra
   *   (optional) Additional render array keys, such as '#attributes'.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public static function build(string $producer_id, mixed $subject, array $extra = []): array {
    $build = [
      '#type' => 'produced_component',
      '#producer' => $producer_id,
      '#subject' => $subject,
    ] + $extra;

    $keys = ProducerInvoker::cacheKeys($producer_id, $subject);
    if ($keys !== NULL) {
      $build['#cache']['keys'] = $keys;
      if ($subject instanceof EntityInterface) {
        $build['#cache']['tags'] = $subject->getCacheTags();
      }
    }
    return $build;
  }

  /**
   * Pre-render callback: produces the props and hands them to the SDC.
   *
   * @param array<string, mixed> $element
   *   The element.
   *
   * @return array<string, mixed>
   *   The element.
   */
  public static function preRenderProducedComponent(array $element): array {
    $invoker = \Drupal::service(ProducerInvoker::class);
    $cacheability = new CacheableMetadata();
    $node = $invoker->produceNode($element['#producer'], $element['#subject'], $cacheability);

    $build = [
      '#type' => 'component',
      '#component' => $node->componentId,
      '#props' => $node->props,
      '#attributes' => $element['#attributes'] ?? [],
    ];
    if (($element['#variant'] ?? NULL) !== NULL) {
      // SDC's own element understands variants, so a producer never has to.
      $build['#variant'] = $element['#variant'];
    }
    if ($node->slots !== []) {
      $build['#slots'] = \array_map(
        static fn (array $nodes): array => \array_map([static::class, 'renderNode'], $nodes),
        $node->slots,
      );
    }
    $cacheability->applyTo($build);

    $element['component'] = $build;
    return $element;
  }

  /**
   * Turns an envelope node back into a render array, for a Twig slot.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public static function renderNode(EnvelopeNodeInterface $node): array {
    $build = match (TRUE) {
      $node instanceof ComponentNode => [
        '#type' => 'component',
        '#component' => $node->componentId,
        '#props' => $node->props,
      ],
      $node instanceof HtmlNode => ['#markup' => $node->markup],
      default => [],
    };
    CacheableMetadata::createFromObject($node)->applyTo($build);
    return $build;
  }

}
