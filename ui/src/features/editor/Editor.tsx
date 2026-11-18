import { useEffect } from 'react';
import clsx from 'clsx';
import { useNavigate, useParams } from 'react-router';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import ErrorBoundary from '@/components/error/ErrorBoundary';
import ConflictWarning from '@/features/editor/ConflictWarning';
import ContentNotEditable, {
  NO_EDITABLE_TREE_REASON,
} from '@/features/editor/ContentNotEditable';
import EditorFrame from '@/features/editorFrame/EditorFrame';
import {
  clearLatestError,
  selectLatestError,
} from '@/features/error-handling/queryErrorSlice';
import { FORM_TYPES } from '@/features/form/constants';
import { clearFieldValues } from '@/features/form/formStateSlice';
import LayoutLoader from '@/features/layout/LayoutLoader';
import { setUpdatePreview } from '@/features/layout/layoutModelSlice';
import PatternLayout from '@/features/layout/PatternLayout';
import TemplateLayout from '@/features/layout/TemplateLayout';
import { resetPageData } from '@/features/pageData/pageDataSlice';
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
    dispatch(resetPageData());
    dispatch(clearFieldValues(FORM_TYPES.ENTITY_FORM));
    // A query error belongs to the previously open entity or template. Template
    // routes change bundle, view mode or preview entity, not entityId.
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
      latestError.message.includes(NO_EDITABLE_TREE_REASON)
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
      case 'pattern':
        return (
          <ErrorBoundary
            title="An error has occurred while fetching the pattern."
            variant="alert"
            onReset={() => {
              navigate('/', { replace: true });
            }}
            resetButtonText="Return to library"
          >
            <PatternLayout />
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
