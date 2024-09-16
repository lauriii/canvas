import type React from 'react';
import styles from './NameTag.module.css';
import { useAppSelector } from '@/app/hooks';
import { selectModel } from '@/features/layout/layoutModelSlice';
import clsx from 'clsx';
import { BoxModelIcon } from '@radix-ui/react-icons';

interface NameTagProps {
  elementId: string;
  selected: boolean;
  nodeType: string;
}

const NameTag: React.FC<NameTagProps> = (props) => {
  const { elementId, selected, nodeType } = props;

  const model = useAppSelector(selectModel);
  const component = model[elementId];
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
      {name}
    </div>
  );
};

export default NameTag;
