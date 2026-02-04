import { useParams } from 'react-router';
import { useNavigate } from 'react-router-dom';
import { EyeNoneIcon, EyeOpenIcon } from '@radix-ui/react-icons';
import { Button } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import PreviewWidthSelector from '@/features/pagePreview/PreviewWidthSelector';
import { useEditorNavigation } from '@/hooks/useEditorNavigation';
import { pageDataFormApi } from '@/services/pageDataForm';

type PreviewControlsProps = {
  isPreview: boolean;
};

const PreviewControls = ({ isPreview }: PreviewControlsProps) => {
  const dispatch = useAppDispatch();
  const navigate = useNavigate();
  const { entityId, entityType } = useParams();
  const { navigateToEditor } = useEditorNavigation();
  function handleChangeModeClick() {
    if (isPreview) {
      dispatch(
        pageDataFormApi.util.invalidateTags([
          { type: 'PageDataForm', id: 'FORM' },
        ]),
      );
      navigateToEditor(entityType, entityId);
    } else {
      navigate(`/preview/${entityType}/${entityId}/full`);
    }
  }

  if (!entityId) {
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
