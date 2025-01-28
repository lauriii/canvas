import { Button } from '@radix-ui/themes';
import clsx from 'clsx';
import styles from '@/components/topbar/Topbar.module.css';
import { useSaveLayoutByIdMutation } from '@/services/layout';
import { useAppSelector } from '@/app/hooks';
import {
  selectEntityId,
  selectEntityType,
} from '@/features/configuration/configurationSlice';
import { selectLayout, selectModel } from '@/features/layout/layoutModelSlice';
import { useEffect, useState } from 'react';

const PUBLISHED = 'published';
const SAVING = 'saving';
const PUBLISH = 'publish';
const { drupalSettings } = window;

/**
 * 🚧🚧 Rough version of the Publish toolbar button to temporarily connect the UI to the save functionality on the API.
 *
 * Code is temp/placeholder and intended to be replaced when it's possible to 'batch' publish changes through the
 * designed UX flow
 */
const DemoPublishButton = () => {
  const entityId = useAppSelector(selectEntityId);
  const entityType = useAppSelector(selectEntityType);
  const layout = useAppSelector(selectLayout);
  const model = useAppSelector(selectModel);
  const [publishStatus, setPublishStatus] = useState(PUBLISH);
  const [saveLayout, { isLoading, isSuccess, isError }] =
    useSaveLayoutByIdMutation();
  const demoMode = drupalSettings?.xb.demo_mode;

  function handlePublishClick() {
    saveLayout({ entityId, entityType, layout, model });
  }

  useEffect(() => {
    if (isError) {
      // @todo implement error handling.
      setPublishStatus(PUBLISH);
    }
    if (isLoading) {
      setPublishStatus(SAVING);
    }
    if (isSuccess) {
      setPublishStatus(PUBLISHED);
      // For now, switch the button back after 5 seconds
      setTimeout(() => {
        setPublishStatus(PUBLISH);
      }, 5000);
    }
  }, [isLoading, isSuccess, isError]);

  return (
    <Button
      variant="solid"
      disabled={publishStatus === SAVING || demoMode}
      color={publishStatus === PUBLISHED ? 'green' : 'blue'}
      onClick={handlePublishClick}
      data-testid="xb-publish-button"
    >
      {publishStatus === PUBLISH && 'Publish'}
      {publishStatus === SAVING && (
        <span className={clsx(styles.loading)}>Saving</span>
      )}
      {publishStatus === PUBLISHED && 'Published'}
    </Button>
  );
};

export default DemoPublishButton;
