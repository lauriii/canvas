import { useEffect } from 'react';
import { Navigate, useParams } from 'react-router';

import { useAppDispatch } from '@/app/hooks';
import CodeEditorUi from '@/features/code-editor/CodeEditorUi';
import { setActivePanel } from '@/features/ui/primaryPanelSlice';
import WelcomeCodeEditor from '@/features/welcome/WelcomeCodeEditor';
import { useGetCodeComponentsQuery } from '@/services/componentAndLayout';
import { hasPermission } from '@/utils/permissions';

const CodeEditorContainer = () => {
  const { codeComponentId } = useParams();
  const dispatch = useAppDispatch();
  const canEditCodeComponents = hasPermission('codeComponents');
  const { data: codeComponents, isLoading } = useGetCodeComponentsQuery(
    undefined,
    {
      skip: !codeComponentId || !canEditCodeComponents,
    },
  );

  /**
   * Set the active panel to "code" when the code editor loads
   */
  useEffect(() => {
    dispatch(setActivePanel('code'));
  }, [dispatch]);

  if (!codeComponentId || !canEditCodeComponents) {
    return <WelcomeCodeEditor />;
  }

  if (isLoading) {
    return null;
  }

  if (codeComponents?.[codeComponentId]?.type === 'external') {
    return <Navigate to="/code-editor/" replace />;
  }

  return <CodeEditorUi />;
};

export default CodeEditorContainer;
