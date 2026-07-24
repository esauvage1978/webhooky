export function FwSwitch({ id, checked, onChange, label, disabled }) {
  const labelId = id ? `${id}-label` : undefined;
  return (
    <div className={`fw-switch-row${disabled ? ' is-disabled' : ''}`}>
      {label ? (
        <span className="fw-switch-label" id={labelId}>
          {label}
        </span>
      ) : null}
      <button
        type="button"
        id={id}
        className={`fw-switch-track${checked ? ' is-on' : ''}`}
        role="switch"
        aria-checked={checked}
        disabled={disabled}
        aria-labelledby={labelId}
        onClick={() => !disabled && onChange(!checked)}
      >
        <span className="fw-switch-thumb" aria-hidden />
      </button>
    </div>
  );
}
