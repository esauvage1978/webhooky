import React from 'react';
import ReactDOM from 'react-dom/client';
import '@fortawesome/fontawesome-free/css/all.min.css';
import App from './app/App.jsx';
import { installGlobalFetch401Handler } from './lib/sessionFetch.js';
import './styles/index.css';

installGlobalFetch401Handler();

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>,
);
