// @todo: Keep this code to reuse for displaying module components.
//    @see https://www.drupal.org/project/experience_builder/issues/3482393
// import { preventHover } from '@/utils/function-utils';
// import * as Menubar from '@radix-ui/react-menubar';
// import { Box, Flex, Text } from '@radix-ui/themes';
// import clsx from 'clsx';
// import styles from '@/components/topbar/add/AddMenu.module.css';
// import { ChevronRightIcon } from '@radix-ui/react-icons';
// import {
//   selectActiveSecondLevelMenu,
//   setActiveSecondLevelMenu,
// } from '@/features/ui/addMenuSlice';
// import { useAppDispatch, useAppSelector } from '@/app/hooks';
//
// interface SecondLevelMenuTriggerInterfaceProps {
//   submenuTitle: string;
//   leftIcon: string;
//   value: string;
// }
//
// const SecondLevelMenuTrigger = ({
//   submenuTitle,
//   leftIcon,
//   value,
// }: SecondLevelMenuTriggerInterfaceProps) => {
//   const dispatch = useAppDispatch();
//   const activeSubmenu = useAppSelector(selectActiveSecondLevelMenu);
//
//   const onClickHandler = () => {
//     if (activeSubmenu !== value) {
//       dispatch(setActiveSecondLevelMenu(value));
//     }
//   };
//
//   return (
//     <Menubar.Trigger
//       onPointerEnter={preventHover}
//       onPointerMove={preventHover}
//       onPointerLeave={preventHover}
//       className={clsx('MenubarSubTrigger', styles.MenubarSubTrigger)}
//       onClick={onClickHandler}
//       asChild
//     >
//       <Flex align="center" justify="between" gap="5" px="3" py="3">
//         <Flex align="center" justify="start" gap="3">
//           <Box flexGrow="0" flexShrink="0">
//             <img src={leftIcon} alt="menu item icon" />
//           </Box>
//           <Text size="2">{submenuTitle}</Text>
//         </Flex>
//         <Box flexGrow="0" flexShrink="0">
//           <ChevronRightIcon />
//         </Box>
//       </Flex>
//     </Menubar.Trigger>
//   );
// };
//
// export default SecondLevelMenuTrigger;
