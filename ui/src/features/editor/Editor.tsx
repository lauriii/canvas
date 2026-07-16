import { useEffect } from 'react';
import clsx from 'clsx';
import { useParams } from 'react-router';
import { useNavigate } from 'react-router-dom';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import ErrorBoundary from '@/components/error/ErrorBoundary';
import ConflictWarning from '@/features/editor/ConflictWarning';
import ContentNotEditable from '@/features/editor/ContentNotEditable';
import EditorFrame from '@/features/editorFrame/EditorFrame';
import {
  clearLatestError,
  selectLatestError,
} from '@/features/error-handling/queryErrorSlice';
import LayoutLoader from '@/features/layout/LayoutLoader';
import { setUpdatePreview } from '@/features/layout/layoutModelSlice';
import TemplateLayout from '@/features/layout/TemplateLayout';
import {
  selectEditorFrameContext,
  setEditorFrameContext,
  setFirstLoadComplete,
  unsetEditorFrameContext,
} from '@/features/ui/uiSlice';
import useEditorNavigation from '@/hooks/useEditorNavigation';
import useReturnableLocation from '@/hooks/useReturnableLocation';
import { useUndoRedo } from '@/hooks/useUndoRedo';

import type { EditorFrameContext } from '@/features/ui/uiSlice';

import styles from '@/features/editor/Editor.module.css';

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
  const { entityId, entityType, bundle, viewMode, previewEntityId } =
    useParams();
  const { navigateToTemplateEditor } = useEditorNavigation();

  useEffect(() => {
    dispatch(setEditorFrameContext(context));
    return () => {
      dispatch(setFirstLoadComplete(false));
      dispatch(unsetEditorFrameContext());
    };
  }, [context, dispatch]);

  useEffect(() => {
    dispatch(setUpdatePreview(false));
    dispatch(setFirstLoadComplete(false));
    // A query error (409 conflict, per-content 403) belongs to the previously
    // open entity or template; opening another one starts fresh instead of
    // showing the stale error screen. Template routes vary by bundle, view
    // mode, and preview entity while entityId stays undefined, so those
    // params participate too.
    dispatch(clearLatestError());
  }, [dispatch, entityId, entityType, bundle, viewMode, previewEntityId]);

  if (latestError) {
    if (latestError.status === '409') {
      return <ConflictWarning />;
    }
    // Per-content editing: the entity became non-editable in Canvas (its
    // template stopped exposing slots) while it was open. Degrade gracefully
    // instead of the generic "unexpected error" boundary.
    if (
      latestError.status === '403' &&
      latestError.message.includes('no editable component tree')
    ) {
      return <ContentNotEditable />;
    }
  }

  if (context === 'none' || editorFrameContext === 'none') {
    return null;
  }

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

  return (
    <>
      <div className={styles.editorMainPane}>
        {renderContextContent()}
        <EditorFrame />
      </div>
      <div
        className={clsx(styles.editorInactive, {
          [styles.visible]: disable,
        })}
      />
    </>
  );
};

export default Editor;
