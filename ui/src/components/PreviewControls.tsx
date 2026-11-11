import { useLocation, useParams } from 'react-router';
import { EyeNoneIcon, EyeOpenIcon } from '@radix-ui/react-icons';
import { Button, Tooltip } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import useEditorNavigation from '@/hooks/useEditorNavigation';
import { useTemplateRef } from '@/hooks/useTemplateRef';
import {
  useNavigateToTranslation,
  useWorkingLanguage,
} from '@/hooks/useWorkingLanguage';
import { EDITED_TRANSLATION_PARAM } from '@/services/baseQuery';
import { pageDataFormApi } from '@/services/pageDataForm';

import styles from './PreviewControls.module.css';

type PreviewControlsProps = {
  isPreview: boolean;
};

const PreviewControls = ({ isPreview }: PreviewControlsProps) => {
  const dispatch = useAppDispatch();
  // Leaving or entering the editor of a translation with a custom layout
  // loads the app afresh.
  const navigate = useNavigateToTranslation();
  const { entityId, entityType, previewEntityId, bundle, viewMode } =
    useParams();
  const { urlForEditor } = useEditorNavigation();
  const { isTemplateContext, isTemplatePreviewRoute } = useTemplateRef();
  const { search } = useLocation();
  // The preview and the editor show the same language: the working language.
  const { workingLanguage, pageLanguage, hasOwnLayout } = useWorkingLanguage();
  const params = new URLSearchParams(search);
  const language =
    params.get('language') ?? params.get(EDITED_TRANSLATION_PARAM);
  const isOtherLanguage =
    !!language && !!workingLanguage && workingLanguage.id !== pageLanguage;
  const query = isOtherLanguage ? `?language=${workingLanguage.id}` : '';
  // The editor edits a page in a language in which it has a custom layout.
  const editorQuery =
    isOtherLanguage && hasOwnLayout(workingLanguage.id)
      ? `?${EDITED_TRANSLATION_PARAM}=${workingLanguage.id}`
      : query;

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
        navigate(`${urlForEditor(entityType, entityId)}${editorQuery}`);
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
