import { Fragment, useEffect, useRef, useState } from 'react';
import { formatKvValue, formatRawBodyForDisplay } from './formWebhookUtils.js';

export function LogCopyButton({ text, ariaLabel, ariaLabelDone }) {
  const [copied, setCopied] = useState(false);
  const timerRef = useRef(null);

  useEffect(() => () => {
    if (timerRef.current != null) window.clearTimeout(timerRef.current);
  }, []);

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(text);
      setCopied(true);
      if (timerRef.current != null) window.clearTimeout(timerRef.current);
      timerRef.current = window.setTimeout(() => setCopied(false), 1600);
    } catch {
      setCopied(false);
    }
  };

  return (
    <button
      type="button"
      className={`log-copy-btn ${copied ? 'log-copy-btn--done' : ''}`}
      onClick={() => void copy()}
      title={copied ? 'Copié' : 'Copier dans le presse-papiers'}
      aria-label={copied ? ariaLabelDone : ariaLabel}
    >
      <i className={`fa-solid ${copied ? 'fa-check' : 'fa-copy'}`} aria-hidden />
      <span>{copied ? 'Copié' : 'Copier'}</span>
    </button>
  );
}

export function KeyValueBlock({ title, record, emptyText, copyable = false }) {
  const entries =
    record && typeof record === 'object' && !Array.isArray(record) ? Object.entries(record) : [];
  const copyText =
    copyable && entries.length > 0 ? JSON.stringify(Object.fromEntries(entries), null, 2) : '';

  const titleRow = (
    <div className="log-kv-title-row">
      <h4 className="log-kv-title">{title}</h4>
      {copyable && copyText ? (
        <LogCopyButton
          text={copyText}
          ariaLabel={`Copier ${title}`}
          ariaLabelDone={`${title} copié`}
        />
      ) : null}
    </div>
  );

  if (entries.length === 0) {
    return (
      <div className="log-kv-block">
        {copyable ? titleRow : <h4 className="log-kv-title">{title}</h4>}
        <p className="muted small">{emptyText ?? 'Aucune donnée'}</p>
      </div>
    );
  }
  return (
    <div className="log-kv-block">
      {copyable ? titleRow : <h4 className="log-kv-title">{title}</h4>}
      <dl className="log-kv-grid">
        {entries.map(([k, v]) => {
          const display = formatKvValue(v);
          const multiline = display.includes('\n') || display.length > 120;
          return (
            <Fragment key={k}>
              <dt className="mono">{k}</dt>
              <dd className={multiline ? 'log-kv-dd-pre' : ''}>
                {multiline ? <pre className="log-kv-pre">{display}</pre> : display}
              </dd>
            </Fragment>
          );
        })}
      </dl>
    </div>
  );
}

export function CopyableRawBody({ body }) {
  const formatted = formatRawBodyForDisplay(body);
  return (
    <div className="log-kv-block">
      <div className="log-kv-title-row">
        <h4 className="log-kv-title">Corps brut</h4>
        <LogCopyButton
          text={formatted}
          ariaLabel="Copier le corps brut"
          ariaLabelDone="Corps brut copié"
        />
      </div>
      <pre className="log-raw-body">{formatted}</pre>
    </div>
  );
}
