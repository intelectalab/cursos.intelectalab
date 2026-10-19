import { ErrorBoundary } from '../shared/ui/ErrorBoundary';
import { Suspense, useEffect } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { basename } from '../shared/tenancy/hosts';
import { TenantProvider, useTenant } from '../shared/tenancy/TenantContext';
import { AuthProvider } from '../features/auth/AuthContext';
import { ProtectedRoute, PermissionGate } from '../features/auth/ProtectedRoute';
import { AppLayout } from '../layouts/backoffice/AppLayout';
import { Login } from '../features/auth/pages/Login';
import { APP_MODULES } from '../config/modules';
import { LoadingState } from '../shared/ui';
import { lazyPage } from './lazyPage';

// Cada pantalla se descarga cuando se abre; solo el inicio de sesión viaja con el arranque
const SupportAccess = lazyPage(() => import('../features/auth/pages/SupportAccess'), 'SupportAccess');
const SignUp = lazyPage(() => import('../features/auth/pages/SignUp'), 'SignUp');
const Register = lazyPage(() => import('../features/auth/pages/Register'), 'Register');
const ForgotPassword = lazyPage(() => import('../features/auth/pages/PasswordRecovery'), 'ForgotPassword');
const ResetPassword = lazyPage(() => import('../features/auth/pages/PasswordRecovery'), 'ResetPassword');
const AccountPage = lazyPage(() => import('../features/account/pages/AccountPage'), 'AccountPage');
const CourseEditorPage = lazyPage(() => import('../features/courses/pages/CourseEditorPage'), 'CourseEditorPage');
const ClassroomPage = lazyPage(() => import('../features/enrollments/pages/ClassroomPage'), 'ClassroomPage');
const ClassroomPreviewPage = lazyPage(() => import('../features/enrollments/pages/ClassroomPage'), 'ClassroomPreviewPage');
const PlatformConsole = lazyPage(() => import('../features/platform/pages/PlatformConsole'), 'PlatformConsole');
const PublicCatalogPage = lazyPage(() => import('../features/sales/pages/CatalogPages'), 'PublicCatalogPage');
const PublicCoursePage = lazyPage(() => import('../features/sales/pages/CatalogPages'), 'PublicCoursePage');
const CheckoutPage = lazyPage(() => import('../features/sales/pages/CheckoutPage'), 'CheckoutPage');
const PackageCheckoutPage = lazyPage(() => import('../features/sales/pages/PackagePages'), 'PackageCheckoutPage');
const PublicPackagePage = lazyPage(() => import('../features/sales/pages/PackagePages'), 'PublicPackagePage');
const MyOrderPage = lazyPage(() => import('../features/sales/pages/MyOrdersPages'), 'MyOrderPage');
const VerifyPage = lazyPage(() => import('../features/certificates/pages/VerifyPage'), 'VerifyPage');
const CertificateEditorPage = lazyPage(() => import('../features/certificates/pages/CertificateEditorPage'), 'CertificateEditorPage');
const TeacherProfilePage = lazyPage(() => import('../features/teachers/pages/TeacherProfilePage'), 'TeacherProfilePage');
const PlanPage = lazyPage(() => import('../features/settings/pages/PlanPage'), 'PlanPage');
const StudentProfilePage = lazyPage(() => import('../features/students/pages/StudentProfilePage'), 'StudentProfilePage');
const CorporateAccountPage = lazyPage(() => import('../features/company/pages/CorporateAccountPage'), 'CorporateAccountPage');
const ClaimFormPage = lazyPage(() => import('../features/claims/pages/PublicClaimPages'), 'ClaimFormPage');
const ClaimLookupPage = lazyPage(() => import('../features/claims/pages/PublicClaimPages'), 'ClaimLookupPage');
const PageEditorPage = lazyPage(() => import('../features/cms/pages/PageEditorPage'), 'PageEditorPage');
const PublicPage = lazyPage(() => import('../features/cms/pages/PublicPage'), 'PublicPage');

