import { useState } from 'react';

export default function LoginForm({ onSuccess }) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [pending, setPending] = useState(false);

  const submit = async (e) => {
    e.preventDefault();
    setError('');
    setPending(true);
    try {
      const res = await fetch('/api/login', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ email, password }),
      });
      if (!res.ok) {
        const data = await res.json().catch(() => ({}));
        setError(data.error ?? 'Échec de la connexion');
        return;
      }
      await onSuccess();
    } catch {
      setError('Erreur réseau');
    } finally {
      setPending(false);
    }
  };

  return (
    <form className="login-form" onSubmit={(e) => void submit(e)}>
      <label className="field">
        <span>Identifiant (e-mail)</span>
        <input
          type="email"
          name="email"
          autoComplete="username"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          required
        />
      </label>
      <label className="field">
        <span>Mot de passe</span>
        <input
          type="password"
          name="password"
          autoComplete="current-password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          required
        />
      </label>
      {error ? <p className="error">{error}</p> : null}
      <button type="submit" className="btn" disabled={pending}>
        {pending ? 'Connexion…' : 'Se connecter'}
      </button>
      <p className="login-switch">
        <a href="/inscription" className="login-inline-link">
          Créer un compte
        </a>
        {' · '}
        <a href="/mot-de-passe-oublie" className="login-inline-link">
          Mot de passe oublié
        </a>
      </p>
    </form>
  );
}
