import {
  configurationSlice,
  setConfiguration,
} from '@/features/configuration/configurationSlice';

const initialState = {
  baseUrl: '/',
};

describe('Configuration slice', () => {
  it('Should set configuration', () => {
    const state = configurationSlice.reducer(
      initialState,
      setConfiguration({ baseUrl: '/xb/' }),
    );
    expect(state.baseUrl).to.eq('/xb/');
  });
});
