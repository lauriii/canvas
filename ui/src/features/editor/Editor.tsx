import { useEffect } from 'react';
import clsx from 'clsx';
import { useParams } from 'react-router';
import { useNavigate } from 'react-router-dom';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import ErrorBoundary from '@/components/error/ErrorBoundary';
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
  const { entityId, entityType, bundle, viewMode } = useParams();
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
  }, [dispatch, entityId, entityType]);

  if (latestError) {
    if (latestError.status === '409') {
      return <ConflictWarning />;
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
