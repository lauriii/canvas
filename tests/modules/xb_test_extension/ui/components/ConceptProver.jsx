import { useSelector, useDispatch } from 'react-redux';
import Button from './Button';
import { useState } from 'react';

const ConceptProver = () => {
  const dispatch = useDispatch();
  const [selectedLayoutItem, setSelectedLayoutItem] = useState();
  const [selectedComponentType, setSelectedComponentType] = useState();
  // Get the uuid of the selected component from the Redux store.
  const selectedComponent = useSelector((state) => {
    return state.ui.readOnlySelectedComponent;
  });

  // Get the entire layout model from the redux store.
  const theLayout = useSelector((state) => state?.layoutModel?.present?.layout);

  const itemsInLayout = [];
  const flatComponentsList = (components) => {
    components.forEach((component) => {
      itemsInLayout.push(component);
      component.slots.forEach((slot) => flatComponentsList(slot.components));
    });
  };
  theLayout.forEach((region) => {
    flatComponentsList(region.components || []);
  });

  // Get the available components list from the redux store.
  const availableComponents = useSelector((state) => {
    return state?.componentAndLayoutApi?.queries['getComponents(undefined)']?.data;
  });

  // Create a dropdown with every available component as options.
  const componentsSelect = () => {
    return (
      <div>
        <label>
          Components Available to add:
          <select
            data-testid="ex-select-component"
            style={{ maxWidth: '250px', marginInlineStart: '12px' }}
            onChange={(e) => setSelectedComponentType(e.target.value)}
          >
            <option value="" key={99999999}>
              {typeof availableComponents === 'object'
                ? '--Select A Component--'
                : '-- Component List Not Ready --'}
            </option>
            {typeof availableComponents === 'object' &&
              Object.entries(availableComponents).map(([key, item], index) => (
                <option key={index} value={item.id}>
                  {item.name}
                </option>
              ))}
          </select>
          {/* When a component type is selected, provide the option to insert it in the layout. */}
          {selectedComponentType && (
            <Button
              data-testid="ex-insert"
              onClick={() => {
                let nodePath = [0];
                if (selectedComponent) {
                  // The component should be inserted after the selected component,
                  // so increase the path value if the final item by 1.
                  nodePath = drupalSettings.xb.layoutUtils.findNodePathByUuid(
                    theLayout,
                    selectedComponent,
                  );
                  nodePath[nodePath.length - 1] += 1;
                }
                dispatch(
                  drupalSettings.xb.layoutUtils.addNewComponentToLayout({
                    to: nodePath,
                    component: availableComponents[selectedComponentType],
                  }),
                );
              }}
            >
              insert
            </Button>
          )}
        </label>
      </div>
    );
  };

  const layoutItemsSelect = () => {
    if (!itemsInLayout) {
      return '';
    }

    return (
      <div>
        <label>
          Items in layout:
          <select
            data-testid="ex-select-in-layout"
            style={{ maxWidth: '250px' }}
            onChange={(e) => setSelectedLayoutItem(e.target.value)}
          >
            <option value="" key={99999999}>
              {itemsInLayout.length
                ? '--Choose an item in the layout--'
                : '-- No items in layout yet --'}
            </option>
            {itemsInLayout.map((item, index) => (
              <option key={index} value={item.uuid}>
                {item.type}({item.uuid})
              </option>
            ))}
          </select>
        </label>
        {/* If the above <select> has chosen an item, provide a way to focus it. */}
        {selectedLayoutItem && (
          <Button
            data-testid="ex-focus"
            onClick={() => {
              // Dispatch based on action name.
              // Update redux store so the layout item chosen is selected in the UI.
              drupalSettings.xb.navUtils.setSelectedComponent(
                selectedLayoutItem,
              );
            }}
          >
            focus
          </Button>
        )}

        {/* If the above <select> has chosen an item, provide a way to delete it. */}
        {selectedLayoutItem && (
          <Button
            data-testid="ex-delete"
            onClick={() => {
              // Dispatch based on action name.
              // Update redux store so the layout item chosen is selected in the UI.
              dispatch({
                type: 'layoutModel/deleteNode',
                payload: selectedLayoutItem,
              });
              // This sets the selected component to null so the contextual menu
              // closes instead of attempting to render to form for a deleted
              // component.
              dispatch({ type: 'ui/unsetSelectedComponent' });
              setSelectedLayoutItem(null);
            }}
          >
            delete
          </Button>
        )}
      </div>
    );
  };

  return (
    <>
      <div>
        {layoutItemsSelect()}
        {componentsSelect()}
        <div>
          <b>Event: Detect selected element</b>:{' '}
          <span data-testid="ex-selected-element">{selectedComponent}</span>
        </div>
      </div>
    </>
  );
};

export default ConceptProver;
