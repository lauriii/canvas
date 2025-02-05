import clsx from 'clsx';
import { Flex, Text } from '@radix-ui/themes';
import styles from './ExtensionsList.module.css';
import type React from 'react';
import { useCallback } from 'react';
import { handleNonWorkingBtn } from '@/utils/function-utils';
import type { Extension } from '@/types/Extensions';

interface ExtensionsPopoverProps {
  extension: Extension;
}

const ExtensionButton: React.FC<ExtensionsPopoverProps> = ({ extension }) => {
  const { name, imgSrc } = extension;
  const handleClick = useCallback((e: React.MouseEvent<HTMLButtonElement>) => {
    e.preventDefault();
    handleNonWorkingBtn();
  }, []);

  return (
    <Flex justify="start" align="center" direction="column" asChild>
      <button className={clsx(styles.extensionIcon)} onClick={handleClick}>
        <img alt={name} src={imgSrc} height="42" width="42" />
        <Text align="center" size="1">
          {name}
        </Text>
      </button>
    </Flex>
  );
};

export default ExtensionButton;
