import { useParams } from 'react-router';
import { useNavigate } from 'react-router-dom';
import { EyeNoneIcon, EyeOpenIcon } from '@radix-ui/react-icons';
import { Button } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import PreviewWidthSelector from '@/features/pagePreview/PreviewWidthSelector';
import { useEditorNavigation } from '@/hooks/useEditorNavigation';
import { useTemplateRef } from '@/hooks/useTemplateRef';
import { pageDataFormApi } from '@/services/pageDataForm';

type PreviewControlsProps = {
  isPreview: boolean;
};

const PreviewControls = ({ isPreview }: PreviewControlsProps) => {
  const dispatch = useAppDispatch();
  const navigate = useNavigate();
  const { entityId, entityType, previewEntityId, bundle, viewMode } =
    useParams();
  const { navigateToEditor } = useEditorNavigation();
  const { isTemplateContext, isTemplatePreviewRoute } = useTemplateRef();

  function handleChangeModeClick() {
    if (isPreview) {
      dispatch(
        pageDataFormApi.util.invalidateTags([
          { type: 'PageDataForm', id: 'FORM' },
        ]),
      );
      if (isTemplatePreviewRoute) {
        navigate(`/template/${entityType}/${bundle}/${viewMode}/${entityId}`);
      } else {
        navigateToEditor(entityType, entityId);
      }
    } else {
      if (isTemplateContext) {
        navigate(
          `/preview/template/${entityType}/${bundle}/${previewEntityId}/${viewMode}`,
        );
      } else {
        navigate(`/preview/${entityType}/${entityId}/full`);
      }
    }
  }

  if (
    (!entityId && !isTemplateContext) ||
    (isTemplateContext && !previewEntityId)
  ) {
    return null;
  }

  return (
    <>
      {isPreview ? (
        <>
          <PreviewWidthSelector />
          <Button
            variant="outline"
            color="blue"
            onClick={handleChangeModeClick}
          >
            <EyeNoneIcon /> Exit Preview
          </Button>
        </>
      ) : (
        <Button variant="outline" color="blue" onClick={handleChangeModeClick}>
          <EyeOpenIcon /> Preview
        </Button>
      )}
    </>
  );
};

export default PreviewControls;
