<?php

declare(strict_types=1);

namespace Drupal\canvas\TypedData;

use Drupal\canvas\Entity\ParametrizedImageStyle;
use Drupal\canvas\Plugin\DataType\ComputedDataTypeWithCacheabilityTrait;
use Drupal\canvas\Plugin\DataType\UriTemplate;
use Drupal\canvas\Plugin\Field\FieldTypeOverride\ImageItemOverride;
use Drupal\Component\Plugin\DependentPluginInterface;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\Plugin\DataType\EntityReference;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\GeneratedUrl;
use Drupal\file\Entity\File;

/**
 * Computes URI template with a `{width}` variable to populate `<img srcset>`.
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/API/HTMLImageElement/srcset#value
 * @see https://tools.ietf.org/html/rfc6570
 * @internal
 */
final class ImageDerivativeWithParametrizedWidth extends UriTemplate implements CacheableDependencyInterface, DependentPluginInterface {

  use ComputedDataTypeWithCacheabilityTrait {
    getValue as private traitGetValue;
  }

  private ?GeneratedUrl $computedValue;

  /**
   * {@inheritdoc}
   */
  public function getValue(): ?GeneratedUrl {
    return $this->traitGetValue();
  }

  /**
   * {@inheritdoc}
   */
  public function getCastedValue(): ?string {
    return $this->getValue()?->getGeneratedUrl();
  }

  private static function getParametrizedImageStyle(): ParametrizedImageStyle {
    // @phpstan-ignore-next-line
    return ParametrizedImageStyle::load('canvas_parametrized_width');
  }

  /**
   * {@inheritdoc}
   *
   * Returns NULL when no derivative image can be generated for the referenced
   * file: the image toolkit can only process a limited set of extensions, and
   * e.g. SVG is not among them.
   *
   * @see \Drupal\image\Entity\ImageStyle::supportsUri()
   */
  public function computeValue() : ?GeneratedUrl {
    // Because this image style is an enforced dependency of the Canvas module,
    // it is possible to assume it always exists. Because this computed property
    // is also only present when Canvas is installed.
    // @see config/install/image.style.canvas_parametrized_width.yml
    // @see \Drupal\canvas\Plugin\Field\FieldTypeOverride\ImageItemOverride::propertyDefinitions()
    $parametrized_image_style = $this->getParametrizedImageStyle();
    // A `return NULL` must populate cacheability explicitly.
    // @see \Drupal\canvas\Plugin\DataType\ComputedDataTypeWithCacheabilityTrait::computeIfNeeded()
    $this->cacheability = CacheableMetadata::createFromObject($parametrized_image_style);

    if ($this->getParent() === NULL) {
      return NULL;
    }
    \assert($this->getParent() instanceof ImageItemOverride);

    $entity = $this->getParent()->get('entity');

    // The image field may still be empty.
    if ($entity === NULL) {
      return NULL;
    }
    \assert($entity instanceof EntityReference);
    $file = $entity->getTarget()?->getValue();
    \assert($file instanceof File);

    \assert(\is_string($file->getFileUri()));
    // No derivative image can be generated for a file whose extension the image
    // toolkit does not support (for example SVG): requesting one would only
    // yield an error response. Fall back to just the original image.
    if (!$parametrized_image_style->supportsUri($file->getFileUri())) {
      $this->cacheability->addCacheableDependency($file);
      return NULL;
    }

    $url_template = $parametrized_image_style->buildUrlTemplate($file->getFileUri());
    \assert(str_contains($url_template, '{width}'));

    // Transform absolute to relative URL template.
    $file_url_generator = \Drupal::service(FileUrlGeneratorInterface::class);
    \assert($file_url_generator instanceof FileUrlGeneratorInterface);
    $url_template = $file_url_generator->transformRelative($url_template);
    \assert(str_contains($url_template, '{width}'));
    return (new GeneratedUrl())->setGeneratedUrl($url_template)
      ->addCacheableDependency($parametrized_image_style)
      ->addCacheableDependency($file);
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    return [
      'config' => [$this->getParametrizedImageStyle()->getConfigDependencyName()],
    ];
  }

}
