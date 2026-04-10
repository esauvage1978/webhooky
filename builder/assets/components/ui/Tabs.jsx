import { memo, useCallback } from 'react';

/**
 * Onglets accessibles (WAI-ARIA).
 *
 * @param {object} props
 * @param {{ id: string, label: unknown }[]} props.items
 * @param {string} props.activeId
 * @param {(id: string) => void} props.onChange
 * @param {string} [props.ariaLabel]
 * @param {string} [props.className]
 */
function Tabs({ items, activeId, onChange, ariaLabel = 'Onglets', className = '' }) {
  const select = useCallback(
    (id) => {
      onChange(id);
    },
    [onChange],
  );

  return (
    <div className={`wh-tabs ${className}`.trim()} role="tablist" aria-label={ariaLabel}>
      {items.map((item) => (
        <button
          key={item.id}
          type="button"
          role="tab"
          aria-selected={activeId === item.id}
          id={`tab-${item.id}`}
          aria-controls={`panel-${item.id}`}
          className={activeId === item.id ? 'wh-tabs__btn wh-tabs__btn--active' : 'wh-tabs__btn'}
          onClick={() => select(item.id)}
        >
          {item.label}
        </button>
      ))}
    </div>
  );
}

export default memo(Tabs);
