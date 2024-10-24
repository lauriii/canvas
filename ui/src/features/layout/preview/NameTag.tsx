import type React from 'react';
import styles from './NameTag.module.css';
import { useAppSelector } from '@/app/hooks';
import { selectModel } from '@/features/layout/layoutModelSlice';
import clsx from 'clsx';
import { BoxModelIcon } from '@radix-ui/react-icons';

interface NameTagProps {
  componentUuid: string;
  selected: boolean;
  nodeType: string;
}

const NameTag: React.FC<NameTagProps> = (props) => {
  const { componentUuid, selected, nodeType } = props;

  const model = useAppSelector(selectModel);
  const component = model[componentUuid];
  const name = nodeType === 'slot' ? 'Slot' : component?.name;

  return (
    <div
      className={clsx(
        styles.nameTag,
        { [styles.selected]: selected },
        { [styles.slot]: nodeType === 'slot' },
      )}
    >
      <BoxModelIcon width={10} height={10} />
      <span id={`${componentUuid}-name`}>{name}</span>
    </div>
  );
};

export default NameTag;
