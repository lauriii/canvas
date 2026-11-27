<?php

declare(strict_types=1);

namespace Drupal\canvas_headless\JsonRender;

use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\canvas_headless\CanvasContentSerializer;
use Drupal\canvas_headless\CanvasCustomElement;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\custom_elements\CustomElement;
use Drupal\custom_elements\CustomElementFormatNormalizerInterface;

/**
 * Serializes Custom Elements with Canvas identity and preview semantics.
 *
 * Canvas owns graph construction and preserves authored prop and slot names.
 * The internal format name leaves the generic json-render serializer unchanged.
 */
final class CanvasJsonRenderNormalizer implements CustomElementFormatNormalizerInterface {

  public const string FORMAT = 'canvas-json-render';

  /**
   * {@inheritdoc} */
  public function supportsFormat(string $format): bool {
    return $format === self::FORMAT;
  }

  /**
   * {@inheritdoc}
   *
   * Called through Custom Elements' tagged format normalizer dispatcher.
   *
   * @phpstan-ignore-next-line shipmonk.deadMethod
   */
  public function normalize(CustomElement $element, BubbleableMetadata $cache_metadata, string $format, array $context = []): array {
    if (!$this->supportsFormat($format)) {
      throw new \InvalidArgumentException("Unsupported Canvas json-render format: $format");
    }
    $preview = ($context['preview'] ?? FALSE) === TRUE;
    $root_container = $element->getTag() === 'renderless-container';
    $elements = $root_container ? [
      'el-0' => ['type' => 'renderless-container', 'props' => new \stdClass(), 'children' => []],
    ] : [];
    $next_id = $root_container ? 1 : 0;
    $references = $this->addCanvasElements($element, $elements, $next_id, $cache_metadata, $preview);
    $root = $references[0] ?? '';
    if ($references !== [] && ($root_container || \count($references) > 1)) {
      $root = $root_container ? 'el-0' : 'el-' . $next_id;
      $elements[$root] = ['type' => 'renderless-container', 'props' => new \stdClass(), 'children' => $references];
    }
    return ['root' => $root, 'elements' => $root === '' ? new \stdClass() : $elements];
  }

  /**
   * Adds component nodes while flattening transparent groups into references.
   *
   * @return list<string>
   */
  private function addCanvasElements(CustomElement $element, array &$elements, int &$next_id, BubbleableMetadata $cache_metadata, bool $preview): array {
    $cache_metadata->addCacheableDependency($element);
    $canvas_metadata = $element instanceof CanvasCustomElement ? $element->getCanvasMetadata() : NULL;
    $type = $canvas_metadata !== NULL ? $canvas_metadata['componentId'] : $element->getPrefixedTag();
    // The content API keeps server implementations opaque. Attached libraries
    // belong to response head assets, not frontend component registry entries.
    if (\str_starts_with($type, 'drupal-library-')) {
      return [];
    }
    $transparent = $type === 'renderless-container' || (!$preview && $type === CanvasContentSerializer::MARKER_ELEMENT);
    $key = $transparent ? '' : ($canvas_metadata !== NULL ? $canvas_metadata['componentUuid'] : 'el-' . $next_id++);
    if (!$transparent) {
      if (\array_key_exists($key, $elements)) {
        throw new \UnexpectedValueException("Duplicate Canvas component UUID in content document: $key");
      }
      $props = $element->getAttributes();
      $elements[$key] = ['type' => $type, 'props' => $props === [] ? new \stdClass() : $props, 'children' => []];
      // Only present when the request opted in and the selection is not
      // empty, so pinned front ends never see new keys.
      // @see \Drupal\canvas_headless\RenderConverter\CanvasRenderConverter::convertBuild()
      $styles = $element instanceof CanvasCustomElement ? $element->getStyles() : NULL;
      if ($styles !== NULL) {
        $elements[$key]['styles'] = $styles['styles'];
        $elements[$key]['attributes'] = $styles['attributes'] === [] ? new \stdClass() : $styles['attributes'];
      }
    }

    $references = [];
    foreach ($element->getSortedSlotsByName() as $name => $entries) {
      if ($type === 'drupal-markup' && $name === 'default' && self::containsOnlyMarkup($entries)) {
        $elements[$key]['props'] = (array) $elements[$key]['props'];
        $markup = '';
        foreach ($entries as $entry) {
          if ($entry['content'] instanceof MarkupInterface) {
            $markup .= (string) $entry['content'];
          }
          else {
            $cache_metadata->addCacheableDependency($entry['content']);
          }
        }
        $elements[$key]['props']['markup'] = $markup;
        continue;
      }
      $children = [];
      foreach ($entries as $entry) {
        $content = $entry['content'];
        if ($content instanceof CustomElement) {
          if ($preview && $canvas_metadata !== NULL && self::isEmptySlotPlaceholder($content)) {
            $cache_metadata->addCacheableDependency($content);
            $placeholder = 'el-' . $next_id++;
            $elements[$placeholder] = [
              'type' => CanvasContentSerializer::SLOT_PLACEHOLDER_ELEMENT,
              'props' => new \stdClass(),
              'children' => [],
            ];
            $children[] = $placeholder;
          }
          else {
            \array_push($children, ...$this->addCanvasElements($content, $elements, $next_id, $cache_metadata, $preview));
          }
        }
        elseif ($content instanceof MarkupInterface && (string) $content !== '') {
          $children[] = self::addMarkupElement((string) $content, $elements, $next_id);
        }
      }
      if ($transparent) {
        \array_push($references, ...$children);
      }
      elseif ($name === 'default') {
        $elements[$key]['children'] = $children;
      }
      elseif ($children !== []) {
        $elements[$key]['slots'][$name] = $children;
      }
    }
    return $transparent ? $references : [$key];
  }

  /**
   * Adds raw slot markup as a json-render element.
   */
  private static function addMarkupElement(string $markup, array &$elements, int &$next_id): string {
    $key = 'el-' . $next_id++;
    $elements[$key] = [
      'type' => 'drupal-markup',
      'props' => ['markup' => $markup],
      'children' => [],
    ];
    return $key;
  }

  /**
   * Whether entries contain only markup and attached-library bookkeeping.
   */
  private static function containsOnlyMarkup(array $entries): bool {
    foreach ($entries as $entry) {
      if (!$entry['content'] instanceof MarkupInterface && !($entry['content'] instanceof CustomElement && \str_starts_with($entry['content']->getTag(), 'drupal-library-'))) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Recognizes the server-side placeholder for a declared empty Canvas slot.
   */
  private static function isEmptySlotPlaceholder(CustomElement $element): bool {
    $entries = \array_values($element->getSortedSlotsByName()['default'] ?? []);
    return $element->getTag() === 'drupal-markup'
      && \count($entries) === 1
      && $entries[0]['content'] instanceof MarkupInterface
      && (string) $entries[0]['content'] === ComponentTreeItemList::EMPTY_SLOT_PLACEHOLDER;
  }

}
