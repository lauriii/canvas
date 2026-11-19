<?php

declare(strict_types=1);

namespace Drupal\canvas\TypedData;

use Drupal\canvas\Plugin\DataType\ComputedDataTypeWithCacheabilityTrait;
use Drupal\canvas\Plugin\Field\FieldType\VideoEmbedItem;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\GeneratedUrl;
use Drupal\Core\TypedData\Plugin\DataType\Uri;
use Drupal\Core\Url;
use Drupal\media\IFrameUrlHelper;
use Drupal\media\OEmbed\Endpoint;

/**
 * Computes the URL of core's oEmbed iframe for a video URL.
 *
 * Computes nothing for URLs of providers that are not allowed: the iframe URL
 * carries a hash that makes core render the provider's HTML.
 *
 * @see \Drupal\canvas\Plugin\Field\FieldType\VideoEmbedItem
 * @see \Drupal\media\Plugin\Field\FieldFormatter\OEmbedFormatter::viewElements()
 *
 * @internal
 */
final class VideoEmbedIframeUrl extends Uri implements CacheableDependencyInterface {

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
  public function getString(): string {
    return $this->getValue()?->getGeneratedUrl() ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function setValue($value, $notify = TRUE): void {
    // Computed and read-only, but the parent must be notified of changes.
    $this->invalidateComputedValue();
    if ($notify && isset($this->parent)) {
      $this->parent->onChange($this->name);
    }
  }

  public function computeValue(): ?GeneratedUrl {
    $this->cacheability = new CacheableMetadata();
    $item = $this->getParent();
    \assert($item instanceof VideoEmbedItem);
    $url = $item->get('value')->getValue();
    if (!\is_string($url) || $url === '') {
      return NULL;
    }
    $is_allowed = \array_any(VideoEmbedItem::getAllowedEndpoints(), fn (Endpoint $endpoint): bool => $endpoint->matchUrl($url));
    if (!$is_allowed) {
      return NULL;
    }

    // Same as core's oEmbed field formatter, without a maximum size: the
    // provider's iframe fills core's iframe, which the component sizes.
    // @see core/modules/media/css/oembed.frame.css
    $iframe_url = Url::fromRoute('media.oembed_iframe', [], [
      'absolute' => TRUE,
      'query' => [
        'url' => $url,
        'max_width' => 0,
        'max_height' => 0,
        'hash' => \Drupal::service(IFrameUrlHelper::class)->getHash($url, 0, 0),
      ],
    ]);
    $media_settings = \Drupal::config('media.settings');
    if ($domain = $media_settings->get('iframe_domain')) {
      $iframe_url->setOption('base_url', $domain);
    }
    $generated_url = $iframe_url->toString(TRUE);
    $generated_url->addCacheableDependency($media_settings);
    return $generated_url;
  }

}
