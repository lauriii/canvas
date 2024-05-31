import { useState } from 'react';
import LoginForm from './components/LoginForm';
import Welcome from './components/Welcome';

function App() {
  const [isAuthenticated, setIsAuthenticated] = useState(false);
  const [errorMessage, setErrorMessage] = useState('');
  const [username, setUsername] = useState('');

  const handleLogin = async (credentials) => {
    try {
      setErrorMessage('');
      const res = await fetch('/auth', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(credentials),
      });
      if (res.status === 200) {
        setIsAuthenticated(true);
        setUsername(credentials.username);
      } else {
        if (res.status === 401) {
          const { message } = await res.json();
          setErrorMessage(message);
        } else {
          throw Error(`error during auth, status code: ${res.status}`);
        }
      }
    } catch (e) {
      console.error(e);
    }
  };

  return (
    <div className="lg:container lg mx-auto m-10">
      {isAuthenticated ? (
        <Welcome
          username={username}
          onLogout={() => {
            setIsAuthenticated(false);
            setUsername('');
          }}
        />
      ) : (
        <LoginForm onLogin={handleLogin} errorMessage={errorMessage} />
      )}
    </div>
  );
}

export default App;
