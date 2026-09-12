import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider } from './context/AuthContext';
import { ProtectedRoute } from './components/common/ProtectedRoute';
import { AppLayout } from './components/layout/AppLayout';
import { Login } from './pages/Login';
import { Dashboard } from './pages/Dashboard';

export default function App() {
  return (
    <AuthProvider>
      <BrowserRouter>
        <Routes>
          <Route path="/login" element={<Login />} />
          
          <Route element={<ProtectedRoute />}>
            <Route element={<AppLayout />}>
              <Route path="/dashboard" element={<Dashboard />} />
              <Route path="/academic" element={<div className="text-sm text-slate-600">Módulo Académico (Cursos, Actividades)</div>} />
              <Route path="/crm" element={<div className="text-sm text-slate-600">Módulo CRM y Oportunidades</div>} />
              <Route path="/billing" element={<div className="text-sm text-slate-600">Módulo de Facturación y Pagos</div>} />
              <Route path="/reports" element={<div className="text-sm text-slate-600">Módulo de Reportes</div>} />
              <Route path="/settings" element={<div className="text-sm text-slate-600">Configuración del Sistema</div>} />
            </Route>
          </Route>

          <Route path="*" element={<Navigate to="/dashboard" replace />} />
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  );
}