import { useEffect, useState } from 'react';
import { parseJson } from '../lib/http.js';

export default function AccountProfile({ user, onSessionRefresh }) {
  const [displayName, setDisplayName] = useState(() => user.displayName ?? '');
  const [error, setError] = useState('');
  const [fields, setFields] = useState({});
  const [pending, setPending] = useState(false);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    setDisplayName(user.displayName ?? '');
  }, [user.displayName]);

  const submit = async (e) => {
    e.preventDefault();
    setError('');
    setFields({});
    setSaved(false);
    setPending(true);
    try {
      const res = await fetch('/api/me/profile', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ displayName: displayName.trim() }),
      });
      const data = await parseJson(res);
      if (!res.ok) {
        if (data?.fields && typeof data.fields === 'object') setFields(data.fields);
        setError(data?.error && typeof data.error === 'string' ? data.error : 'Enregistrement impossible');
        return;
      }
      setSaved(true);
      await onSessionRefresh?.({ quiet: true });
    } catch {
      setError('Erreur réseau');
    } finally {
      setPending(false);
    }
  };

  return (
    <div className="users-shell account-settings-page">
      <header className="users-hero users-hero--minimal">
        <div className="users-hero-text">
          <h1 className="users-hero-title">
            <i className="fa-solid fa-user" aria-hidden />
            <span>Mon profil</span>
          </h1>
          <p className="users-hero-sub muted">
            Votre nom affiché est visible dans l’interface et auprès de votre équipe.
          </p>
        </div>
      </header>
      <div className="content-card">
        <form className="mailjet-form account-form account-section" onSubmit={(e) => void submit(e)}>
        <label className="field">
          <span>E-mail</span>
          <input
            value={typeof user.email === 'string' ? user.email : ''}
            readOnly
            className="input-readonly"
            autoComplete="username"
            placeholder={user.email ? undefined : 'Non communiqué par la session — reconnectez-vous ou contactez le support'}
          />
        </label>
        <label className="field">
          <span>Nom d’affichage</span>
          <input
            value={displayName}
            onChange={(e) => setDisplayName(e.target.value)}
            maxLength={120}
            autoComplete="nickname"
            required
            placeholder="Ex. Marie D."
          />
        </label>
        {fields.displayName ? <p className="error">{fields.displayName}</p> : null}
        {error ? <p className="error">{error}</p> : null}
        {saved ? (
          <p className="auth-notice ok" role="status">
            Modifications enregistrées.
          </p>
        ) : null}
        <button type="submit" className="btn" disabled={pending || !displayName.trim()}>
          {pending ? 'Enregistrement…' : 'Enregistrer'}
        </button>
      </form>
      </div>
    </div>
  );
}
