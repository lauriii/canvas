import {
  useCallback,
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
} from 'react';
import clsx from 'clsx';
import { useErrorBoundary } from 'react-error-boundary';
import { useLocation, useParams, useSearchParams } from 'react-router';
import { AlertDialog, Button, Flex } from '@radix-ui/themes';
import { skipToken } from '@reduxjs/toolkit/query';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import EditorFrame from '@/features/editorFrame/EditorFrame';
import {
  selectLayout,
  selectModel,
  selectUpdatePreview,
  setUpdatePreview,
} from '@/features/layout/layoutModelSlice';
import { PreviewDomProvider } from '@/features/layout/preview/PreviewDomContext';
import { PreviewGeometryProvider } from '@/features/layout/preview/PreviewGeometryContext';
import { useHeadlessDraftSession } from '@/features/layout/preview/useHeadlessDraftSession';
import Viewport from '@/features/layout/preview/Viewport';
import ViewportSelector from '@/features/layout/preview/ViewportSelector';
import { selectPageData } from '@/features/pageData/pageDataSlice';
import {
  selectPreviewHtml,
  setSnapshotHTML,
  setSnapshotTitle,
} from '@/features/pagePreview/previewSlice';
import {
  selectViewportWidth,
  setFirstLoadComplete,
} from '@/features/ui/uiSlice';
import { useCanvasHeadlessSettings } from '@/hooks/useCanvasHeadlessSettings';
import { useGetPageLayoutQuery } from '@/services/componentAndLayout';
import {
  useGetSnapshotPreviewQuery,
  useQueuedPostPreviewMutation,
} from '@/services/preview';
import { getViewportSizes } from '@/utils/viewports';

import type React from 'react';
import type { HeadlessSettings } from '@drupal-canvas/types';

import toolbarStyles from '@/features/layout/preview/ViewportToolbar.module.css';
import styles from './PagePreview.module.css';

/**
 * Shows a page of a headless frontend in another language at a viewport
 * width, scaled down to fit the space when it is wider.
 *
 * The viewport is the editor's own: it stays the same when the language
 * changes, and in the editor after it.
 *
 * @todo Show it on the canvas of the editor, as a page that Drupal renders is, once the frontend reports its height to this view.
 */
