import { useCallback, useEffect, useMemo, useRef } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { ArrowLeftIcon } from '@radix-ui/react-icons';
import { IconButton } from '@radix-ui/themes';

import { getCanvasSettings } from '@/utils/drupal-globals';

import type React from 'react';
import type { PageExtension } from '@drupal-canvas/types';

import styles from './ExtensionPage.module.css';

const ExtensionPage: React.FC = () => {
  const { extensionId, '*': subPath } = useParams<{
    extensionId: string;
    '*': string;
  }>();
  const navigate = useNavigate();
  const pageExtensions: PageExtension[] =
    getCanvasSettings()?.pageExtensions ?? [];

  const ext = pageExtensions.find((e) => e.id === extensionId);

  // Recompute src when the extensionId changes so navigating between page
  // extensions actually switches the iframe.
  const iframeSrc = useMemo(() => {
    if (!ext) return '';
    return subPath ? `${ext.extension_url}#/${subPath}` : ext.extension_url;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [extensionId]);

  const iframeRef = useRef<HTMLIFrameElement>(null);

  // Listen for navigation messages from the extension iframe and update the
  // parent address bar without reloading the iframe.
  useEffect(() => {
    function onMessage(e: MessageEvent) {
      // Ignore messages from any source other than our iframe, and from any
      // origin other than our own (the sandboxed iframe could have navigated
      // itself elsewhere).
      if (e.source !== iframeRef.current?.contentWindow) return;
      if (e.origin !== window.location.origin) return;
      if (!e.data) return;

      // The extension iframe is sandboxed without allow-top-navigation, so
      // links out of the extension into the Canvas app itself cannot use
      // target="_top". Extensions instead post an in-app route (relative to
      // the router basename, e.g. `/preview/canvas_page/1/full?language=fr`)
      // and the parent navigates. `//host` (and its `\` spellings — URL
      // parsing normalizes backslashes to slashes) would be a cross-origin
      // URL, not an in-app route: reject those.
      if (
        e.data.type === 'canvas:navigate-app' &&
        typeof e.data.path === 'string' &&
        e.data.path.startsWith('/') &&
        !e.data.path.startsWith('//') &&
        !e.data.path.includes('\\')
      ) {
        navigate(e.data.path);
        return;
      }

      if (
        e.data.type !== 'canvas:navigate' ||
        typeof e.data.subPath !== 'string'
      )
        return;

      const sub: string = e.data.subPath;
      // Use replaceState directly — not navigate() — so React Router's history
      // stack is never touched. This means navigate(-1) from the side menu
      // always goes back correctly with no race condition.
      const base = window.location.pathname.replace(/\/app\/.*$/, '');
      const newPath = sub
        ? `${base}/app/${extensionId}/${sub}`
        : `${base}/app/${extensionId}`;
      window.history.replaceState(window.history.state, '', newPath);
    }

    window.addEventListener('message', onMessage);
    return () => window.removeEventListener('message', onMessage);
  }, [extensionId, navigate]);

  const handleBack = useCallback(() => {
    // React Router keeps its position in history.state.idx; at 0 this is the
    // first in-app entry (e.g. a deep link), so going back would leave Canvas.
    if (window.history.state?.idx > 0) {
      navigate(-1);
    } else {
      navigate('/');
    }
  }, [navigate]);

  if (!ext) {
    return (
      <div className={styles.notFound}>Extension not found: {extensionId}</div>
    );
  }

  return (
    <div className={styles.page}>
      <div className={styles.header}>
        <IconButton
          variant="ghost"
          color="gray"
          aria-label="Go back"
          onClick={handleBack}
        >
          <ArrowLeftIcon />
        </IconButton>
      </div>

      <iframe
        key={extensionId}
        ref={iframeRef}
        // @todo Only add 'allow-same-origin' if the extension is loaded from a local file.
        sandbox="allow-scripts allow-same-origin allow-downloads"
        id={`canvas-extension-page-${ext.id}`}
        src={iframeSrc}
        className={styles.iframe}
        title={ext.name}
      />
    </div>
  );
};

export default ExtensionPage;
