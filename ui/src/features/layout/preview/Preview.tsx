import styles from "./Preview.module.css";
import type React from "react";
import { useRef, useEffect, useCallback, useState } from "react";
import Sortable from "sortablejs";
import Outline from "./Outline";
import { useAppDispatch, useAppSelector } from "../../../app/hooks";
import { selectDragging, setPreviewDragging } from "../../../features/ui/uiSlice";
import {selectModel} from "../../model/modelSlice";
import type { LayoutNode} from "../layoutSlice";
import { moveNode, selectLayout, sortNode, addNewComponentToLayout } from "../layoutSlice";
import {findNodePathByUuid} from "../layoutUtils";
import {usePostPreviewMutation} from "../../../services/preview";
import {Flex, Spinner} from "@radix-ui/themes";
import classNames from "classnames";


interface PreviewProps {
  iframeRef: React.RefObject<HTMLIFrameElement>; // Replace 'any' with a more specific type if possible
}

const Preview: React.FC<PreviewProps> = props => {
  const { iframeRef } = props;
  const layout = useAppSelector(selectLayout);
  const iframeDocumentRef = useRef<Document | null>(null);
  const { isDragging } = useAppSelector(selectDragging);
  const model = useAppSelector(selectModel);
  const dispatch = useAppDispatch();
  const [hoveredElementId, setHoveredElementId] = useState<string | undefined>();
  const [frameSrcDoc, setFrameSrcDoc] = useState("");
  const [postPreview, { data, isLoading, error }] = usePostPreviewMutation();


  const bindEvents = () => {};

  function handleDragStart(ev: Sortable.SortableEvent) {
    dispatch(setPreviewDragging(true));
    iframeDocumentRef.current?.body.classList.add("preview-dragging");
  }

  function handleDragAdd(ev: Sortable.SortableEvent) {
    updateData(ev, false);
  }

  function handleDragEnd(ev: Sortable.SortableEvent) {
    dispatch(setPreviewDragging(false));
    iframeDocumentRef.current?.body.classList.remove("preview-dragging");

    // Normally handle the data update in dragAdd unless the item is being dragged within the same container, in which
    // case dragAdd doesn't fire, so we can call it from here.
    if (ev.to === ev.from) {
      updateData(ev, true);
    }
  }

  function updateData(ev: Sortable.SortableEvent, sort: boolean) {
    if (typeof ev.newDraggableIndex !== 'number') {
      return;
    }
    if (sort) {
      // Moving a node within the same parent.
      dispatch(sortNode({ uuid: ev.item.dataset.xbUuid, to: ev.newDraggableIndex }));
    } else {
      // Moving a node from one parent to another
      const receivingParentPath = findNodePathByUuid(layout, ev.to.dataset.xbUuid);
      if (receivingParentPath) {
        const newPath: number[] = [...receivingParentPath, ev.newDraggableIndex];

        if(ev.clone.dataset.isNew === 'true' && ev.clone.dataset.xbUuid) {
          dispatch(addNewComponentToLayout({to: newPath, newNode: {uuid: 'tempUUID', children: [], type: 'component', name: ev.clone.dataset.xbName}}));
        } else {
          dispatch(moveNode({ uuid: ev.item.dataset.xbUuid, to: newPath }));
        }

      }
    }
  }

  // Takes each sortable item (component) and adds a dragstart event listener. This is so that we can implement a custom
  // dragImage (the floating representation of what you are dragging that follows your cursor).
  const initSortableListItem = (listItemEl: HTMLElement) => {
    listItemEl.addEventListener("dragstart", function (event: DragEvent) {
      if (iframeDocumentRef.current && event.target) {
        // Create and style the custom drag image
        const target = event.target as HTMLElement;
        const customDragImage = iframeDocumentRef.current.createElement("div");

        customDragImage.textContent = target.dataset.debId || "Component";
        customDragImage.style.cssText = `
              background-color: #f0f0f0;
              width: 200px;
              height: 20px;
              padding: 5px 10px;
              border: 1px solid #000;
              position: absolute;
              display: flex;
              justify-content: center;
              top: -9999px; // Position off-screen to avoid flickering
              cursor: grabbing;
              pointer-events: none;
            `;
        iframeDocumentRef.current.body.appendChild(customDragImage);

        // Set the custom drag image
        event.dataTransfer?.setDragImage(customDragImage, 0, 0);

        // Remove the custom drag image element after a short delay
        window.requestAnimationFrame(function () {
          iframeDocumentRef.current?.body.removeChild(customDragImage);
        });
      }
    });
  };
  const initComponentHover = (listItemEl: HTMLElement) => {
    listItemEl.addEventListener("mouseover", function (event: MouseEvent) {
      event.stopPropagation();
      if (event.target) {
        const target = event.currentTarget as HTMLElement;
        setHoveredElementId(target.dataset.xbUuid);
      }
    });
  };
  const initSortableList = (listEl: HTMLElement) => {
    // Initialize SortableJS on the elements inside the iframe
    Sortable.create(listEl, {
      animation: 0,
      invertSwap: true,
      group: {
        name: "layout",
        pull: true,
        put: ["layout", "list"],
        revertClone: false,
      },
      dataIdAttr: "data-xb-uuid",
      onAdd: handleDragAdd,
      onStart: handleDragStart,
      onEnd: handleDragEnd,
    });
  };

  useEffect(() => {
    const iframe = iframeRef.current;
    if (iframe) {
      console.log('layout or model changed');
      // Wait for the iframe to load
      iframe.onload = () => {
        console.log('On load fired');
        iframeDocumentRef.current = iframe.contentDocument;
        const sortableLists = iframeDocumentRef.current?.getElementsByClassName("sortable-list") as HTMLCollectionOf<HTMLElement>;

        Array.from(sortableLists).forEach(sortableList => {
          const draggableItems = sortableList.getElementsByClassName("sortable-item") as HTMLCollectionOf<HTMLElement>;
          initSortableList(sortableList);
          Array.from(draggableItems).forEach(item => {
            initSortableListItem(item);
            initComponentHover(item);
          });
        });
      };

    }
  }, [layout, model]);
      //

  useEffect(() => {
    const sendPreviewRequest = async () => {
      try {
        // Trigger the mutation
        const result = await postPreview({ layout, model }).unwrap();
        // Handle the successful response here
        console.log(result); // Do something with the result
        setFrameSrcDoc(result.html);
      } catch (err) {
        // Handle the error here
        console.error(err); // Do something with the error
      }
    };
    if(layout && model) {
      sendPreviewRequest();
    }
  }, [layout, model]);

  return (
    <>
      <iframe ref={iframeRef} className={styles.preview} id="preview" srcDoc={frameSrcDoc}></iframe>
      <Flex align="center" justify="center" className={classNames(styles.loadingOverlay, {[styles.show]: isLoading})}><Spinner loading={isLoading} size="3" /></Flex>
      {!isDragging && <Outline hoveredElementId={hoveredElementId} setHoveredElementId={setHoveredElementId} />}
    </>
  );
};
export default Preview;
