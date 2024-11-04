// @todo: Keep this code to reuse for displaying module components.
//    @see https://www.drupal.org/project/experience_builder/issues/3482393
// import * as Menubar from '@radix-ui/react-menubar';
// import { PlusIcon } from '@radix-ui/react-icons';
// import clsx from 'clsx';
// import { useEffect, useRef, useCallback } from 'react';
// import styles from '@/components/topbar/add/AddMenu.module.css';
// import { preventHover } from '@/utils/function-utils';
// import SecondLevelMenubar from '@/components/topbar/add/SecondLevelMenubar';
// import { useAppDispatch, useAppSelector } from '@/app/hooks';
// import {
//   selectActiveMenu,
//   selectIsHidden,
//   setActiveSecondLevelMenu,
//   setInactive,
// } from '@/features/ui/addMenuSlice';
//
// export const AddMenu = () => {
//   const isHidden = useAppSelector(selectIsHidden);
//   const activeMenu = useAppSelector(selectActiveMenu);
//   const dispatch = useAppDispatch();
//   const menuRef = useRef<HTMLDivElement>(null);
//   const portalMenuBarContainerRef = useRef<HTMLElement | null>(null);
//   const portalMenuBarSubmenuContainerRef = useRef<HTMLElement | null>(null);
//   const buttonRef = useRef<HTMLButtonElement>(null);
//   const iframesRef = useRef<HTMLIFrameElement[]>([]);
//
//   const onClickHandler = () => {
//     if (activeMenu === ADD_MENU_ITEMS.ADD_ID) {
//       dispatch(setInactive());
//     } else {
//       // Open default components menu by default.
//       dispatch(setActiveSecondLevelMenu(ADD_MENU_ITEMS.DEFAULT_COMPONENTS_ID));
//     }
//   };
//
//   const handleClickOutside = useCallback(
//     (event: MouseEvent) => {
//       const portalMenuBarContainer =
//         portalMenuBarContainerRef.current ||
//         document.getElementById('menuBarContainer');
//       const portalMenuBarSubmenuContainer =
//         portalMenuBarSubmenuContainerRef.current ||
//         document.getElementById('menuBarSubmenuContainer');
//       if (
//         buttonRef.current &&
//         buttonRef.current.contains(event.target as Node)
//       ) {
//         return;
//       }
//       if (
//         menuRef.current &&
//         !menuRef.current.contains(event.target as Node) &&
//         portalMenuBarContainer &&
//         !portalMenuBarContainer.contains(event.target as Node) &&
//         portalMenuBarSubmenuContainer &&
//         !portalMenuBarSubmenuContainer.contains(event.target as Node)
//       ) {
//         dispatch(setInactive());
//       }
//     },
//     [dispatch],
//   );
//
//   useEffect(() => {
//     portalMenuBarContainerRef.current =
//       document.getElementById('menuBarContainer');
//     document.addEventListener('mousedown', handleClickOutside);
//     const iframes = document.querySelectorAll('iframe');
//     const currentIframes = Array.from(iframes) as HTMLIFrameElement[];
//     currentIframes.forEach((iframe, index) => {
//       iframesRef.current[index] = iframe;
//       iframe.addEventListener('load', () => {
//         const iframeDoc =
//           iframe.contentDocument || iframe.contentWindow?.document;
//         if (iframeDoc) {
//           iframeDoc.addEventListener('mousedown', handleClickOutside);
//         }
//       });
//     });
//
//     return () => {
//       document.removeEventListener('mousedown', handleClickOutside);
//       currentIframes.forEach((iframe) => {
//         const iframeDoc =
//           iframe.contentDocument || iframe.contentWindow?.document;
//         if (iframeDoc) {
//           iframeDoc.removeEventListener('mousedown', handleClickOutside);
//         }
//       });
//     };
//   }, [handleClickOutside]);
//
//   return (
//     <Menubar.Menu value={ADD_MENU_ITEMS.ADD_ID}>
//       <Menubar.Trigger
//         ref={buttonRef}
//         onPointerEnter={preventHover}
//         onPointerMove={preventHover}
//         onPointerLeave={preventHover}
//         onClick={onClickHandler}
//         id="add-menu-button"
//         aria-label="Open add menu"
//       >
//         <div className={clsx(styles.plusIconWrapper)}>
//           <PlusIcon width={20} height={20} />
//         </div>
//       </Menubar.Trigger>
//       <Menubar.Portal container={document.getElementById('menuBarContainer')}>
//         <Menubar.Content
//           ref={menuRef}
//           className={clsx('MenubarContent', styles.MenubarContent)}
//           align="start"
//           onPointerEnter={preventHover}
//           onPointerLeave={preventHover}
//           style={{ display: isHidden ? 'none' : undefined }}
//           forceMount
//         >
//           <SecondLevelMenubar />
//         </Menubar.Content>
//       </Menubar.Portal>
//     </Menubar.Menu>
//   );
// };
//
// export default AddMenu;
