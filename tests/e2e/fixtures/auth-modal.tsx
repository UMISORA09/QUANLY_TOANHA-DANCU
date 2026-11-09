import React, { useState } from 'react';
import { createRoot } from 'react-dom/client';
import { AuthModal } from '../../../resources/js/Components/AuthModal';
import { LoginPage } from '../../../resources/js/Pages/Auth/LoginPage';
function Harness() {
  const [open, setOpen] = useState(true);
  const [role, setRole] = useState('');
  if (new URLSearchParams(window.location.search).get('view') === 'login') {
    return <><LoginPage onNavigate={() => {}} onLoginSuccess={setRole} />
      <output data-testid="authenticated-role">{role}</output></>;
  }
  return <><AuthModal isOpen={open} initialRole="admin" onClose={() => setOpen(false)}
    onSuccess={(value) => setRole(value)} /><output data-testid="authenticated-role">{role}</output></>;
}
createRoot(document.getElementById('root')!).render(<Harness />);
