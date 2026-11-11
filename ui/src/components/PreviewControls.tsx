import { useLocation, useNavigate, useParams } from 'react-router';
import { EyeNoneIcon, EyeOpenIcon } from '@radix-ui/react-icons';
import { Button, Tooltip } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import useEditorNavigation from '@/hooks/useEditorNavigation';
import { useTemplateRef } from '@/hooks/useTemplateRef';
import { useWorkingLanguage } from '@/hooks/useWorkingLanguage';
import { pageDataFormApi } from '@/services/pageDataForm';

import styles from './PreviewControls.module.css';

type PreviewControlsProps = {
  isPreview: boolean;
};

const PreviewControls = ({ isPreview }: PreviewControlsProps) => {
  const dispatch = useAppDispatch();
  const navigate = useNavigate();
  const { entityId, entityType, previewEntityId, bundle, viewMode } =
    useParams();
  const { urlForEditor } = useEditorNavigation();
  const { isTemplateContext, isTemplatePreviewRoute } = useTemplateRef();
  const { search } = useLocation();
  // The preview and the editor show the same language: the working language.
  const { workingLanguage, pageLanguage } = useWorkingLanguage();
  const language = new URLSearchParams(search).get('language');
  const query =
    language && workingLanguage && workingLanguage.id !== pageLanguage
      ? `?language=${workingLanguage.id}`
      : '';

  function handleChangeModeClick() {
    if (isPreview) {
      dispatch(
        pageDataFormApi.util.invalidateTags([
          { type: 'PageDataForm', id: 'FORM' },
        ]),
      );
      if (isTemplatePreviewRoute) {
        navigate(
          `/template/${entityType}/${bundle}/${viewMode}/${entityId}${query}`,
        );
      } else {
        navigate(`${urlForEditor(entityType, entityId)}${query}`);
      }
    } else {
      if (isTemplateContext) {
        navigate(
          `/preview/template/${entityType}/${bundle}/${previewEntityId}/${viewMode}${query}`,
        );
      } else {
        navigate(`/preview/${entityType}/${entityId}/full${query}`);
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
      {!isPreview ? (
        <Tooltip content="Preview">
          <Button
            onClick={handleChangeModeClick}
            color="blue"
            variant="ghost"
            size="1"
            className={styles.previewButton}
            aria-label="Preview"
          >
            <EyeOpenIcon />
          </Button>
        </Tooltip>
      ) : null}
      {isPreview ? (
        <Tooltip content="Exit Preview">
          <Button
            onClick={handleChangeModeClick}
            color="blue"
            variant="ghost"
            size="1"
            className={styles.previewButton}
            aria-label="Exit Preview"
          >
            <EyeNoneIcon />
          </Button>
        </Tooltip>
      ) : null}
    </>
  );
};

export default PreviewControls;
