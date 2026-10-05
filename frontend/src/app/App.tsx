import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { TenantProvider, useTenant } from '../shared/tenancy/TenantContext';
import { AuthProvider } from '../features/auth/AuthContext';
import { ProtectedRoute, PermissionGate } from '../features/auth/ProtectedRoute';
import { AppLayout } from '../layouts/backoffice/AppLayout';
import { Login } from '../features/auth/pages/Login';
import { SignUp } from '../features/auth/pages/SignUp';
import { Register } from '../features/auth/pages/Register';
import { ForgotPassword, ResetPassword } from '../features/auth/pages/PasswordRecovery';
import { AccountPage } from '../features/account/pages/AccountPage';
import { CourseEditorPage } from '../features/courses/pages/CourseEditorPage';
import { ClassroomPage } from '../features/enrollments/pages/ClassroomPage';
import { PlatformConsole } from '../features/platform/pages/PlatformConsole';
import { PublicCatalogPage, PublicCoursePage } from '../features/sales/pages/CatalogPages';
import { CheckoutPage } from '../features/sales/pages/CheckoutPage';
import { MyOrderPage } from '../features/sales/pages/MyOrdersPages';
import { APP_MODULES } from '../config/modules';

// Dominio central de la plataforma: alta de organizaciones
function CentralRoutes() {
  return (
    <Routes>
      <Route path="/register" element={<Register />} />
      <Route path="/platform" element={<PlatformConsole />} />
      <Route path="*" element={<Navigate to="/register" replace />} />
    </Routes>
  );
}

// Subdominio de una organización
function TenantRoutes() {
  return (
    <AuthProvider>
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route path="/signup" element={<SignUp />} />

        {/* Catálogo público de la academia: no requiere sesión */}
        <Route path="/catalog" element={<PublicCatalogPage />} />
        <Route path="/catalog/:courseId" element={<PublicCoursePage />} />
        <Route path="/forgot-password" element={<ForgotPassword />} />
        <Route path="/reset-password" element={<ResetPassword />} />

        {/* Rutas Protegidas Mapeadas Automáticamente */}
        <Route element={<ProtectedRoute />}>
          <Route element={<AppLayout />}>
            {/* Cuenta propia: disponible para cualquier usuario autenticado */}
            <Route path="/account" element={<AccountPage />} />
            <Route
              path="/my-courses/:courseId"
              element={
                <PermissionGate permission="enrollments.self.read">
                  <ClassroomPage />
                </PermissionGate>
              }
            />
            <Route
              path="/checkout/:courseId"
              element={
                <PermissionGate permission="orders.create">
                  <CheckoutPage />
                </PermissionGate>
              }
            />
            <Route
              path="/my-orders/:orderId"
              element={
                <PermissionGate permission="orders.create">
                  <MyOrderPage />
                </PermissionGate>
              }
            />
            <Route
              path="/courses/:courseId"
              element={
                <PermissionGate permission={['courses.read', 'courses.assigned.read']}>
                  <CourseEditorPage />
                </PermissionGate>
              }
            />
            {APP_MODULES.map((mod) => {
              const Component = mod.component;
              return (
                <Route
                  key={mod.id}
                  path={mod.href}
                  element={
                    <PermissionGate permission={mod.permission}>
                      <Component />
                    </PermissionGate>
                  }
                />
              );
            })}
          </Route>
        </Route>

        {/* Ruta por defecto */}
        <Route path="*" element={<Navigate to="/dashboard" replace />} />
      </Routes>
    </AuthProvider>
  );
}

function AppRoutes() {
  const { tenant } = useTenant();
  return tenant ? <TenantRoutes /> : <CentralRoutes />;
}

export default function App() {
  return (
    <TenantProvider>
      <BrowserRouter>
        <AppRoutes />
      </BrowserRouter>
    </TenantProvider>
  );
}
