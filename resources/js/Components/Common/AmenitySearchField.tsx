import React, { useId, useState } from 'react';
import { Search, X } from 'lucide-react';
import '../../../css/scss/amenity-search.scss';

type Suggestion = { id: string; amenity_name: string; amenity_code: string };
const normalize = (value: string) => value.normalize('NFC').toLocaleLowerCase('vi').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd');

export function highlightAmenityText(value: string | null | undefined, query: string): React.ReactNode {
  const text = (value ?? '').normalize('NFC');
  const needle = normalize(query.trim());
  const index = needle ? normalize(text).indexOf(needle) : -1;
  if (index < 0) return text;
  return <>{text.slice(0, index)}<mark>{text.slice(index, index + needle.length)}</mark>{text.slice(index + needle.length)}</>;
}

export function AmenitySearchField({ value, onChange, suggestions, loading = false, className = '', placeholder = 'Tên hoặc mã tiện ích…' }: {
  value: string; onChange: (value: string) => void; suggestions: Suggestion[]; loading?: boolean; className?: string; placeholder?: string;
}) {
  const id = useId();
  const [focused, setFocused] = useState(false);
  const [active, setActive] = useState(-1);
  const items = suggestions.slice(0, 5);
  const open = focused && value.trim().length >= 2 && !loading && items.length > 0;
  const choose = (item: Suggestion) => { onChange(item.amenity_name); setFocused(false); setActive(-1); };

  return <div className="amenity-search-field" onBlur={(event) => { if (!event.currentTarget.contains(event.relatedTarget)) setFocused(false); }}>
    <Search aria-hidden="true" className="amenity-search-field__icon" />
    <input role="combobox" aria-label="Tìm tiện ích" aria-autocomplete="list" aria-expanded={open} aria-controls={open ? id : undefined} aria-activedescendant={open && active >= 0 && active < items.length ? `${id}-${active}` : undefined}
      className={className} value={value} maxLength={200} placeholder={placeholder}
      onFocus={() => setFocused(true)} onChange={(event) => { onChange(event.target.value); setActive(-1); setFocused(true); }}
      onKeyDown={(event) => {
        if (event.key === 'Escape') { event.stopPropagation(); setFocused(false); setActive(-1); }
        if (open && ['ArrowDown', 'ArrowUp'].includes(event.key)) { event.preventDefault(); setActive((current) => event.key === 'ArrowDown' ? (current + 1) % items.length : current <= 0 ? items.length - 1 : current - 1); }
        if (open && items[active] && event.key === 'Enter') { event.preventDefault(); choose(items[active]); }
      }} />
    {value && <button type="button" className="amenity-search-field__clear" aria-label="Xóa từ khóa" onClick={() => { onChange(''); setActive(-1); }}><X aria-hidden="true" size={16} /></button>}
    {open && <ul id={id} role="listbox" aria-label="Tiện ích gợi ý" className="amenity-search-field__suggestions">
      {items.map((item, index) => <li key={item.id} id={`${id}-${index}`} role="option" aria-selected={active === index} onMouseDown={(event) => event.preventDefault()} onClick={() => choose(item)} onMouseMove={() => setActive(index)}>
        <span>{highlightAmenityText(item.amenity_name, value)}</span><small>{item.amenity_code}</small>
      </li>)}
    </ul>}
  </div>;
}
