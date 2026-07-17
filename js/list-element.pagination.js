/**
 * @file
 * Load more and infinite scroll behavior for the List element.
 *
 * The wrapper element carries the endpoint URL, the pagination mode, and the
 * next offset as data attributes; every query-shaping setting lives server-
 * side in the stored component instance inputs.
 *
 * List elements can appear in the DOM after behaviors have been attached,
 * for example inside a code component's slot that is hydrated client-side.
 * Clicks are therefore handled by document-level delegation, and infinite
 * scroll sentinels are discovered through a mutation observer.
 *
 * @see Drupal\canvas\Plugin\Canvas\ComponentSource\ListComponent::addPagination()
 * @see Drupal\canvas\Controller\ApiListElementController
 */

(function (Drupal, once) {
  const loadingWrappers = new WeakSet();
  const observedSentinels = new WeakSet();
  let intersectionObserver = null;

  /**
   * Fetches the next page of a List element and appends its items.
   *
   * @param {HTMLElement} wrapper
   *   The .canvas-list-element wrapper carrying the pagination data
   *   attributes.
   */
  async function loadNextPage(wrapper) {
    if (loadingWrappers.has(wrapper)) {
      return;
    }
    loadingWrappers.add(wrapper);
    try {
      const url = new URL(
        wrapper.dataset.canvasListEndpoint,
        window.location.origin,
      );
      url.searchParams.set('offset', wrapper.dataset.canvasListOffset || '0');
      const response = await fetch(url, {
        headers: { Accept: 'application/json' },
      });
      if (!response.ok) {
        throw new Error(`Unexpected response status ${response.status}.`);
      }
      const { html, more } = await response.json();
      const items = wrapper.querySelector('.canvas-list');
      if (items && html) {
        const template = document.createElement('template');
        template.innerHTML = html;
        const appended = template.content.querySelectorAll(
          '.canvas-list__item',
        ).length;
        wrapper.dataset.canvasListOffset = String(
          (parseInt(wrapper.dataset.canvasListOffset, 10) || 0) + appended,
        );
        items.append(template.content);
      }
      if (!more) {
        wrapper
          .querySelectorAll(
            '.canvas-list-element__load-more, .canvas-list-element__sentinel',
          )
          .forEach((control) => control.remove());
      } else {
        // Re-arm the sentinel: observe() reports the current intersection
        // state, so if the sentinel is still in the viewport after appending
        // a page, the next page loads immediately.
        const sentinel = wrapper.querySelector(
          '.canvas-list-element__sentinel',
        );
        if (sentinel && intersectionObserver) {
          intersectionObserver.unobserve(sentinel);
          intersectionObserver.observe(sentinel);
        }
      }
    } catch {
      // A failed fetch (network hiccup, entity meanwhile unpublished) leaves
      // the already-rendered items untouched; the control stays usable.
    } finally {
      loadingWrappers.delete(wrapper);
    }
  }

  /**
   * Starts observing any infinite scroll sentinels not yet observed.
   */
  function observeSentinels() {
    document
      .querySelectorAll(
        '[data-canvas-list-mode="infinite_scroll"] .canvas-list-element__sentinel',
      )
      .forEach((sentinel) => {
        if (observedSentinels.has(sentinel)) {
          return;
        }
        observedSentinels.add(sentinel);
        if (!intersectionObserver) {
          intersectionObserver = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
              if (entry.isIntersecting) {
                const wrapper = entry.target.closest(
                  '[data-canvas-list-endpoint]',
                );
                if (wrapper) {
                  loadNextPage(wrapper);
                }
              }
            });
          });
        }
        intersectionObserver.observe(sentinel);
      });
  }

  Drupal.behaviors.canvasListElementPagination = {
    attach() {
      if (once('canvas-list-pagination', 'html').length === 0) {
        return;
      }
      document.addEventListener('click', (event) => {
        const button = event.target.closest('.canvas-list-element__load-more');
        const wrapper = button?.closest('[data-canvas-list-endpoint]');
        if (wrapper) {
          loadNextPage(wrapper);
        }
      });
      observeSentinels();
      // List elements may enter the DOM later (e.g. inside client-hydrated
      // slots); watch for their sentinels.
      new MutationObserver(observeSentinels).observe(document.documentElement, {
        childList: true,
        subtree: true,
      });
    },
  };
})(Drupal, once);
