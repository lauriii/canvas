import Dialog from '@/components/Dialog';
import type React from 'react';
import { useCallback } from 'react';

import { Text } from '@radix-ui/themes';
import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectDialogOpen,
  setDialogClosed,
  setDialogOpen,
} from '@/features/ui/dialogSlice';
import {
  selectActiveExtension,
  unsetActiveExtension,
} from '@/features/extensions/extensionsSlice';

interface ExtensionDialogProps {}

const ExtensionDialog: React.FC<ExtensionDialogProps> = () => {
  const { extension } = useAppSelector(selectDialogOpen);
  const activeExtension = useAppSelector(selectActiveExtension);
  const dispatch = useAppDispatch();

  const handleOpenChange = useCallback(
    (open: boolean) => {
      if (open) {
        dispatch(setDialogOpen('extension'));
      } else {
        dispatch(setDialogClosed('extension'));
        dispatch(unsetActiveExtension());
      }
    },
    [dispatch],
  );
  if (!extension || activeExtension === null) {
    return null;
  }

  return (
    <Dialog
      open={extension}
      onOpenChange={handleOpenChange}
      title={activeExtension.name}
      modal={false}
      footer={{ cancelText: 'Close' }}
      description={activeExtension.description}
    >
      {/* @todo https://www.drupal.org/i/3485692 - render the proof of concept into this div */}
      <div
        id="extensionPortalContainer"
        className={`xb-extension-${activeExtension.id}`}
      >
        <Text as="p">Not yet supported</Text>
      </div>
    </Dialog>
  );
};

export default ExtensionDialog;
