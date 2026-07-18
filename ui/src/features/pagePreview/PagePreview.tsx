import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import { useLocation, useParams } from 'react-router';
import { useSearchParams } from 'react-router-dom';
import { AlertDialog, Button, Flex } from '@radix-ui/themes';
import { skipToken } from '@reduxjs/toolkit/query';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectLayout,
  selectModel,
  selectUpdatePreview,
} from '@/features/layout/layoutModelSlice';
import { useHeadlessDraftSession } from '@/features/layout/preview/useHeadlessDraftSession';
import { selectPageData } from '@/features/pageData/pageDataSlice';
import {
  selectPreviewHtml,
  setSnapshotHTML,
} from '@/features/pagePreview/previewSlice';
import { useGetPageLayoutQuery } from '@/services/componentAndLayout';
import {
  useGetSnapshotPreviewQuery,
  useOrderedPostPreviewMutation,
} from '@/services/preview';
import { getCanvasHeadlessSettings } from '@/utils/drupal-globals';
import { getViewportSizes } from '@/utils/viewports';

import type React from 'react';
import type { HeadlessSettings } from '@drupal-canvas/types';

import styles from './PagePreview.module.css';

/**
 * Embeds the configured frontend app in the standalone page preview.
 *
 * The counterpart of the editor frame's HeadlessPreview: when the
 * canvas_headless module is enabled, the app owns the rendering here too,
 * driven by the same draft-session protocol. The width selector keeps
 * working — it only sizes the iframe.
 */
const HeadlessPagePreview: React.FC<{
  settings: HeadlessSettings;
  width: string;
}> = ({ settings, width }) => {
  const iframeRef = useRef<HTMLIFrameElement>(null);
  const { entityId, entityType } = useParams();
  const { statusText } = useHeadlessDraftSession(
    iframeRef,
    settings,
    entityType,
    entityId,
  );

  return (
    <div className={styles.PagePreviewContainer}>
      <p
        data-testid="canvas-headless-status"
        aria-live="polite"
        className={styles.headlessStatus}
      >
        {statusText}
      </p>
      <iframe
        ref={iframeRef}
        title="Page preview"
        style={{ width }}
        className={styles.PagePreviewIframe}
      ></iframe>
    </div>
  );
};

