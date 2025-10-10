"use strict";
(() => {
  // ../../../packages/extensions/dist/index.js
  function get(type) {
    return new Promise((resolve) => {
      const handler = (event) => {
        if (event.data.type === `canvas:data:get:${type}`) {
          resolve(event.data.payload);
          window.removeEventListener("message", handler);
        }
      };
      window.addEventListener("message", handler);
      window.parent.postMessage({ type: `canvas:data:get:${type}` }, window.location.origin);
    });
  }
  function subscribe(type, callback) {
    const handler = (event) => {
      if (event.data.type === `canvas:data:subscribe:${type}`) {
        callback(event.data.payload);
      }
    };
    window.addEventListener("message", handler);
    window.parent.postMessage({ type: `canvas:data:subscribe:${type}` }, window.location.origin);
    return () => {
      window.parent.postMessage({ type: `canvas:data:unsubscribe:${type}` }, window.location.origin);
      window.removeEventListener("message", handler);
    };
  }
  function getPreviewHtml() {
    return get("previewHtml");
  }
  function getSelectedComponentUuid() {
    return get("selectedComponentUuid");
  }
  function subscribeToSelectedComponentUuid(callback) {
    return subscribe("selectedComponentUuid", callback);
  }

  // index.ts
  document.addEventListener("DOMContentLoaded", async () => {
    const previewHtml = await getPreviewHtml();
    const getElement = document.getElementById("canvas-data-get");
    if (getElement) {
      getElement.textContent = previewHtml ? previewHtml.substring(0, 200) + "..." : "No HTML";
    }
    subscribeToSelectedComponentUuid((uuid) => {
      const subscribeElement = document.getElementById("canvas-data-subscribe");
      if (subscribeElement) {
        subscribeElement.textContent = uuid || "No component selected";
      }
    });
    const selectedComponentUuid = await getSelectedComponentUuid();
    const selectedElement = document.getElementById(
      "canvas-data-get-selected-component-uuid"
    );
    if (selectedElement) {
      selectedElement.textContent = selectedComponentUuid || "No component selected";
    }
  });
})();
