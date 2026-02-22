import { useCallback, useEffect, useState } from 'react';
import { Allotment } from 'allotment';
import clsx from 'clsx';
import { useParams } from 'react-router';
import { useNavigate } from 'react-router-dom';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import ErrorBoundary from '@/components/error/ErrorBoundary';
import ContextualPanel from '@/components/panel/ContextualPanel';
import ConflictWarning from '@/features/editor/ConflictWarning';
import EditorFrame from '@/features/editorFrame/EditorFrame';
import { selectLatestError } from '@/features/error-handling/queryErrorSlice';
import LayoutLoader from '@/features/layout/LayoutLoader';
import { setUpdatePreview } from '@/features/layout/layoutModelSlice';
import TemplateLayout from '@/features/layout/TemplateLayout';
import {
  EditorFrameContext,
  EditorFrameMode,
  selectEditorFrameContext,
  selectEditorFrameMode,
  selectIsMultiSelect,
  selectSelectedComponentUuid,
  setEditorFrameContext,
  setFirstLoadComplete,
  unsetEditorFrameContext,
} from '@/features/ui/uiSlice';
import useEditorNavigation from '@/hooks/useEditorNavigation';
import useReturnableLocation from '@/hooks/useReturnableLocation';
import { useUndoRedo } from '@/hooks/useUndoRedo';
import { getLayoutItem, setLayoutItem } from '@/utils/layoutStorage';

import styles from '@/features/editor/Editor.module.css';

const EDITOR_SIDEBAR_LAYOUT_KEY = 'canvas-editor-sidebar-layout';
const SIDEBAR_DEFAULT_PCT = 24;
const SIDEBAR_MIN_PX = 320;
const SIDEBAR_MAX_PX = 640;
const MIN_MAIN_PANE_PX = 200;
const MIN_PANE_PCT = 10;
const DEFAULT_SIDEBAR_SIZES: [number, number] = [
  100 - SIDEBAR_DEFAULT_PCT,
  SIDEBAR_DEFAULT_PCT,
];

function isValidSidebarSizes(value: unknown): value is [number, number] {
  return (
    Array.isArray(value) &&
    value.length === 2 &&
    typeof value[0] === 'number' &&
    typeof value[1] === 'number' &&
    value[0] >= MIN_PANE_PCT &&
    value[1] >= MIN_PANE_PCT
  );
}

// Invalid stored data or parse errors fall back to defaults.
function loadSidebarSizes(): [number, number] {
  const stored = getLayoutItem(EDITOR_SIDEBAR_LAYOUT_KEY);
  if (stored) {
    try {
      const parsed = JSON.parse(stored) as unknown;
      if (isValidSidebarSizes(parsed)) {
        return parsed;
      }
    } catch {
      // Ignore parse errors.
    }
  }
  return DEFAULT_SIDEBAR_SIZES;
}

interface EditorProps {
  context: EditorFrameContext;
  disable?: boolean;
}

const Editor: React.FC<EditorProps> = ({ context, disable = false }) => {
  const dispatch = useAppDispatch();
  const navigate = useNavigate();
  useReturnableLocation();
  const { isUndoable, dispatchUndo } = useUndoRedo();
  const latestError = useAppSelector(selectLatestError);
  const editorFrameContext = useAppSelector(selectEditorFrameContext);
  const editorFrameMode = useAppSelector(selectEditorFrameMode);
  const selectedComponent = useAppSelector(selectSelectedComponentUuid);
  const isMultiSelect = useAppSelector(selectIsMultiSelect);
  const [sidebarSizes, setSidebarSizes] = useState(loadSidebarSizes);
  const { entityId, entityType, bundle, viewMode } = useParams();
  const { navigateToTemplateEditor } = useEditorNavigation();

  const isTemplateContext = editorFrameContext === EditorFrameContext.TEMPLATE;
  const isPanelHidden =
    editorFrameMode === EditorFrameMode.INTERACTIVE ||
    (isTemplateContext && !isMultiSelect && !selectedComponent);

  const handleSidebarResize = useCallback((sizes: number[]) => {
    if (sizes.length === 2) {
      setSidebarSizes([sizes[0], sizes[1]]);
      setLayoutItem(
        EDITOR_SIDEBAR_LAYOUT_KEY,
        JSON.stringify([sizes[0], sizes[1]]),
      );
    }
  }, []);

  useEffect(() => {
    dispatch(setEditorFrameContext(context));
    return () => {
      dispatch(setFirstLoadComplete(false));
      dispatch(unsetEditorFrameContext());
    };
  }, [context, dispatch]);

  useEffect(() => {
    dispatch(setUpdatePreview(false));
    // When the entityId or entityType changes, we want to reset the first load complete state
    dispatch(setFirstLoadComplete(false));
  }, [dispatch, entityId, entityType]);

  if (latestError) {
    if (latestError.status === '409') {
      // There has been an editing conflict and the user should be blocked from continuing!
      return <ConflictWarning />;
    }
  }

  if (context === 'none' || editorFrameContext === 'none') {
    return null;
  }

  // Render content based on context.
  const renderContextContent = () => {
    switch (editorFrameContext) {
      case 'entity':
        return (
          <ErrorBoundary
            title="An unexpected error has occurred while fetching the layout."
            variant="alert"
            onReset={isUndoable ? dispatchUndo : undefined}
            resetButtonText={isUndoable ? 'Undo last action' : undefined}
          >
            <LayoutLoader />
          </ErrorBoundary>
        );
      case 'template':
        return (
          <ErrorBoundary
            title="An error has occurred while fetching the template."
            variant="alert"
            onReset={() => {
              if (entityType && bundle && viewMode) {
                navigateToTemplateEditor(
                  {
                    entityType,
                    bundle,
                    viewMode,
                  },
                  {
                    replace: true,
                  },
                );
              } else {
                navigate('/', { replace: true });
              }
            }}
            resetButtonText="Return to templates"
          >
            <TemplateLayout />
          </ErrorBoundary>
        );
      default:
        return null;
    }
  };

  const editorContent = (
    <>
      {renderContextContent()}
      <EditorFrame />
    </>
  );

  return (
    <>
      <Allotment
        defaultSizes={sidebarSizes}
        minSize={MIN_MAIN_PANE_PX}
        onChange={handleSidebarResize}
        className={styles.editorAllotment}
      >
        <Allotment.Pane minSize={MIN_MAIN_PANE_PX}>
          <div className={styles.editorMainPane}>{editorContent}</div>
        </Allotment.Pane>
        <Allotment.Pane
          visible={!isPanelHidden}
          minSize={SIDEBAR_MIN_PX}
          maxSize={SIDEBAR_MAX_PX}
          preferredSize={`${SIDEBAR_DEFAULT_PCT}%`}
        >
          <div className={styles.editorSidebarPane}>
            <ContextualPanel />
          </div>
        </Allotment.Pane>
      </Allotment>
      <div
        className={clsx(styles.editorInactive, {
          [styles.visible]: disable,
        })}
      ></div>
    </>
  );
};

export default Editor;
