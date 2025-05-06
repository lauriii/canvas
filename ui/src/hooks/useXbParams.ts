import { useParams } from 'react-router-dom';

// TODO this can be refactored away in a later MR, but don't want to pollute my MR with changing all the places
// useXbParams is called. https://www.drupal.org/i/3522387
function useXbParams() {
  return useParams();
}

export default useXbParams;
