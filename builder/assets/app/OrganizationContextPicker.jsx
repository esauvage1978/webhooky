import { useState } from 'react';
import { apiJsonInit, parseJson } from '../lib/http.js';
import { ORG_SESSION_KEY } from './routing.js';

/** Écran obligatoire après connexion si plusieurs organisations. */
export default function OrganizationContextPicker({ user, onComplete }) {
  const orgs = user.organizations ?? [];
  const [selectedId, setSelectedId] = useState(() => user.organization?.id ?? orgs[0]?.id ?? null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    if (!selectedId) return;
    setBusy(true);
    setError('');
    try {
      const res = await fetch(
        '/api/me/active-organization',
        apiJsonInit({
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ organizationId: selectedId }),
        }),
      );
      const data = await parseJson(res);
      if (!res.ok) {
        setError((data && data.error) || 'Échec de la sélection');
        setBusy(false);
        return;
      }
      sessionStorage.setItem(ORG_SESSION_KEY, '1');
      onComplete();
    } catch {
      setError('Erreur réseau');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="admin-org-context-overlay" role="dialog" aria-modal="true" aria-labelledby="org-context-title">
      <div className="admin-org-context-card">
        <h2 id="org-context-title">Organisation de travail</h2>
        <p>
          Votre compte est rattaché à plusieurs organisations. Choisissez celle dans laquelle vous travaillez pour cette
          session. Vous pourrez la modifier à tout moment depuis le menu latéral.
        </p>
        {error ? (
          <p className="admin-org-context-error" role="alert">
            {error}
          </p>
        ) : null}
        <div className="admin-org-context-list" role="radiogroup" aria-label="Organisations">
          {orgs.map((o) => (
            <label key={o.id} className="admin-org-context-option">
              <input
                type="radio"
                name="org-context"
                checked={selectedId === o.id}
                onChange={() => setSelectedId(o.id)}
              />
              <span>
                <strong>{o.name}</strong>
              </span>
            </label>
          ))}
        </div>
        <button type="button" className="btn" onClick={() => void submit()} disabled={busy || !selectedId}>
          {busy ? 'Validation…' : 'Continuer'}
        </button>
      </div>
    </div>
  );
}