const PagePreview = () => {
  const dispatch = useAppDispatch();
  const location = useLocation();
  const layout = useAppSelector(selectLayout);
  const updatePreview = useAppSelector(selectUpdatePreview);
  const model = useAppSelector(selectModel);
  const entity_form_fields = useAppSelector(selectPageData);
  const frameSrcDoc = useAppSelector(selectPreviewHtml);
  const [postPreview] = useOrderedPostPreviewMutation();
  const { entityId, entityType, bundle, viewMode, width } = useParams();
  const [searchParams] = useSearchParams();
  const { showBoundary } = useErrorBoundary();
  const [widthVal, setWidthVal] = useState('100%');
  const [linkIntercepted, setLinkIntercepted] = useState('');
  const [submissionIntercepted, setSubmissionIntercepted] = useState(false);
  // Get viewport sizes (supports theme-level customization).
  const viewportSizes = useMemo(() => getViewportSizes(), []);

  // Derive the active language directly from the URL search params.
  const language = searchParams.get('language') ?? '';

  // Determine template context from the URL path.
  const isContentTemplate = location.pathname.includes('/preview/template');

  // The same gate as useHeadlessPreviewSettings, keyed on the URL instead of
  // the editor frame context, which is not set on this route: content
  // templates have no public path for the app to enter at, so they keep the
  // Drupal-rendered preview.
  const headlessSettings = isContentTemplate
    ? undefined
    : getCanvasHeadlessSettings();

  // Only fetch the language preview when we are on a preview route.
  const isPreview = isContentTemplate || location.pathname.includes('/preview');

  // Always fetch the default-language layout so page data (title etc.) is
  // seeded correctly on a fresh page load at a language preview URL. This hits
  // the generic /layout route, which only supports canvas_page entities, so it
  // is skipped for templates (their preview entity is rendered via the
  // snapshot query below).
  useGetPageLayoutQuery(
    entityId && entityType && !isContentTemplate
      ? { entityId, entityType }
      : skipToken,
  );

  // Language preview: auto-fetch whenever language/entity changes.
  const { error: languagePreviewError } = useGetSnapshotPreviewQuery(
    {
      entityType: entityType!,
      entityId: entityId!,
      language,
      isTemplate: isContentTemplate,
      templateInfo: { bundle, viewMode },
    },
    {
      skip:
        !!headlessSettings ||
        !isPreview ||
        (!language && !isContentTemplate) ||
        !entityType ||
        !entityId,
      refetchOnMountOrArgChange: true,
    },
  );

  // Clear snapshot HTML when leaving language preview and handle errors.
  useEffect(() => {
    if (languagePreviewError) {
      showBoundary(languagePreviewError);
    }
    if (!language) return;
    return () => {
      dispatch(setSnapshotHTML(''));
    };
  }, [language, languagePreviewError, showBoundary, dispatch]);

  useEffect(() => {
    const sendPreviewRequest = async () => {
      // Template previews are rendered by the snapshot query against the
      // content-template route. This POST hits the generic /layout route, which
      // only supports canvas_page entities; skip it for a content template's
      // preview. In headless mode the app owns the rendering, so the srcdoc
      // HTML this generates would never be shown.
      if (isContentTemplate || headlessSettings || !entityType || !entityId) {
        return;
      }
      try {
        await postPreview({
          layout,
          model,
          entity_form_fields,
          entityId,
          entityType,
        });
      } catch (err) {
        showBoundary(err);
      }
    };
    if (updatePreview) {
      sendPreviewRequest().then(() => {});
    }
  }, [
    layout,
    model,
    postPreview,
    entity_form_fields,
    entityId,
    entityType,
    isContentTemplate,
    headlessSettings,
    updatePreview,
    showBoundary,
  ]);

  useEffect(() => {
    if (!width || width === 'full') {
      setWidthVal('100%');
    } else {
      viewportSizes.find((vs) => {
        if (width === vs.id) {
          setWidthVal(`${vs.width}px`);
          return true;
        }
      });
    }
  }, [width, viewportSizes]);

  // Register the preview link/form intercept listener once.
  useEffect(() => {
    function handlePreviewLinkClick(event: MessageEvent) {
      if (event.data && event.data.canvasPreviewClickedUrl) {
        setLinkIntercepted(event.data.canvasPreviewClickedUrl);
      }
      if (event.data && event.data.canvasPreviewFormSubmitted) {
        setSubmissionIntercepted(true);
      }
    }
    window.addEventListener('message', handlePreviewLinkClick);

    return () => {
      window.removeEventListener('message', handlePreviewLinkClick);
    };
  }, []);

  const handleDialogOpenChange = (isOpen: boolean) => {
    if (!isOpen) {
      setLinkIntercepted('');
      setSubmissionIntercepted(false);
    }
  };

  const handleLinkOpenClick = useCallback(() => {
    window.open(linkIntercepted, '_blank');
  }, [linkIntercepted]);

  // When the canvas_headless module embeds a frontend app, the app owns
  // the rendering, exactly as in the editor frame.
  if (headlessSettings) {
    return <HeadlessPagePreview settings={headlessSettings} width={widthVal} />;
  }

  return (
    <>
      <div className={styles.PagePreviewContainer}>
        <div className={styles.controls}></div>
        <iframe
          title="Page preview"
          style={{ width: widthVal }}
          srcDoc={frameSrcDoc}
          className={styles.PagePreviewIframe}
        ></iframe>
      </div>
      <AlertDialog.Root
        open={!!linkIntercepted || submissionIntercepted}
        defaultOpen={false}
        onOpenChange={handleDialogOpenChange}
      >
        <AlertDialog.Content maxWidth="450px">
          {linkIntercepted && (
            <>
              <AlertDialog.Title>Link clicked</AlertDialog.Title>
              <AlertDialog.Description size="2" mb="4">
                You attempted to open a link in the preview but it was
                intercepted before you were navigated away from this page.
              </AlertDialog.Description>

              <AlertDialog.Description size="2">
                The link goes to <strong>{linkIntercepted}</strong>
              </AlertDialog.Description>

              <Flex gap="3" mt="4" justify="end">
                <AlertDialog.Cancel>
                  <Button variant="soft" color="gray">
                    Close
                  </Button>
                </AlertDialog.Cancel>
                <AlertDialog.Action>
                  <Button
                    variant="solid"
                    color="blue"
                    onClick={handleLinkOpenClick}
                  >
                    Open in new window
                  </Button>
                </AlertDialog.Action>
              </Flex>
            </>
          )}
          {submissionIntercepted && (
            <>
              <AlertDialog.Title>Form submitted</AlertDialog.Title>
              <AlertDialog.Description size="2" mb="4">
                You attempted to submit a form in the preview but it was
                intercepted before you were navigated away from this page.
              </AlertDialog.Description>

              <Flex gap="3" mt="4" justify="end">
                <AlertDialog.Cancel>
                  <Button variant="soft" color="gray">
                    Close
                  </Button>
                </AlertDialog.Cancel>
              </Flex>
            </>
          )}
        </AlertDialog.Content>
      </AlertDialog.Root>
    </>
  );
};

export default PagePreview;
