<?php

namespace Drupal\canvas\Plugin\DataType;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\GeneratedUrl;
use Drupal\Core\Render\AttachmentsInterface;
use Drupal\Core\Render\AttachmentsTrait;

/**
 * Like core's GeneratedUrl class, plus access result, plus optionality.
 *
 * @see \Drupal\Core\GeneratedUrl
 * @internal
 */
final class MaybeUrl extends AccessResult implements AccessResultReasonInterface, RefinableCacheableDependencyInterface, AttachmentsInterface {

  use AttachmentsTrait;

  private ?string $reason = NULL;
  private ?string $url;

  public function __construct(
    ?GeneratedUrl $generatedUrl,
    AccessResultInterface $access,
  ) {
    if (!$access->isAllowed() && $generatedUrl !== NULL) {
      throw new \InvalidArgumentException('$generatedUrl must be NULL if inaccessible.');
    }
    if ($access->isAllowed() && $generatedUrl === NULL) {
      throw new \InvalidArgumentException('$generatedUrl must not be NULL if accessible.');
    }

    if ($access instanceof AccessResultReasonInterface) {
      $this->reason = $access->getReason();
    }
    $this->addCacheableDependency($access);

    if ($generatedUrl) {
      $this->url = $generatedUrl->getGeneratedUrl();
      $this->addCacheableDependency($generatedUrl);
      // TRICKY: some URLs bubble attachments.
      $this->addAttachments($generatedUrl->getAttachments());
    }
    else {
      $this->url = NULL;
    }
  }

  public function getUrl(): ?string {
    return $this->url;
  }

  /**
   * {@inheritdoc}
   */
  public function getReason() {
    return (string) $this->reason;
  }

  /**
   * {@inheritdoc}
   */
  public function setReason($reason) {
    throw new \LogicException(\sprintf("%s objects are immutable, the reason cannot be modified.", __CLASS__));
  }

}
