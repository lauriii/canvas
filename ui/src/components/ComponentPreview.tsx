import { useEffect, useRef } from 'react';
import clsx from 'clsx';

import { getBaseUrl, getDrupalSettings } from '@/utils/drupal-globals';

import type { CanvasComponent } from '@/types/Component';
import type { Pattern } from '@/types/Pattern';

import styles from './ComponentPreview.module.css';

interface ComponentPreviewProps {
  componentListItem: CanvasComponent | Pattern;
}

const drupalSettings = getDrupalSettings();
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

const ComponentPreview: React.FC<ComponentPreviewProps> = ({
  componentListItem,
}) => {
  const component = componentListItem;
  const defaultIframeWidth = 1200;
  const defaultIframeHeight = 800;
  const defaultPreviewWidth = 300;
  const defaultPreviewHeight = 200;

  const css = drupalSettings?.canvas.globalAssets.css + component.css;
  const js_footer =
    drupalSettings?.canvas.globalAssets.jsFooter + component.js_footer;
  const js_header =
    drupalSettings?.canvas.globalAssets.jsHeader + component.js_header;

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
      const widthScale = maxWidth / offsetWidth;
      const heightScale = maxHeight / offsetHeight;
      const scale = Math.min(widthScale, heightScale);
      scalingElement.style.transform = `scale(${scale})`;
      tooltipElement.style.position = 'relative';
      tooltipElement.style.width = `${offsetWidth * scale}px`;
      tooltipElement.style.height = `${offsetHeight * scale}px`;
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
        const offsetWidth = currentWrapper.scrollWidth;
        const offsetHeight = currentWrapper.scrollHeight;
        applyScaling(
          offsetWidth,
          offsetHeight,
          scalingElement,
          tooltipElement,
          defaultIframeWidth,
          defaultIframeHeight,
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
      const componentWrapper =
        iframe.contentDocument!.querySelector('#component-wrapper');

      const offsetWidth = componentWrapper!.scrollWidth;
      const offsetHeight = componentWrapper!.scrollHeight;

      applyScaling(
        offsetWidth,
        offsetHeight,
        scalingElement,
        tooltipElement,
        defaultPreviewWidth,
        defaultPreviewHeight,
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

  return (
    <div
      className={clsx(styles.wrapper, 'canvas-app', 'canvas-previewTooltip')}
    >
      <div className={clsx('canvas-scaled', styles.scaled)}>
        <iframe
          ref={iframeRef}
          title={component.name}
          width={defaultIframeWidth}
          height={defaultIframeHeight}
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
