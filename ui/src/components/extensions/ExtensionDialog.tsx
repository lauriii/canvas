import { useCallback } from 'react';
import { Box } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import Dialog from '@/components/Dialog';
import {
  selectActiveExtension,
  unsetActiveExtension,
} from '@/features/extensions/extensionsSlice';
import {
  selectDialogOpen,
  setDialogClosed,
  setDialogOpen,
} from '@/features/ui/dialogSlice';

import type React from 'react';

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

  const { id, name, url } = activeExtension;

  return (
    <Dialog
      open={extension}
      onOpenChange={handleOpenChange}
      title={name}
      modal={false}
      headerClose={true}
      footer={{ hidden: true }}
    >
      {url ? (
        <iframe
          // @todo Only add 'allow-same-origin' if the extension is loaded from a local file.
          sandbox="allow-scripts allow-same-origin"
          id={`canvas-extension-iframe-${id}`}
          src={url}
          style={{
            border: 'none',
            width: '100%',
            // @todo Explore how to size the iframe automatically instead of hardcoding the height.
            // Idea: Use `srcdoc` and fetch the HTML content, then inject it with a custom script that communicates the
            // height to the parent.
            // This value matches the maximum height set for the dialog.
            height: 'calc(100vh - 300px)',
          }}
        />
      ) : (
        <Box
          id="extensionPortalContainer"
          className={`canvas-extension-${activeExtension.id}`}
        ></Box>
      )}
    </Dialog>
  );
};

export default ExtensionDialog;
