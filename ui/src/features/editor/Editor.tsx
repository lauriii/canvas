import { useEffect } from 'react';
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
  selectEditorFrameContext,
  setEditorFrameContext,
  setFirstLoadComplete,
  unsetEditorFrameContext,
} from '@/features/ui/uiSlice';
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
  useReturnableLocation();
  const { isUndoable, dispatchUndo } = useUndoRedo();
  const latestError = useAppSelector(selectLatestError);
  const editorFrameContext = useAppSelector(selectEditorFrameContext);
  const params = useParams();
  const navigate = useNavigate();

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
  }, [dispatch, params.entityId, params.entityType]);

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
            onReset={() =>
              navigate(
                `/template/${params.entityType}/${params.bundle}/${params.viewMode}`,
                { replace: true },
              )
            }
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
      {renderContextContent()}
      <EditorFrame />
      <ContextualPanel />
      <div
        className={clsx(styles.editorInactive, {
          [styles.visible]: disable,
        })}
      ></div>
    </>
  );
};

export default Editor;
