<?php

declare(strict_types=1);

namespace Drupal\canvas\Event;

use Drupal\canvas\Entity\PageVariant;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyTrait;
use Drupal\Core\Entity\FieldableEntityInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Lets modules select a page variant before the site default applies.
 *
 * Dispatched when neither the entity (a canvas_page) nor its full-view content
 * template selects an existing page variant. The first listener to set a
 * variant wins: setting one stops propagation, and the site default no longer
 * applies. Without one, the site default applies, or none.
 *
 * Every caller of the resolver dispatches this event: front-end page
 * rendering, the editor's previews and layout API, and the canvas_headless
 * content API. So the current request is not necessarily the page's own
 * request; it may be an API or preview request.
 *
 * Listeners must add the cacheability of everything their decision depends on
 * to the event, also when they set no variant: the resolved variant, and so
 * the page, varies by it.
 *
 * @code
 * final class FrontPageVariantSubscriber implements EventSubscriberInterface {
 *
 *   public function __construct(private readonly PathMatcherInterface $pathMatcher) {}
 *
 *   public function onSelect(PageVariantSelectionEvent $event): void {
 *     $event->addCacheContexts(['url.path.is_front']);
 *     if ($this->pathMatcher->isFrontPage() && $variant = PageVariant::load('front')) {
 *       $event->addCacheableDependency($variant);
 *       $event->setVariant($variant);
 *     }
 *   }
 *
 *   public static function getSubscribedEvents(): array {
 *     return [PageVariantSelectionEvent::class => 'onSelect'];
 *   }
 *
 * }
 * @endcode
 *
 * @see \Drupal\canvas\PageVariantResolver::resolve()
 */
final class PageVariantSelectionEvent extends Event implements RefinableCacheableDependencyInterface {

  use RefinableCacheableDependencyTrait;

  private ?PageVariant $variant = NULL;

  /**
   * Constructs a PageVariantSelectionEvent.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $entity
   *   The content entity being rendered, or NULL for routes that render no
   *   content entity (for example a 404 or the login page).
   * @param bool $isPreview
   *   Whether the variant renders in an editor preview. The resolver then
   *   renders the variant's valid auto-saved draft: set the published variant.
   */
  public function __construct(
    public readonly ?FieldableEntityInterface $entity,
    public readonly bool $isPreview,
  ) {}

  /**
   * Selects the page variant, and stops propagation.
   */
  public function setVariant(PageVariant $variant): void {
    $this->variant = $variant;
    $this->stopPropagation();
  }

  /**
   * Returns the selected page variant, if any.
   */
  public function getVariant(): ?PageVariant {
    return $this->variant;
  }

}
