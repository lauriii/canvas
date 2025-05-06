import { useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAppSelector } from '@/app/hooks';
import { selectBaseUrl } from '@/features/configuration/configurationSlice';
import { DEFAULT_REGION } from '@/features/ui/uiSlice';

const { drupalSettings } = window;

/**
 * Hook for editor navigation functions
 * Handles URL/route based navigation for regions and entities
 */
export function useEditorNavigation() {
  const navigate = useNavigate();
  const baseUrl = useAppSelector(selectBaseUrl);

  const setSelectedRegion = useCallback(
    (regionId: string) => {
      const baseUrl = '/editor';
      if (regionId === DEFAULT_REGION) {
        navigate(`${baseUrl}`);
      } else {
        navigate(`${baseUrl}/region/${regionId}`);
      }
    },
    [navigate],
  );

  // @todo revisit approach (like using routing) in https://www.drupal.org/i/3502887
  const setEditorEntity = useCallback(
    (entityType: string, entityId: string) => {
      window.location.href = `${baseUrl}xb/${entityType}/${entityId}`;
    },
    [baseUrl],
  );

  const editorNavUtils = {
    setSelectedRegion,
    setEditorEntity,
  };

  drupalSettings.xb.navUtils = editorNavUtils;

  return editorNavUtils;
}

export default useEditorNavigation;
