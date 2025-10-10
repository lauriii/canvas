import { useCallback } from 'react';
import clsx from 'clsx';
import { Flex, Text, Tooltip } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import { setActiveExtension } from '@/features/extensions/extensionsSlice';
import { setDialogOpen } from '@/features/ui/dialogSlice';

import type React from 'react';
import type { Extension } from '@/types/Extensions';

import styles from './ExtensionsList.module.css';

const ExtensionButton = ({ extension }: { extension: Extension }) => {
  const { name, icon, description } = extension;
  const dispatch = useAppDispatch();

  const handleClick = useCallback(
    (e: React.MouseEvent<HTMLButtonElement>) => {
      e.preventDefault();
      dispatch(setDialogOpen('extension'));
      dispatch(setActiveExtension(extension));
    },
    [dispatch, extension],
  );
  const maxDescriptionLength = 60;
  const isTrimmed = description.length > maxDescriptionLength;
  const trimmedDescription = isTrimmed
    ? description.substring(0, maxDescriptionLength) + '…'
    : description;

  return (
    <Tooltip content={trimmedDescription}>
      <Flex justify="start" align="center" direction="column" asChild>
        <button className={clsx(styles.extensionIcon)} onClick={handleClick}>
          <img alt={name} src={icon} height="42" width="42" />
          <Text align="center" size="1">
            {name}
          </Text>
        </button>
      </Flex>
    </Tooltip>
  );
};

export default ExtensionButton;
