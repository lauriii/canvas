If your component uses function declaration syntax:
```jsx
  function MyComponent() {
    return <div>Hello world</div>
  }
```

Add "export default" in front, like so:
```jsx
  export default function MyComponent() {
    return <div>Hello world</div>;
  }
```

Or if it uses the arrow function syntax:
```jsx
  const MyComponent = () => {
    return <div>Hello world</div>;
  };
```

Add "export default" and the name of your component at the end:
```jsx
  const MyComponent = () => {
    return <div>Hello world</div>;
  };
  export default MyComponent;
```
