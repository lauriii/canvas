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
        <div className="sidebar sidebar-left">
          <List/>
        </div>
        <div className="topbar">
          <div>Top bar</div>
        </div>
        <Preview iframeRef={iframeRef}/>
        <div className="sidebar sidebar-right">
          <h2>Layout blahhh</h2>
          <TreeView/>
        </div>
      </div>
    </div>
  );
};

export default App;