// Dominio central de la plataforma: alta de organizaciones
function CentralRoutes() {
  return (
    <Routes>
      <Route path="/register" element={<Register />} />
      <Route path="/platform" element={<PlatformConsole />} />
      <Route path="/platform/tenants/:tenantId" element={<PlatformConsole />} />
      <Route path="/platform/plans" element={<PlatformConsole />} />
      <Route path="/platform/payments" element={<PlatformConsole />} />
      <Route path="/platform/panel" element={<PlatformConsole />} />
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
        {/* Entrada del soporte de plataforma, con un acceso corto de solo lectura */}
        <Route path="/support-access" element={<SupportAccess />} />

        {/* Catálogo público de la academia: no requiere sesión */}
        <Route path="/catalog" element={<PublicCatalogPage />} />
        <Route path="/catalog/packages/:packageId" element={<PublicPackagePage />} />
        <Route path="/catalog/:courseId" element={<PublicCoursePage />} />

        {/* Verificación pública de certificados: a esta dirección lleva el QR */}
        <Route path="/verify" element={<VerifyPage />} />
        <Route path="/verify/:code" element={<VerifyPage />} />
        {/* Libro de Reclamaciones: lo llena cualquier consumidor, sin cuenta */}
        <Route path="/reclamaciones" element={<ClaimFormPage />} />
        <Route path="/reclamaciones/seguimiento" element={<ClaimLookupPage />} />
        {/* Páginas publicadas de la academia */}
        <Route path="/p/:slug" element={<PublicPage />} />
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
              path="/checkout/package/:packageId"
              element={
                <PermissionGate permission="orders.create">
                  <PackageCheckoutPage />
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
            <Route
              path="/settings/plan"
              element={
                <PermissionGate permission="tenant_settings.read">
                  <PlanPage />
                </PermissionGate>
              }
            />
            <Route
              path="/teachers/:teacherId"
              element={
                <PermissionGate permission="teachers.read">
                  <TeacherProfilePage />
                </PermissionGate>
              }
            />
            <Route
              path="/students/:studentId"
              element={
                <PermissionGate permission="students.read">
                  <StudentProfilePage />
                </PermissionGate>
              }
            />
            <Route
              path="/courses/:courseId/preview"
              element={
                <PermissionGate permission={['courses.read', 'courses.assigned.read']}>
                  <ClassroomPreviewPage />
                </PermissionGate>
              }
            />
            <Route
              path="/crm/accounts/:companyId"
              element={
                <PermissionGate permission={['corporate_accounts.read', 'licenses.read']}>
                  <CorporateAccountPage />
                </PermissionGate>
              }
            />
            <Route
              path="/certificates/design"
              element={
                <PermissionGate permission="tenant_settings.update">
                  <CertificateEditorPage />
                </PermissionGate>
              }
            />
            <Route
              path="/pages/:pageId"
              element={
                <PermissionGate permission={['cms.read', 'cms.update', 'cms.publish']}>
                  <PageEditorPage />
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

// El título de la pestaña sigue al encabezado de la pantalla: dice dónde se está
// a quien usa lector de pantalla, y distingue las pestañas y el historial
function DocumentTitle() {
  const { tenant } = useTenant();
  const site = tenant?.name ?? 'IntelectaLab';

  useEffect(() => {
    const sync = () => {
      const heading = document.querySelector('h1')?.textContent?.trim();
      const title = heading ? `${heading} · ${site}` : site;
      if (document.title !== title) document.title = title;
    };

    sync();
    const observer = new MutationObserver(sync);
    observer.observe(document.body, { childList: true, subtree: true, characterData: true });
    return () => observer.disconnect();
  }, [site]);

  return null;
}

function AppRoutes() {
  const { tenant } = useTenant();
  return (
    <Suspense
      fallback={
        <div className="min-h-screen flex items-center justify-center">
          <LoadingState />
        </div>
      }
    >
      <ErrorBoundary>{tenant ? <TenantRoutes /> : <CentralRoutes />}</ErrorBoundary>
    </Suspense>
  );
}

export default function App() {
  return (
    <TenantProvider>
      <BrowserRouter basename={basename || undefined}>
        <DocumentTitle />
        <AppRoutes />
      </BrowserRouter>
    </TenantProvider>
  );
}
