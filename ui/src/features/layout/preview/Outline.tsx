import "./Outline.css";
import type React from "react";
import { useRef, useEffect, useCallback, useState } from "react";
import { deleteNode } from "../layoutSlice";
import { useAppDispatch } from "../../../app/hooks";
import {updateNodeModel} from "../../model/modelSlice";

interface OutlineProps {
  hoveredElementId: string | undefined; // the data-xb-uuid value of the dom element that was hovered.
}
const Outline: React.FC<OutlineProps> = props => {
  const { hoveredElementId } = props;
  const hoveredElementRef = useRef<HTMLElement | null>(null);
  const outlineElRef = useRef<HTMLDivElement | null>(null);
  const iframeElRef = useRef<HTMLIFrameElement | null>(null);
  const toolbarElRef = useRef<HTMLDivElement | null>(null);
  const dispatch = useAppDispatch();

  useEffect(() => {
    const iframe = document.getElementById("preview") as HTMLIFrameElement | null;
    iframeElRef.current = iframe;
  }, []);

  useEffect(() => {
    if (hoveredElementId) {
      hoveredElementRef.current = iframeElRef.current?.contentDocument?.querySelectorAll(
        `[data-xb-uuid="${hoveredElementId}"]`,
      )[0] as HTMLElement | null;
      applyStyles();
      bindEvents();
    }
  }, [hoveredElementId]);

  const handleFrameScroll = () => {
    if (!iframeElRef.current || !hoveredElementRef.current) {
      return;
    }
    applyStyles();
  };

  const bindEvents = () => {
    if (!iframeElRef.current) {
      return;
    }
    const iframeDocument = iframeElRef.current.contentDocument;
    if (!iframeDocument) {
      return;
    }

    // Attach the scroll event listener to the iframe's content window
    iframeDocument.addEventListener("scroll", handleFrameScroll);
  };

  const applyStyles = () => {
    const elRect = hoveredElementRef.current?.getBoundingClientRect();
    const iframeRect = iframeElRef.current?.getBoundingClientRect();

    if (outlineElRef.current && elRect && iframeRect) {
      outlineElRef.current.style.transform = `translate(${elRect.left + iframeRect.x}px, ${elRect.top + iframeRect.y}px)`;
      outlineElRef.current.style.width = `${elRect.width}px`;
      outlineElRef.current.style.height = `${elRect.height}px`;
    }

    if (toolbarElRef.current && elRect && iframeRect) {
      toolbarElRef.current.style.transform = `translate(${elRect.left + iframeRect.x}px, ${elRect.top + iframeRect.y}px)`;
    }
  };

  function handleDeleteClick() {
    if (hoveredElementId) {
      dispatch(deleteNode(hoveredElementId));
    }
  }
  function handleEditClick() {
    if (hoveredElementId) {
      dispatch(updateNodeModel({uuid: hoveredElementId, model: {name: 'FOO'}}))
    }
  }

  if (hoveredElementId === undefined) {
    return null;
  }

  return (
    <>
      <div ref={outlineElRef} className="xb-component-outline" />
      <div ref={toolbarElRef} className="xb-component-toolbar">
        <button type="button" onClick={handleEditClick}>Edit</button>
        <button type="button" onClick={handleDeleteClick}>
          Delete
        </button>
      </div>
    </>
  );
};

export default Outline;
