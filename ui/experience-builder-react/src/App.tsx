import "./App.css";
import { useRef, useState } from "react";
import Preview from "./features/layout/preview/Preview";
import TreeView from "./features/layout/tree/TreeView";

const App = () => {
  const iframeRef = useRef(null);

  // const moveInArray = (fromIndex, toIndex) => {
  //   setLayout((layoutState) => {
  //     if (fromIndex === toIndex || layoutState === undefined) return layoutState;
  //
  //     const newArray = [...layoutState];
  //     if (toIndex >= newArray.length) {
  //       newArray.push(null);
  //     }
  //     newArray.splice(toIndex, 0, newArray.splice(fromIndex, 1)[0]);
  //     return newArray;
  //   })
  //
  // };

  return (
    <div className="App">
      <div className="app-container">
        <div className="sidebar">
          <h2>Components</h2>
          <ul>
            <li>Component 1</li>
            <li>Component 2</li>
            <li>Component 3</li>
            <li>Component 4</li>
          </ul>
          <h2>Layout</h2>
          <TreeView />
        </div>
        <div className="topbar">
          <div>Top bar</div>
        </div>
        <Preview iframeRef={iframeRef} />
      </div>
    </div>
  );
};

export default App;
