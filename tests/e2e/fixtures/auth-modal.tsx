import React, { useState } from 'react';
import { createRoot } from 'react-dom/client';
import { AuthModal } from '../../../resources/js/Components/AuthModal';
function Harness() {
  const [open, setOpen] = useState(true);
  const [role, setRole] = useState('');
  return <><AuthModal isOpen={open} initialRole="admin" onClose={() => setOpen(false)}
    onSuccess={(value) => setRole(value)} /><output data-testid="authenticated-role">{role}</output></>;
}
createRoot(document.getElementById('root')!).render(<Harness />);
