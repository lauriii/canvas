import { useAppSelector } from '@/app/hooks';
import { selectBaseUrl } from '@/features/configuration/configurationSlice';
import { useNavigate, useParams } from 'react-router-dom';
import { DEFAULT_REGION } from '@/features/ui/uiSlice';
import { useCallback } from 'react';

export function useNavigationUtils() {
  const { regionId } = useParams();
  const navigate = useNavigate();
  const baseUrl = useAppSelector(selectBaseUrl);

  const setSelectedComponent = useCallback(
    (componentUuid: string) => {
      const baseUrl = '/editor';
      let destinationUrl = `${baseUrl}`;

      if (regionId) {
        destinationUrl = `${baseUrl}/region/${regionId}/component/${componentUuid}`;
      } else {
        destinationUrl = `${baseUrl}/component/${componentUuid}`;
      }

      navigate(destinationUrl);
    },
    [navigate, regionId],
  );
  const unsetSelectedComponent = useCallback(() => {
    const baseUrl = '/editor';
    let destinationUrl = `${baseUrl}`;

    if (regionId) {
      destinationUrl = `${baseUrl}/region/${regionId}`;
    } else {
      destinationUrl = `${baseUrl}`;
    }

    navigate(destinationUrl);
  }, [navigate, regionId]);

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
      window.location.href = `${baseUrl}/xb/${entityType}/${entityId}`;
    },
    [baseUrl],
  );

  return {
    setSelectedComponent,
    unsetSelectedComponent,
    setSelectedRegion,
    setEditorEntity,
  };
}
