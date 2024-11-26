import styles from './NameTag.module.css';
import clsx from 'clsx';
import { BoxModelIcon } from '@radix-ui/react-icons';

interface NameTagProps {
  name: string;
  componentUuid: string;
  selected: boolean;
  nodeType: string;
}

const NameTag: React.FC<NameTagProps> = (props) => {
  const { name, selected, nodeType, componentUuid } = props;

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
