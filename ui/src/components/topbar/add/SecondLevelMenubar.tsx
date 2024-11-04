// @todo: Keep this code to reuse for displaying module components.
//    @see https://www.drupal.org/project/experience_builder/issues/3482393
// import * as Menubar from '@radix-ui/react-menubar';
// import styles from '@/components/topbar/add/AddMenu.module.css';
// import {
//   selectActiveSecondLevelMenu,
//   setActiveSecondLevelMenu,
//   ADD_MENU_ITEMS,
// } from '@/features/ui/addMenuSlice';
// import SecondLevelMenu from '@/components/topbar/add/SecondLevelMenu';
// import ComponentIcon from '@assets/icons/component.svg';
// import SectionIcon from '@assets/icons/section.svg';
// import { useAppSelector } from '@/app/hooks';
// import ComponentList from '@/components/list/ComponentList';
// import SectionList from '@/components/list/SectionList';
// import Panel from '@/components/Panel';
// import ErrorBoundary from '@/components/error/ErrorBoundary';
// import { Box, Text } from '@radix-ui/themes';
//
// const SecondLevelMenubar = () => {
//   const activeMenu = useAppSelector(selectActiveSecondLevelMenu);
//
//   return (
//     <Menubar.Root value={activeMenu} onValueChange={setActiveSecondLevelMenu}>
//       <Panel p="1" className={styles.MenubarPanel}>
//         <SecondLevelMenu
//           value={ADD_MENU_ITEMS.DEFAULT_COMPONENTS_ID}
//           submenuTitle="Default components"
//           leftIcon={ComponentIcon}
//         >
//           <ErrorBoundary title="An unexpected error has occurred while fetching components.">
//             <ComponentList />
//           </ErrorBoundary>
//         </SecondLevelMenu>
//         <SecondLevelMenu
//           value={ADD_MENU_ITEMS.CUSTOM_COMPONENTS_ID}
//           submenuTitle="Custom components"
//           leftIcon={ComponentIcon}
//         >
//           <Box px="4" pb="4">
//             <Text size="4">Custom components placeholder</Text>
//           </Box>
//         </SecondLevelMenu>
//         <SecondLevelMenu
//           value={ADD_MENU_ITEMS.SECTION_ID}
//           submenuTitle="Section templates"
//           leftIcon={SectionIcon}
//         >
//           <>
//             <Box px="4" pb="4">
//               <Text size="2">
//                 The section template listed below is hard coded and is a proof
//                 of concept. It should allow the user to add a hero with an image
//                 below it in a single action.
//               </Text>
//             </Box>
//             <ErrorBoundary title="An unexpected error has occurred while fetching section templates.">
//               <SectionList />
//             </ErrorBoundary>
//           </>
//         </SecondLevelMenu>
//       </Panel>
//     </Menubar.Root>
//   );
// };
//
// export default SecondLevelMenubar;
