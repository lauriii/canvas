import { useEffect, useRef } from 'react';
import clsx from 'clsx';

import {
  calculateComponentPreviewSizing,
  COMPONENT_PREVIEW_HEIGHT,
  COMPONENT_PREVIEW_IFRAME_HEIGHT,
  COMPONENT_PREVIEW_IFRAME_WIDTH,
  COMPONENT_PREVIEW_WIDTH,
} from '@/components/componentPreviewSizing';
import { useGetPreviewAssetsQuery } from '@/services/componentAndLayout';
import { getBaseUrl } from '@/utils/drupal-globals';

import type { CanvasComponent } from '@/types/Component';
import type { Pattern } from '@/types/Pattern';

import styles from './ComponentPreview.module.css';

interface ComponentPreviewProps {
  componentListItem: CanvasComponent | Pattern;
}

const baseUrl = getBaseUrl();

// Helper to convert asset paths to absolute.
function makeAssetPathsAbsolute(
  template: HTMLTemplateElement,
  baseUrl: string,
) {
  // Convert relative asset URLs in <link> and <script> to absolute URLs using baseUrl
  const assetNodes = template.content.querySelectorAll(
    'link[href], script[src]',
  );
  assetNodes.forEach((node) => {
    if (node.tagName === 'LINK') {
      const href = node.getAttribute('href');
      if (href && href.startsWith('/')) {
        const newHref = window.location.origin + href;
        node.setAttribute('href', newHref);
      }
    } else if (node.tagName === 'SCRIPT') {
      const src = node.getAttribute('src');
      if (src && src.startsWith('/')) {
        const newSrc = window.location.origin + src;
        node.setAttribute('src', newSrc);
      }
    }
  });
  // Also update component-url and renderer-url on <canvas-island> tags
  const islandNodes = template.content.querySelectorAll('canvas-island');
  islandNodes.forEach((node) => {
    ['component-url', 'renderer-url'].forEach((attr) => {
      const val = node.getAttribute(attr);
      if (val && val.startsWith('/')) {
        const newVal = window.location.origin + val;
        node.setAttribute(attr, newVal);
      }
    });
  });
}

// Whether a selector matches anything inside the wrapper. Pseudo-elements are
// stripped, as they never match an element.
function matchesInside(wrapper: HTMLElement, selector: string): boolean {
  try {
    return (
      wrapper.querySelector(selector.replace(/::[\w-]+(\([^)]*\))?/g, '')) !==
      null
    );
  } catch {
    return false;
  }
}

// Whether the previewed component relies on CSS container queries: it either
// is (or contains) a query container, or it has @container rules that apply to
// it. Only rules matching an element inside the wrapper count, so unrelated
// @container rules in the global theme CSS don't affect other components.
function usesContainerQueries(wrapper: HTMLElement): boolean {
  const view = wrapper.ownerDocument.defaultView;
  if (!view) {
    return false;
  }

  const elements = wrapper.querySelectorAll('*');
  for (const element of elements) {
    if (view.getComputedStyle(element).containerType !== 'normal') {
      return true;
    }
  }

  // The rules belong to the <iframe>'s realm, so use its constructors for the
  // instanceof checks.
  const { CSSContainerRule, CSSStyleRule } = view as typeof window;
  const walk = (rules: CSSRuleList, parentSelector: string | null): boolean => {
    for (const rule of rules) {
      if (rule instanceof CSSContainerRule) {
        // A @container nested inside a style rule applies to that rule's
        // elements.
        if (parentSelector && matchesInside(wrapper, parentSelector)) {
          return true;
        }
        for (const child of rule.cssRules) {
          if (
            child instanceof CSSStyleRule &&
            matchesInside(wrapper, child.selectorText)
          ) {
            return true;
          }
        }
      }
      if ('cssRules' in rule) {
        const selector =
          rule instanceof CSSStyleRule ? rule.selectorText : parentSelector;
        if (walk((rule as CSSGroupingRule).cssRules, selector)) {
          return true;
        }
      }
    }
    return false;
  };

  for (const sheet of wrapper.ownerDocument.styleSheets) {
    try {
      if (walk(sheet.cssRules, null)) {
        return true;
      }
    } catch {
      // Cross-origin stylesheets can't be read.
    }
  }
  return false;
}

