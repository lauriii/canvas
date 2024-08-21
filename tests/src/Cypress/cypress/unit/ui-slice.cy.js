import {
  uiSlice,
  initialState,
  setSelectedComponent,
  unsetSelectedComponent,
  setHoveredComponent,
  unsetHoveredComponent,
} from '../../../../../ui/src/features/ui/uiSlice';

describe('Set selected component', () => {
  it('Should set model and layout', () => {
    const state = uiSlice.reducer(initialState, setSelectedComponent('12345'));
    expect(state.selectedComponent).to.eq('12345');
  });
});

describe('Unset selected component', () => {
  it('Should set model and layout', () => {
    const state = uiSlice.reducer(initialState, unsetSelectedComponent());
    expect(state.selectedComponent).to.eq(undefined);
  });
});

describe('Set hovered component', () => {
  it('Should set hovered component to the passed ID', () => {
    const state = uiSlice.reducer(initialState, setHoveredComponent('12345'));
    expect(state.hoveredComponent).to.eq('12345');
  });
});

describe('Unset hovered component', () => {
  it('Should set model and layout', () => {
    const state = uiSlice.reducer(initialState, unsetHoveredComponent());
    expect(state.hoveredComponent).to.eq(undefined);
  });
});
