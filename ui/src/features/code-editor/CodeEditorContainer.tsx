import { useEffect } from 'react';
import { useParams } from 'react-router';

import { useAppDispatch } from '@/app/hooks';
import CodeEditorUi from '@/features/code-editor/CodeEditorUi';
import {
  setActivePanel,
  unsetActivePanel,
} from '@/features/ui/primaryPanelSlice';
import WelcomeCodeEditor from '@/features/welcome/WelcomeCodeEditor';
import { hasPermission } from '@/utils/permissions';

const CodeEditorContainer = () => {
  const { codeComponentId } = useParams();
  const dispatch = useAppDispatch();

  /**
   * Set the active panel to "code" when the code editor loads
   * Close the panel when it unloads.
   */
  useEffect(() => {
    dispatch(setActivePanel('code'));
    return () => {
      dispatch(unsetActivePanel());
    };
  }, [dispatch]);

  if (!codeComponentId || !hasPermission('codeComponents')) {
    return <WelcomeCodeEditor />;
  }

  return <CodeEditorUi />;
};

export default CodeEditorContainer;
