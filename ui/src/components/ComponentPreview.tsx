import type React from 'react';
import { useEffect } from 'react';
import styles from './ComponentPreview.module.css';
import clsx from 'clsx';
import Panel from '@/components/Panel';
import type {
  ComponentListItem,
  SectionListItem,
} from '@/components/list/List';

interface ComponentPreviewProps {
  componentListItem: ComponentListItem | SectionListItem;
}

const { drupalSettings } = window;

const ComponentPreview: React.FC<ComponentPreviewProps> = ({
  componentListItem,
}) => {
  const component = componentListItem;
  const defaultIframeWidth = 1200;
  const defaultIframeHeight = 800;
  const defaultPreviewWidth = 300;
  const defaultPreviewHeight = 200;

  const css = drupalSettings?.xb.globalAssets.css + component.css;
  const js_footer =
    drupalSettings?.xb.globalAssets.jsFooter + component.js_footer;
  const js_header =
    drupalSettings?.xb.globalAssets.jsHeader + component.js_header;

  const markup = component.default_markup;
  const base_url = window.location.origin + drupalSettings?.path.baseUrl;

  const html = `
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
			    padding: 20px;
			    overflow: hidden;
			    display: inline-block;
			    min-width: 120px;

			    &.empty {
                  min-height: 100px;
                  background-color: #DDD;
			    }
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

  const blob = new Blob([html], { type: 'text/html' });
  const blobSrc = URL.createObjectURL(blob);

  useEffect(() => {
    return () => {
      URL.revokeObjectURL(blobSrc);
    };
  }, [blobSrc]);

  const iframeOnLoadHandler = () => {
    const iframe = window.document.querySelector(
      'iframe[data-preview-component-id]',
    ) as HTMLIFrameElement;

    if (iframe) {
      const componentWrapper =
        iframe.contentDocument!.querySelector('#component-wrapper');

      const offsetWidth = componentWrapper!.scrollWidth;
      let offsetHeight = componentWrapper!.scrollHeight;

      // since the component wrapper has padding 20px for top and bottom,
      // so if the height is less than 45, this component is almost rendered empty.
      if (offsetHeight < 45) {
        componentWrapper!.classList.add('empty');
        offsetHeight = componentWrapper!.scrollHeight;
      }

      iframe.parentElement!.style.width = `${offsetWidth}px`;
      iframe.parentElement!.style.height = `${offsetHeight}px`;
      if (
        offsetWidth > defaultPreviewWidth ||
        offsetHeight > defaultPreviewHeight
      ) {
        // If we are here, then one or more component dimensions
        // exceed the preview maximums. We begin by determining
        // how much each dimension exceeds their maximum.
        const widthScale = defaultPreviewWidth / offsetWidth;
        const heightScale = defaultPreviewHeight / offsetHeight;
        iframe.parentElement!.style.transform = `scale(${Math.min(widthScale, heightScale)})`;
      }
      iframe.parentElement!.style.visibility = 'visible';
    }
  };

  return (
    <Panel className={styles.Wrapper}>
      <iframe
        title={component.name}
        width={defaultIframeWidth}
        height={defaultIframeHeight}
        data-preview-component-id={component.id}
        src={blobSrc}
        className={clsx('IFrame', styles.IFrame)}
        onLoad={iframeOnLoadHandler}
      />
    </Panel>
  );
};

export default ComponentPreview;
