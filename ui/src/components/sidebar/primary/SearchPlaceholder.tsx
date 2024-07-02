import * as Menubar from '@radix-ui/react-menubar';
import clsx from 'clsx';
import styles from '@/components/sidebar/primary/PrimaryMenubar.module.css';

// Placeholder!!! Will be replaced with an actual search component.
const SearchPlaceholder = () => {
  return (
    <>
      <div className={clsx('MenubarItem', styles.MenubarItem)}>Search</div>
      <Menubar.Separator
        className={clsx('MenubarSeparator', styles.MenubarSeparator)}
      />
    </>
  );
};

export default SearchPlaceholder;