const LanguageViewport = ({ children }: { children: React.ReactNode }) => {
  const viewportWidth = useAppSelector(selectViewportWidth);
  const stageRef = useRef<HTMLDivElement>(null);
  const [stage, setStage] = useState({ width: 0, height: 0 });

  useLayoutEffect(() => {
    if (!stageRef.current) {
      return;
    }
    const observer = new ResizeObserver(([entry]) =>
      setStage({
        width: entry.contentRect.width,
        height: entry.contentRect.height,
      }),
    );
    observer.observe(stageRef.current);
    return () => observer.disconnect();
  }, []);

  const scale =
    viewportWidth && stage.width ? Math.min(1, stage.width / viewportWidth) : 1;

  return (
    <div className={styles.viewportPane} data-testid="canvas-language-viewport">
      <div className={toolbarStyles.toolbar}>
        <ViewportSelector buttonClassName={toolbarStyles.toolbarButton} />
      </div>
      <div ref={stageRef} className={styles.viewportStage}>
        {/* Always rendered: a headless frontend connects to the frame on
            mount. Hidden until the space for it is known. */}
        <div
          className={styles.viewportFrame}
          style={{
            left: (stage.width - viewportWidth * scale) / 2,
            width: viewportWidth,
            height: stage.height / scale,
            transform: `scale(${scale})`,
            visibility: viewportWidth && stage.width ? undefined : 'hidden',
          }}
        >
          {children}
        </div>
      </div>
    </div>
  );
};

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
  viewMode?: string;
  language?: string;
  entityId?: string;
  isLanguageView?: boolean;
}> = ({ settings, width, viewMode, language, entityId, isLanguageView }) => {
  const iframeRef = useRef<HTMLIFrameElement>(null);
  const { entityType } = useParams();
  const { statusText } = useHeadlessDraftSession(
    iframeRef,
    settings,
    entityType,
    entityId,
    undefined,
    undefined,
    { viewMode, language },
  );
  const iframe = (
    <iframe
      ref={iframeRef}
      title="Page preview"
      style={isLanguageView ? undefined : { width }}
      className={styles.PagePreviewIframe}
    ></iframe>
  );

  return (
    <div
      className={clsx(
        styles.PagePreviewContainer,
        isLanguageView && styles.languageView,
      )}
    >
      <p
        data-testid="canvas-headless-status"
        aria-live="polite"
        className={styles.headlessStatus}
      >
        {statusText}
      </p>
      {isLanguageView ? <LanguageViewport>{iframe}</LanguageViewport> : iframe}
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
  const [postPreview] = useQueuedPostPreviewMutation();
  const {
    entityId: routeEntityId,
    previewEntityId,
    entityType,
    bundle,
    viewMode,
    width,
  } = useParams();
  // The template editor names the entity it previews differently.
  const entityId = routeEntityId ?? previewEntityId;
  const [searchParams] = useSearchParams();
  const { showBoundary } = useErrorBoundary();
  const [widthVal, setWidthVal] = useState('100%');
  const [linkIntercepted, setLinkIntercepted] = useState('');
  const [submissionIntercepted, setSubmissionIntercepted] = useState(false);
  // Get viewport sizes (supports theme-level customization).
  const viewportSizes = useMemo(() => getViewportSizes(), []);

  // Derive the active language directly from the URL search params.
  const language = searchParams.get('language') ?? '';

  // The preview shows a page the way visitors see it, in any language. Outside
  // of it, this shows a page or template in another language than its own,
  // inside the editor.
  const isPreviewRoute = location.pathname.startsWith('/preview/');
  const isLanguageView = !isPreviewRoute && !!language;

  // Determine template context from the URL path.
  const isContentTemplate =
    location.pathname.startsWith('/preview/template/') ||
    location.pathname.startsWith('/template/');

  const headlessSettings = useCanvasHeadlessSettings();

  // Only fetch the language preview when we are on a preview route.
  const isPreview = isContentTemplate || isPreviewRoute || isLanguageView;

  // Always fetch the default-language layout so page data (title etc.) is
  // seeded correctly on a fresh page load at a language preview URL. This hits
  // the generic /layout route, which only supports canvas_page entities, so it
  // is skipped for templates (their preview entity is rendered via the
  // snapshot query below).
  const {
    fulfilledTimeStamp: layoutFetchedAt,
    isUninitialized: isLayoutSkipped,
    refetch: refetchLayout,
  } = useGetPageLayoutQuery(
    entityId && entityType && !isContentTemplate
      ? { entityId, entityType }
      : skipToken,
  );

  // Language preview: auto-fetch whenever language/entity changes.
  const {
    error: languagePreviewError,
    isFetching: isSnapshotFetching,
    isUninitialized: isSnapshotSkipped,
    refetch: refetchSnapshot,
  } = useGetSnapshotPreviewQuery(
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

  // The layout in the store can be that of another page: the page lists link
  // straight to this view. Nothing may post it to this page, also not the
  // preview this view opens.
  useEffect(() => {
    if (isLanguageView) {
      dispatch(setUpdatePreview(false));
    }
  }, [isLanguageView, dispatch]);

  // The canvas centers the page once it has loaded, for every page shown.
  useEffect(() => {
    if (!isLanguageView) {
      return;
    }
    dispatch(setFirstLoadComplete(false));
    return () => {
      dispatch(setFirstLoadComplete(false));
    };
  }, [isLanguageView, entityId, entityType, dispatch]);

  // The translations of the page change in a translation module, often in
  // another tab: load them again whenever the view shows another language.
  useEffect(() => {
    if (isLanguageView && !isLayoutSkipped) {
      refetchLayout();
    }
    // Only when the language changes, not when the query state does.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isLanguageView, language]);

  // The default-language layout clears the snapshot whenever it arrives, also
  // when it is fetched again while this view is open: fetch the snapshot again.
  useEffect(() => {
    if (language && layoutFetchedAt && !isSnapshotSkipped) {
      refetchSnapshot();
    }
  }, [language, layoutFetchedAt, isSnapshotSkipped, refetchSnapshot]);

  // Clear snapshot HTML and title when leaving language preview and handle errors.
  useEffect(() => {
    if (languagePreviewError) {
      showBoundary(languagePreviewError);
    }
    if (!language) return;
    return () => {
      dispatch(setSnapshotHTML(''));
      dispatch(setSnapshotTitle(''));
    };
  }, [language, languagePreviewError, showBoundary, dispatch]);

  useEffect(() => {
    const sendPreviewRequest = async () => {
      // Template previews are rendered by the snapshot query against the
      // content-template route. This POST hits the generic /layout route, which
      // only supports canvas_page entities; skip it for a content template's
      // preview. In headless mode the app owns the rendering, so the srcdoc
      // HTML this generates would never be shown.
      // A view in another language is rendered by the snapshot query. The
      // layout in the store can then be that of another page, because the
      // page lists link straight to this route: it must not be posted.
      if (
        isContentTemplate ||
        headlessSettings ||
        language ||
        !entityType ||
        !entityId
      ) {
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
    language,
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
    return (
      <HeadlessPagePreview
        settings={headlessSettings}
        width={widthVal}
        viewMode={isContentTemplate ? viewMode : undefined}
        language={language || undefined}
        entityId={entityId}
        isLanguageView={isLanguageView}
      />
    );
  }

  return (
    <>
      {isLanguageView ? (
        // The canvas of the editor, with its viewports, panning and zooming,
        // without the controls that edit a page.
        <div className={styles.languageCanvas}>
          <EditorFrame>
            <PreviewGeometryProvider>
              <PreviewDomProvider>
                <Viewport
                  frameSrcDoc={frameSrcDoc}
                  isFetching={isSnapshotFetching}
                  editable={false}
                />
              </PreviewDomProvider>
            </PreviewGeometryProvider>
          </EditorFrame>
        </div>
      ) : (
        <div className={styles.PagePreviewContainer}>
          <div className={styles.controls}></div>
          <iframe
            title="Page preview"
            style={{ width: widthVal }}
            srcDoc={frameSrcDoc}
            className={styles.PagePreviewIframe}
          ></iframe>
        </div>
      )}
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
