// @todo: Keep this code to reuse for displaying module components.
//    @see https://www.drupal.org/project/experience_builder/issues/3482393
// import * as Menubar from '@radix-ui/react-menubar';
// import clsx from 'clsx';
// import styles from '@/components/topbar/add/AddMenu.module.css';
// import type { ReactElement } from 'react';
// import SecondLevelMenuTrigger from '@/components/topbar/add/SecondLevelMenuTrigger';
// import Panel from '@/components/Panel';
// import { useAppSelector } from '@/app/hooks';
// import { selectIsHidden } from '@/features/ui/addMenuSlice';
//
// const SecondLevelMenu = (props: {
//   submenuTitle: string;
//   leftIcon: string;
//   children?: ReactElement;
//   value: string;
// }) => {
//   const { submenuTitle, leftIcon, children, value } = props;
//   const isHidden = useAppSelector(selectIsHidden);
//
//   return (
//     <Menubar.Menu value={value}>
//       <SecondLevelMenuTrigger
//         submenuTitle={submenuTitle}
//         leftIcon={leftIcon}
//         value={value}
//       />
//       <Menubar.Portal
//         container={document.getElementById('menuBarSubmenuContainer')}
//       >
//         <Menubar.Content
//           forceMount
//           side="top"
//           className={clsx('MenubarSubContent', styles.MenubarSubContent)}
//           asChild
//           style={{ display: isHidden ? 'none' : undefined }}
//         >
//           <Panel p="1" pt="4">
//             {children}
//           </Panel>
//         </Menubar.Content>
//       </Menubar.Portal>
//     </Menubar.Menu>
//   );
// };
//
// export default SecondLevelMenu;
