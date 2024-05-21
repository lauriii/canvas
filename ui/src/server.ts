/**
 * Mock/development API server using miragejs
 *
 */
import {createServer, Model} from "miragejs"
import type {LayoutNode} from "./features/layout/layoutSlice";

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
function debugCreateHtmlFromData(layout: LayoutNode, model: { [x: string]: { name: string; }; }) {
  // Recursive function to create HTML for each component
  function createComponent(component: LayoutNode) {
    const div = document.createElement("div");
    div.className = "sortable-item";
    div.setAttribute("data-xb-uuid", component.uuid);

    // Check if the component has children to create a nested structure

    if (component.type === "component") {
      const header = document.createElement("h1");
      header.textContent = model[component.uuid]?.name || `debug: no name`;
      div.appendChild(header);
      div.setAttribute('data-xb-type', 'component');
    }
    if (component.children) {
      const innerDiv = document.createElement("div");
      if(component.type === 'slot') {
        innerDiv.className = "sortable-list";
        innerDiv.setAttribute("data-xb-uuid", component.uuid);
        div.setAttribute('data-xb-type', 'slot');
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
function debugCreateFullHtmlDocument(data: LayoutNode, model: {}): string {
  // Create a new document
  const doc = document.implementation.createHTMLDocument("New Document");

  // Add the style element to the head
  const styleEl = doc.createElement("style");
  styleEl.textContent = styleContent;
  doc.head.appendChild(styleEl);

  // Create the body content from the data
  const bodyContent = debugCreateHtmlFromData(data, model);
  // Append the body content to the new document's body
  doc.body.innerHTML = bodyContent;

  // Serialize the new document to a string
  const serializer = new XMLSerializer();
  const docString = serializer.serializeToString(doc);

  return docString;
}


export function makeServer({environment = "test"} = {}) {
  let server = createServer({
    environment,

    routes() {
      this.namespace = "api"

      this.post("/preview", (schema, request) => {

          const req = JSON.parse(request.requestBody)
          return {html: debugCreateFullHtmlDocument(req.layout, req.model)};
        },
        {timing: 2000}
      );


      this.get("/components", () => [
          {
            "name": "Component 1",
            "id": "1"
          },
          {
            "name": "Component 2",
            "id": "2"
          },
          {
            "name": "Component 3",
            "id": "3"
          },
          {
            "name": "Component 4",
            "id": "4"
          },
          {
            "name": "Component 5",
            "id": "5"
          }
        ],
        {timing: 2000}
      );

      this.get("/layout/:id", (schema, request) => {
          let id = request.params.id;

          console.log(id);
          return {
            "layout": {
              "uuid": "root",
              "type": "root",
              "name": "root",
              "children": [
                {
                  "uuid": "43cd7aa4-0160-4787-a3af-baf44ff17a88",
                  "children": [],
                  "type": "component",
                },
                {
                  "uuid": "fcd2490d-1124-4146-82b6-b1e049ed8026",
                  "type": "component",
                  "children": [
                    {
                      "name": "Slot 1",
                      "type": "slot",
                      "uuid": "05fa13be-8291-4955-aa89-32351f68e776",
                      "children": []
                    }
                  ]
                },
                {
                  "uuid": "1941ffae-f9ed-4ce3-8145-a2c3977ac65b",
                  "type": "component",
                  "children": [
                    {
                      "name": "Slot 1",
                      "type": "slot",
                      "uuid": "68cafa3e-bfd8-4767-a5cc-c18cce97c236",
                      "children": [
                        {
                          "type": "component",
                          "uuid": "bdfce52f-e666-49f0-a57f-dfb8c5c0c75b",
                          "children": []
                        }
                      ]
                    },
                    {
                      "name": "Slot 2",
                      "type": "slot",
                      "uuid": "584ae5f1-e242-4dad-991b-55ca20d0bfa4",
                      "children": [
                        {
                          "type": "component",
                          "uuid": "fe01d628-55ab-4146-9d04-71e5a01ad233",
                          "children": []
                        }
                      ]
                    }
                  ]
                }
              ]
            },
            model: {
              "43cd7aa4-0160-4787-a3af-baf44ff17a88": {
                name: 'Component 1 (no slots)',
              },
              "fcd2490d-1124-4146-82b6-b1e049ed8026": {
                name: 'Component 2 (1 slots)'
              },
              "1941ffae-f9ed-4ce3-8145-a2c3977ac65b": {
                name: 'Component 3 (2 slots)'
              },
              "fe01d628-55ab-4146-9d04-71e5a01ad233": {
                name: 'Component 4 (no slots)'
              },
              "bdfce52f-e666-49f0-a57f-dfb8c5c0c75b": {
                name: 'Component 5 (no slots)'
              },
            }
          }


        },
        {timing: 2000})
    },
  })

  return server
}
