import "./App.css";
import { useRef, useState } from "react";
import Preview from "./features/layout/preview/Preview";
import TreeView from "./features/layout/tree/TreeView";
import List from "./features/list/List";

const App = () => {
  const iframeRef = useRef(null);

  return (
    <div className="App">
      <div className="app-container">
        <div className="sidebar">
          <List />
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