// A container-query component has no intrinsic width: in the shrink-to-fit
// wrapper a query container collapses to the wrapper's min-width, and a
// component querying an ancestor finds no container at all. Render those at
// the full <iframe> width, inside a container.
function applyContainerQuerySupport(wrapper: HTMLElement) {
  if (usesContainerQueries(wrapper)) {
    wrapper.style.display = 'block';
    wrapper.style.containerType = 'inline-size';
  }
}

const ComponentPreview: React.FC<ComponentPreviewProps> = ({
  componentListItem,
}) => {
  const component = componentListItem;
  const { data: previewAssets, error: previewAssetsError } =
    useGetPreviewAssetsQuery();

  const css = (previewAssets?.css ?? '') + component.css;
  const js_footer = (previewAssets?.jsFooter ?? '') + component.js_footer;
  const js_header = (previewAssets?.jsHeader ?? '') + component.js_header;

  const markup = component.default_markup;
  const base_url = window.location.origin + baseUrl;

  let html = `
<html>
	<head>
    <base href=${base_url} />
		<meta charset="utf-8">
		${css}
		${js_header}
		<style>
			html{
				height: auto !important;
				min-height: 100%;
			}
			body {
        background-color: #FFF;
        background-image: none;
			}
			#component-wrapper {
        overflow: hidden;
        display: inline-block;
        min-width: 120px;
			}
		</style>
	</head>
	<body>
    <div id="component-wrapper">
      ${markup}
    </div>
		${js_footer}
	</body>
</html>`;

  const template = document.createElement('template');
  template.innerHTML = html;
  // We need to convert asset paths to absolute URLs so that they load correctly
  // in the srcDoc-using iframe.
  makeAssetPathsAbsolute(template, baseUrl);
  html = template.innerHTML;

  // If there are <canvas-island> nodes, we use an interval to watch for size
  // changes after hydration. The refs below are for managing the interval and
  // corresponding data.
  const iframeRef = useRef<HTMLIFrameElement>(null);
  const hydrationIntervalId = useRef<number | null>(null);
  const hydrationTimeoutId = useRef<number | null>(null);
  const hydrationBaseline = useRef<{ width: number; height: number } | null>(
    null,
  );
  const hydrationComponentId = component.id;

  // Consolidated scaling logic
  function applyScaling(
    offsetWidth: number,
    offsetHeight: number,
    scalingElement: HTMLDivElement,
    tooltipElement: HTMLDivElement,
    maxWidth: number,
    maxHeight: number,
  ) {
    scalingElement.style.width = `${offsetWidth}px`;
    scalingElement.style.height = `${offsetHeight}px`;
    if (offsetWidth > maxWidth || offsetHeight > maxHeight) {
      const sizing = calculateComponentPreviewSizing(
        offsetWidth,
        offsetHeight,
        maxWidth,
        maxHeight,
      );
      scalingElement.style.transform = `scale(${sizing.scale})`;
      tooltipElement.style.position = 'relative';
      tooltipElement.style.width = `${sizing.width}px`;
      tooltipElement.style.height = `${sizing.height}px`;
    }
    tooltipElement.style.visibility = 'visible';
  }

  // This is called for components that have canvas-island elements, which can
  // potentially change size after hydration. We set an interval to check for
  // size changes every 250ms until 5s is reached. If a change is detected, we
  // run the same scaling logic. Ideally this logic would respond upon hydration
  // completion. If such a thing is possible, please replace the below!
  function startHydrationInterval(
    iframe: HTMLIFrameElement,
    scalingElement: HTMLDivElement,
    tooltipElement: HTMLDivElement,
  ) {
    const getWrapper = () =>
      iframe.contentDocument?.getElementById('component-wrapper');
    const wrapper = getWrapper();
    if (!wrapper) return;

    // Store the initial dimensions of the component wrapper as a baseline
    // to compare against.
    hydrationBaseline.current = {
      width: wrapper.offsetWidth,
      height: wrapper.offsetHeight,
    };
    let elapsed = 0;
    hydrationIntervalId.current = window.setInterval(() => {
      if (component.id !== hydrationComponentId) {
        clearInterval(hydrationIntervalId.current!);
        hydrationIntervalId.current = null;
        return;
      }
      const currentWrapper = getWrapper();
      if (!currentWrapper) return;
      const w = currentWrapper.offsetWidth;
      const h = currentWrapper.offsetHeight;
      if (
        w !== hydrationBaseline.current!.width ||
        h !== hydrationBaseline.current!.height
      ) {
        clearInterval(hydrationIntervalId.current!);
        hydrationIntervalId.current = null;
        // Run scaling logic again
        applyContainerQuerySupport(currentWrapper);
        const offsetWidth = currentWrapper.scrollWidth;
        const offsetHeight = currentWrapper.scrollHeight;
        applyScaling(
          offsetWidth,
          offsetHeight,
          scalingElement,
          tooltipElement,
          COMPONENT_PREVIEW_IFRAME_WIDTH,
          COMPONENT_PREVIEW_IFRAME_HEIGHT,
        );
        return;
      }
      elapsed += 250;
      if (elapsed >= 5000) {
        clearInterval(hydrationIntervalId.current!);
        hydrationIntervalId.current = null;
      }
    }, 250);
    hydrationTimeoutId.current = window.setTimeout(() => {
      if (hydrationIntervalId.current) {
        clearInterval(hydrationIntervalId.current);
        hydrationIntervalId.current = null;
      }
    }, 5000);
  }

  const iframeOnLoadHandler = () => {
    const iframe = window.document.querySelector(
      'iframe[data-preview-component-id]',
    ) as HTMLIFrameElement;
    const tooltipElement = document.querySelector(
      '.canvas-previewTooltip',
    ) as HTMLDivElement;
    const scalingElement = document.querySelector(
      '.canvas-scaled',
    ) as HTMLDivElement;

    if (iframe) {
      const componentWrapper = iframe.contentDocument!.querySelector(
        '#component-wrapper',
      ) as HTMLElement;
      applyContainerQuerySupport(componentWrapper);

      const offsetWidth = componentWrapper!.scrollWidth;
      const offsetHeight = componentWrapper!.scrollHeight;

      applyScaling(
        offsetWidth,
        offsetHeight,
        scalingElement,
        tooltipElement,
        COMPONENT_PREVIEW_WIDTH,
        COMPONENT_PREVIEW_HEIGHT,
      );

      const hasCanvasIsland =
        iframe.contentDocument?.querySelector('canvas-island');
      if (hasCanvasIsland) {
        // If there are canvas-island elements, we need to watch for size
        // changes after hydration.
        startHydrationInterval(iframe, scalingElement, tooltipElement);
      }
    }
  };

  useEffect(() => {
    // Clean up any previous interval/timeouts if the component changes.
    return () => {
      if (hydrationIntervalId.current) {
        clearInterval(hydrationIntervalId.current);
        hydrationIntervalId.current = null;
      }
      if (hydrationTimeoutId.current) {
        clearTimeout(hydrationTimeoutId.current);
        hydrationTimeoutId.current = null;
      }
    };
  }, [hydrationComponentId]);

  useEffect(() => {
    if (previewAssetsError) {
      console.error(
        'Failed to load component preview assets:',
        previewAssetsError,
      );
    }
  }, [previewAssetsError]);

  // Without the front-end theme's global assets the preview would render, and
  // be measured, unstyled. Show no preview until they have loaded.
  if (!previewAssets) {
    return null;
  }

  return (
    <div
      className={clsx(styles.wrapper, 'canvas-app', 'canvas-previewTooltip')}
    >
      <div className={clsx('canvas-scaled', styles.scaled)}>
        <iframe
          ref={iframeRef}
          title={component.name}
          width={COMPONENT_PREVIEW_IFRAME_WIDTH}
          height={COMPONENT_PREVIEW_IFRAME_HEIGHT}
          data-preview-component-id={component.id}
          srcDoc={html}
          className={clsx(styles.iframe)}
          onLoad={iframeOnLoadHandler}
        />
      </div>
    </div>
  );
};

export default ComponentPreview;
