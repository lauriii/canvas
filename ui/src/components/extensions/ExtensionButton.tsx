import clsx from 'clsx';
import { Flex, Text, Tooltip } from '@radix-ui/themes';
import styles from './ExtensionsList.module.css';
import type React from 'react';
import { useCallback } from 'react';
import type { ExtensionDefinition } from '@/types/Extensions';
import { useAppDispatch } from '@/app/hooks';
import { setDialogOpen } from '@/features/ui/dialogSlice';
import { setActiveExtension } from '@/features/extensions/extensionsSlice';

const ExtensionButton: React.FC<ExtensionsPopoverProps> = ({ extension }) => {
  const { name, imgSrc, description } = extension;
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
          <img alt={name} src={imgSrc} height="42" width="42" />
          <Text align="center" size="1">
            {name}
          </Text>
        </button>
      </Flex>
    </Tooltip>
  );
};

interface ExtensionsPopoverProps {
  extension: ExtensionDefinition;
}

export default ExtensionButton;
