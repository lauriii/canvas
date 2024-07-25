import type React from 'react';
import styles from './NameTag.module.css';
import { useAppSelector } from '@/app/hooks';
import { selectModel } from '@/features/layout/layoutModelSlice';
import clsx from 'clsx';
import { BoxModelIcon } from '@radix-ui/react-icons';

interface NameTagProps {
  elementId: string;
  selected: boolean;
}

const NameTag: React.FC<NameTagProps> = (props) => {
  const { elementId, selected } = props;
  const model = useAppSelector(selectModel);
  const component = model[elementId];
  if (!component) {
    console.error(elementId);
    return;
  }

  return (
    <div className={clsx(styles.nameTag, { [styles.selected]: selected })}>
      <BoxModelIcon width={10} height={10} />
      {component.name}
    </div>
  );
};

export default NameTag;
