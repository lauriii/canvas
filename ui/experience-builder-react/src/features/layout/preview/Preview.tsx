import "./Preview.css";
import type React from "react";
import { useRef, useEffect, useCallback, useState } from "react";
import Sortable from "sortablejs";
import Outline from "./Outline";
import { useAppDispatch, useAppSelector } from "../../../app/hooks";
import { selectDragging, setPreviewDragging } from "../../../features/ui/uiSlice";
import type { LayoutNode} from "../layoutSlice";
import { moveNode, selectLayout, sortNode, insertNode } from "../layoutSlice";
import {findNodePathByUuid, insertNodeAtPath} from "../layoutUtils";

const styleContent = `
.preview-dragging .sortable-list{
  min-height: 2rem;
}
.preview-dragging .sortable-list:empty{
  background: #fff;
}
.sortable-list {
  margin: 1rem;
  background: #DA924D;

  .sortable-list {
    background: #E0B773;
    .sortable-list {
      background: #A7AC86;
      .sortable-list {
        background: #678779;
        .sortable-list {
          background: #566D62;
        }
      }
    }
  }
}
.slot-container {
  display: flex;
  > .sortable-item {
    width: 100%;
  }
}
.sortable-list:empty {
  min-height: 1.5rem;
  border: 2px dashed #ccc;
  position: relative;
}
.sortable-list:empty:after {
  content: 'Slot';
  top: 0;
  right: 0;
  text-align: right;
  position: absolute;

}
.sortable-item {
  cursor: grab;
  margin: 1rem;
  padding: 1rem;
}
.preview-dragging *,
.preview-dragging {
  cursor: grabbing;
}

.sortable-ghost {
    opacity: 0.5;
    padding: 0;
    height: 2rem;
    max-height: 2rem;
    width: 100%;
    margin: 0;
    clear: none;
    position: relative;
    background: linear-gradient(135deg, #DDD 12.50%, transparent 12.50%, transparent 50%, #DDD 50%, #DDD 62.50%, transparent 62.50%, transparent 100%) center / 5.66px 5.66px;
    outline: 1px solid #ccc;
    box-shadow: none;
    flex-grow: 1;
    overflow: hidden;
    & * {
      visibility: hidden;

    }
  }
`;

interface PreviewProps {
  iframeRef: React.RefObject<HTMLIFrameElement>; // Replace 'any' with a more specific type if possible
}

const Preview: React.FC<PreviewProps> = props => {
  const { iframeRef } = props;
  const layout = useAppSelector(selectLayout);
  const iframeDocumentRef = useRef<Document | null>(null);
  const { isDragging } = useAppSelector(selectDragging);
  const dispatch = useAppDispatch();
  const [hoveredElementId, setHoveredElementId] = useState<string | undefined>();
  const [frameSrcDoc, setFrameSrcDoc] = useState("");

  function debugCreateHtmlFromData(layout: LayoutNode) {
    // Recursive function to create HTML for each component
    function createComponent(component: LayoutNode) {
      const div = document.createElement("div");
      div.className = "sortable-item";
      div.setAttribute("data-xb-uuid", component.uuid);

      // Check if the component has children to create a nested structure

      if (component.type === "component") {
        const header = document.createElement("h1");
        header.textContent = component.name;
        div.appendChild(header);
      }
      if (component.children) {
        const innerDiv = document.createElement("div");
        if(component.type === 'slot') {
          innerDiv.className = "sortable-list";
          innerDiv.setAttribute("data-xb-uuid", component.uuid);
        } else {
          innerDiv.className = "slot-container";
        }
        component.children.forEach((child: LayoutNode) => {
          innerDiv.appendChild(createComponent(child));
        });
        div.appendChild(innerDiv);
      }
      return div;
    }

    // Create the root element
    const rootDiv = document.createElement("div");
    rootDiv.className = "sortable-list";
    rootDiv.setAttribute("data-xb-uuid", layout.uuid);

    // Append all child components to the root
    layout.children.forEach(child => {
      rootDiv.appendChild(createComponent(child));
    });

    return rootDiv.outerHTML;
  }

  // Function to create a full HTML document as a string
  function debugCreateFullHtmlDocument(data: LayoutNode): string {
    // Create a new document
    const doc = document.implementation.createHTMLDocument("New Document");

    // Add the style element to the head
    const styleEl = doc.createElement("style");
    styleEl.textContent = styleContent;
    doc.head.appendChild(styleEl);

    // Create the body content from the data
    const bodyContent = debugCreateHtmlFromData(data);
    // Append the body content to the new document's body
    doc.body.innerHTML = bodyContent;

    // Serialize the new document to a string
    const serializer = new XMLSerializer();
    const docString = serializer.serializeToString(doc);

    return docString;
  }

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
          dispatch(insertNode({to: newPath, newNode: {uuid: ev.clone.dataset.xbUuid, children: [], type: 'component', name: `Component ${ev.clone.dataset.xbUuid}`}}))
        } else {
          dispatch(moveNode({ uuid: ev.item.dataset.xbUuid, to: newPath }));
        }

      }
    }
  }

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
      // Wait for the iframe to load
      iframe.onload = () => {
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
      setFrameSrcDoc(debugCreateFullHtmlDocument(layout));
    }
  }, [layout]);

  useEffect(() => {}, []);

  return (
    <>
      <iframe ref={iframeRef} className="preview" id="preview" srcDoc={frameSrcDoc}></iframe>
      {!isDragging && <Outline hoveredElementId={hoveredElementId} />}
    </>
  );
};
export default Preview;
