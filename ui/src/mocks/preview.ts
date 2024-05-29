import type { LayoutNode } from "../features/layout/layoutSlice";
import styleContent from "./styles.css?raw";

const createHtmlFromLayoutData = (
  layout: LayoutNode,
  model: { [x: string]: { name: string } },
) => {
  // Recursive function to create HTML for each component
  const createComponent = (component: LayoutNode): HTMLDivElement => {
    const div = document.createElement('div');
    div.className = 'sortable-item';
    div.setAttribute('data-xb-uuid', component.uuid);

    // Check if the component has children to create a nested structure

    if (component.type === 'component') {
      const header = document.createElement('h1');
      header.textContent = model[component.uuid]?.name || `debug: no name`;
      div.appendChild(header);
      div.setAttribute('data-xb-type', 'component');
    }
    if (component.children) {
      const innerDiv = document.createElement('div');
      if (component.type === 'slot') {
        innerDiv.className = 'sortable-list';
        innerDiv.setAttribute('data-xb-uuid', component.uuid);
        div.setAttribute('data-xb-type', 'slot');
      } else {
        innerDiv.className = 'slot-container';
      }
      component.children.forEach((child: LayoutNode) => {
        innerDiv.appendChild(createComponent(child));
      });
      div.appendChild(innerDiv);
    }
    return div;
  };

  // Create the root element
  const rootDiv = document.createElement('div');
  rootDiv.className = 'sortable-list';
  rootDiv.setAttribute('data-xb-uuid', layout.uuid);

  // Append all child components to the root
  layout.children.forEach((child) => {
    rootDiv.appendChild(createComponent(child));
  });

  return rootDiv.outerHTML;
}

// Function to create a full HTML document as a string
const mockPreviewDocument = (data: LayoutNode, model: {}): string => {
  // Create a new document
  const doc = document.implementation.createHTMLDocument('New Document');

  // Add the style element to the head
  const styleEl = doc.createElement('style');
  styleEl.textContent = styleContent;
  doc.head.appendChild(styleEl);

  // Create the body content from the data
  // Append the body content to the new document's body
  doc.body.innerHTML = createHtmlFromLayoutData(data, model);

  // Serialize the new document to a string
  const serializer = new XMLSerializer();
  return serializer.serializeToString(doc);
}

export default mockPreviewDocument;
