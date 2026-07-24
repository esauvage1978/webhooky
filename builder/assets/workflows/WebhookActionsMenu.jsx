import { useEffect, useRef } from 'react';

export function WebhookActionsMenu({
  menuKey,
  menuOpen,
  onMenuOpenChange,
  triggerVariant = 'dots',
  showFiche = true,
  onFiche,
  onLogs,
  onEdit,
  onDuplicate,
  onDelete,
  duplicateDisabled = false,
  duplicateTitle,
}) {
  const open = menuOpen === menuKey;
  const wrapRef = useRef(null);

  useEffect(() => {
    if (!open) return;
    const onDoc = (e) => {
      if (wrapRef.current && !wrapRef.current.contains(e.target)) {
        onMenuOpenChange(null);
      }
    };
    const onKey = (e) => {
      if (e.key === 'Escape') onMenuOpenChange(null);
    };
    document.addEventListener('click', onDoc, true);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('click', onDoc, true);
      document.removeEventListener('keydown', onKey);
    };
  }, [open, onMenuOpenChange]);

  const close = () => onMenuOpenChange(null);

  const isDots = triggerVariant === 'dots';

  return (
    <div className="fw-card-menu-wrap" ref={wrapRef}>
      <button
        type="button"
        className={`fw-card-menu-trigger${isDots ? ' fw-card-menu-trigger--dots' : ''}`}
        aria-expanded={open}
        aria-haspopup="menu"
        aria-label={isDots ? 'Plus d’actions' : undefined}
        onClick={(e) => {
          e.stopPropagation();
          onMenuOpenChange(open ? null : menuKey);
        }}
      >
        {isDots ? (
          <span className="fw-card-menu-dots" aria-hidden>
            ⋯
          </span>
        ) : (
          <>
            Actions
            <span className="fw-card-menu-chevron" aria-hidden>
              ▾
            </span>
          </>
        )}
      </button>
      {open ? (
        <ul className="fw-card-menu-dropdown" role="menu">
          <li role="none">
            <button
              type="button"
              className="fw-card-menu-item"
              role="menuitem"
              onClick={() => {
                close();
                onEdit?.();
              }}
            >
              Éditer
            </button>
          </li>
          {showFiche ? (
            <li role="none">
              <button
                type="button"
                className="fw-card-menu-item"
                role="menuitem"
                onClick={() => {
                  close();
                  onFiche?.();
                }}
              >
                Fiche
              </button>
            </li>
          ) : null}
          <li role="none">
            <button
              type="button"
              className="fw-card-menu-item"
              role="menuitem"
              onClick={() => {
                close();
                onLogs?.();
              }}
            >
              Journaux
            </button>
          </li>
          <li role="none">
            <button
              type="button"
              className="fw-card-menu-item"
              role="menuitem"
              disabled={duplicateDisabled}
              title={duplicateTitle}
              onClick={() => {
                if (duplicateDisabled) return;
                close();
                onDuplicate?.();
              }}
            >
              Dupliquer
            </button>
          </li>
          <li role="none">
            <button
              type="button"
              className="fw-card-menu-item fw-card-menu-item--danger"
              role="menuitem"
              onClick={() => {
                close();
                onDelete?.();
              }}
            >
              Supprimer
            </button>
          </li>
        </ul>
      ) : null}
    </div>
  );
}
