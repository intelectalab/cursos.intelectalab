import React, { useState } from 'react';
import { useNavigate, Navigate, Link } from 'react-router-dom';
import { useAuth } from '../AuthContext';
import { api, apiErrorMessage } from '../../../shared/api/client';
import { useTenant } from '../../../shared/tenancy/TenantContext';
import { centralUrl } from '../../../shared/tenancy/hosts';
import { 
  Lock, 
  Mail, 
  AlertCircle, 
  Loader2, 
  Eye, 
  EyeOff, 
  Users, 
  ShieldCheck, 
  Building2,
  ArrowRight,
  Sparkles
} from 'lucide-react';

export const Login: React.FC = () => {
  const navigate = useNavigate();
  const { login, isAuthenticated } = useAuth();
  const { tenant, centralDomain } = useTenant();

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [rememberMe, setRememberMe] = useState(true);
  const [showPassword, setShowPassword] = useState(false);
  const [status, setStatus] = useState<'idle' | 'processing' | 'error'>('idle');
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  if (isAuthenticated) {
    return <Navigate to="/dashboard" replace />;
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setStatus('processing');
    setErrorMessage(null);

    try {
      const response = await api.post('/auth/login', { email, password });
      login(response.data.data);
      navigate('/dashboard', { replace: true });
    } catch (err) {
      setStatus('error');
      setErrorMessage(
        apiErrorMessage(err, 'Credenciales no válidas o error en el servidor.')
      );
    }
  };

  return (
    <div className="relative min-h-screen w-full flex flex-col lg:flex-row font-sans bg-[#F0F4F8] selection:bg-[#0284C7] selection:text-white overflow-hidden">
      
      {/* Estilos embebidos para animaciones personalizadas fluidas */}
      <style>{`
        @keyframes gridScroll {
          0% { background-position: 0 0; }
          100% { background-position: 50px 50px; }
        }
        @keyframes blobFloat1 {
          0%, 100% { transform: translate(0, 0) scale(1); }
          50% { transform: translate(40px, -30px) scale(1.15); }
        }
        @keyframes blobFloat2 {
          0%, 100% { transform: translate(0, 0) scale(1.1); }
          50% { transform: translate(-30px, 40px) scale(0.95); }
        }
        @keyframes shimmerLight {
          0% { transform: translateX(-150%); }
          100% { transform: translateX(200%); }
        }
        .animated-grid {
          background-image: 
            linear-gradient(to right, rgba(255, 255, 255, 0.08) 1px, transparent 1px),
            linear-gradient(to bottom, rgba(255, 255, 255, 0.08) 1px, transparent 1px);
          background-size: 50px 50px;
          animation: gridScroll 18s linear infinite;
        }
        .animate-blob-1 {
          animation: blobFloat1 10s ease-in-out infinite;
        }
        .animate-blob-2 {
          animation: blobFloat2 14s ease-in-out infinite;
        }
        .shimmer-effect {
          animation: shimmerLight 3.5s infinite;
        }
      `}</style>

      {/* PANEL IZQUIERDO: Escena Visual con Aurora y Fondo Dinámico */}
      <div className="relative lg:w-[56%] bg-gradient-to-br from-[#041E3D] via-[#062F5E] to-[#0B4D94] p-8 sm:p-12 lg:p-16 flex flex-col justify-between min-h-[480px] lg:min-h-screen text-white overflow-hidden">
        
        {/* Cuadrícula arquitectónica con movimiento sutil */}
        <div className="absolute inset-0 pointer-events-none animated-grid opacity-30" />

        {/* Orbes de luz Aurora flotantes en el fondo */}
        <div className="absolute -top-24 -left-24 w-[450px] h-[450px] bg-[#0284C7]/30 rounded-full blur-[110px] pointer-events-none animate-blob-1" />
        <div className="absolute bottom-10 right-10 w-[420px] h-[420px] bg-[#38BDF8]/20 rounded-full blur-[130px] pointer-events-none animate-blob-2" />
        <div className="absolute top-1/2 left-1/3 w-[300px] h-[300px] bg-[#084586]/40 rounded-full blur-[90px] pointer-events-none" />

        {/* 1. Header con Logo y Badge de Estado Activo */}
        <div className="relative z-10 flex items-center justify-between">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-xl bg-white/10 backdrop-blur-md border border-white/20 flex items-center justify-center shadow-lg shadow-black/10 group transition-all duration-300 hover:scale-105 hover:border-[#38BDF8]">
              {/* Matraz IntelectaLab con burbujas luminosas */}
              <svg 
                className="w-6 h-6 text-white transition-transform duration-500 group-hover:rotate-6" 
                viewBox="0 0 48 48" 
                fill="none" 
                stroke="currentColor" 
                strokeWidth="2.5" 
                strokeLinecap="round" 
                strokeLinejoin="round"
              >
                <path d="M19 6h10M21 6v10l-11 18a4 4 0 003.4 6h21.2a4 4 0 003.4-6L27 16V6" />
                <path d="M14 28c3 1 7-1 10 0s7 1 10 0" fill="#38BDF8" fillOpacity="0.45" stroke="none" />
                <circle cx="24" cy="22" r="1.5" fill="#38BDF8" className="animate-pulse" />
                <circle cx="21" cy="25" r="1" fill="#FFFFFF" />
              </svg>
            </div>
            <div>
              <span className="font-extrabold text-xl tracking-tight text-white drop-shadow-sm">
                Intelecta<span className="text-[#38BDF8]">Lab_</span>
              </span>
              <span className="block text-[9px] uppercase tracking-widest font-semibold text-blue-200">
                SaaS Enterprise Suite
              </span>
            </div>
          </div>

          {/* Badge 'Live Status' */}
          <div className="hidden sm:flex items-center gap-2 px-3 py-1 rounded-full bg-white/10 backdrop-blur-md border border-white/15 text-xs text-blue-100 shadow-sm">
            <span className="relative flex h-2 w-2">
              <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75" />
              <span className="relative inline-flex rounded-full h-2 w-2 bg-emerald-500" />
            </span>
            <span className="text-[11px] font-medium tracking-wide">Multi-Tenant v12.0</span>
          </div>
        </div>

        {/* 2. Hero Central */}
        <div className="relative z-10 my-auto py-10">
          <div className="inline-flex items-center gap-2 px-3 py-1 rounded-lg bg-[#38BDF8]/10 border border-[#38BDF8]/25 text-[#38BDF8] text-xs font-semibold uppercase tracking-wider mb-5">
            <Sparkles className="w-3.5 h-3.5 animate-spin" style={{ animationDuration: '8s' }} />
            Arquitectura de Aislamiento PostgreSQL
          </div>

          <h1 className="text-3xl sm:text-4xl lg:text-5xl font-black tracking-tight leading-[1.12] max-w-xl text-white mb-5 drop-shadow">
            Toda tu operación, <br />
            <span className="bg-gradient-to-r from-white via-blue-100 to-[#38BDF8] bg-clip-text text-transparent">
              en un solo lugar.
            </span>
          </h1>

          <p className="text-blue-100/80 text-sm sm:text-base max-w-lg leading-relaxed mb-10 font-normal">
            Aprovisiona organizaciones, coordina roles con permisos RBAC en tiempo real y administra datos de alta escala de manera blindada e independiente.
          </p>

          {/* 3 Tarjetas Traslúcidas Reactivas al Cursor */}
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-3.5 max-w-2xl">
            
            <div className="group relative bg-white/[0.05] hover:bg-white/[0.12] backdrop-blur-md border border-white/10 hover:border-[#38BDF8]/40 rounded-2xl p-4.5 transition-all duration-300 hover:-translate-y-1.5 hover:shadow-xl hover:shadow-[#0284C7]/20">
              <div className="w-9 h-9 rounded-xl bg-gradient-to-br from-[#0284C7]/20 to-[#38BDF8]/30 flex items-center justify-center text-[#38BDF8] mb-3 group-hover:scale-110 transition-transform">
                <Users className="w-4 h-4" />
              </div>
              <p className="text-xs font-semibold text-white/95 leading-snug">
                Gestión centralizada
              </p>
              <p className="text-[10px] text-blue-200/65 mt-1 leading-tight">
                Control multiorganización sin fricciones
              </p>
            </div>

            <div className="group relative bg-white/[0.05] hover:bg-white/[0.12] backdrop-blur-md border border-white/10 hover:border-[#38BDF8]/40 rounded-2xl p-4.5 transition-all duration-300 hover:-translate-y-1.5 hover:shadow-xl hover:shadow-[#0284C7]/20">
              <div className="w-9 h-9 rounded-xl bg-gradient-to-br from-[#0284C7]/20 to-[#38BDF8]/30 flex items-center justify-center text-[#38BDF8] mb-3 group-hover:scale-110 transition-transform">
                <ShieldCheck className="w-4 h-4" />
              </div>
              <p className="text-xs font-semibold text-white/95 leading-snug">
                Matriz RBAC granular
              </p>
              <p className="text-[10px] text-blue-200/65 mt-1 leading-tight">
                Permisos validados en middleware
              </p>
            </div>

            <div className="group relative bg-white/[0.05] hover:bg-white/[0.12] backdrop-blur-md border border-white/10 hover:border-[#38BDF8]/40 rounded-2xl p-4.5 transition-all duration-300 hover:-translate-y-1.5 hover:shadow-xl hover:shadow-[#0284C7]/20">
              <div className="w-9 h-9 rounded-xl bg-gradient-to-br from-[#0284C7]/20 to-[#38BDF8]/30 flex items-center justify-center text-[#38BDF8] mb-3 group-hover:scale-110 transition-transform">
                <Building2 className="w-4 h-4" />
              </div>
              <p className="text-xs font-semibold text-white/95 leading-snug">
                Multi-tenant real
              </p>
              <p className="text-[10px] text-blue-200/65 mt-1 leading-tight">
                Aislamiento estricto en BD
              </p>
            </div>

          </div>
        </div>

        {/* 3. Footer */}
        <div className="relative z-10 text-[11px] text-blue-200/60 font-medium">
          &copy; {new Date().getFullYear()} IntelectaLab. Todos los derechos reservados.
        </div>
      </div>

      {/* PANEL DERECHO: Tarjeta Flotante con efecto Glassmorphism */}
      <div className="lg:w-[44%] flex flex-col justify-center items-center px-6 sm:px-10 lg:px-16 py-12 relative z-10">
        
        {/* Glow de fondo detrás de la tarjeta en desktop */}
        <div className="absolute w-72 h-72 bg-[#0284C7]/15 rounded-full blur-3xl pointer-events-none" />

        <div className="w-full max-w-[420px] bg-white/90 backdrop-blur-xl rounded-3xl shadow-[0_20px_50px_-15px_rgba(6,47,94,0.12)] border border-white/60 p-8 sm:p-10 relative">
          
          <div className="mb-7">
            <h2 className="text-2xl font-black text-slate-900 tracking-tight">
              Bienvenido de nuevo
            </h2>
            <p className="text-xs text-slate-500 mt-1">
              Ingresa tus credenciales para continuar al panel de{' '}
              <span className="font-semibold text-slate-700">{tenant?.name}</span>.
            </p>
          </div>

          {status === 'error' && errorMessage && (
            <div className="mb-5 p-3.5 bg-red-50/90 border border-red-200 rounded-xl flex items-start gap-2.5 text-xs text-red-600 animate-fadeIn">
              <AlertCircle className="w-4 h-4 shrink-0 mt-0.5" />
              <span className="font-medium">{errorMessage}</span>
            </div>
          )}

          <form onSubmit={handleSubmit} className="space-y-4">
            
            {/* Campo Correo con efecto Focus Glow */}
            <div>
              <label className="block text-[11px] font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                Correo electrónico
              </label>
              <div className="relative group">
                <Mail className="w-4 h-4 text-slate-400 group-focus-within:text-[#0284C7] transition-colors absolute left-3.5 top-3.5 pointer-events-none" />
                <input
                  type="email"
                  required
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  disabled={status === 'processing'}
                  className="w-full pl-10 pr-3.5 py-2.5 bg-[#EFF4FA]/70 border border-slate-200/80 rounded-xl text-xs text-slate-900 placeholder-slate-400 focus:bg-white focus:border-[#0284C7] focus:ring-4 focus:ring-[#0284C7]/15 focus:outline-none transition-all duration-200"
                />
              </div>
            </div>

            {/* Campo Contraseña */}
            <div>
              <div className="flex items-center justify-between mb-1.5">
                <label className="block text-[11px] font-bold text-slate-700 uppercase tracking-wider">
                  Contraseña
                </label>
                <Link
                  to="/forgot-password" 
                  className="text-[11px] font-medium text-slate-400 hover:text-[#0284C7] transition-colors"
                >
                  ¿Olvidaste tu contraseña?
                </Link>
              </div>
              <div className="relative group">
                <Lock className="w-4 h-4 text-slate-400 group-focus-within:text-[#0284C7] transition-colors absolute left-3.5 top-3.5 pointer-events-none" />
                <input
                  type={showPassword ? 'text' : 'password'}
                  required
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  disabled={status === 'processing'}
                  className="w-full pl-10 pr-10 py-2.5 bg-[#EFF4FA]/70 border border-slate-200/80 rounded-xl text-xs text-slate-900 placeholder-slate-400 focus:bg-white focus:border-[#0284C7] focus:ring-4 focus:ring-[#0284C7]/15 focus:outline-none transition-all duration-200"
                />
                <button
                  type="button"
                  onClick={() => setShowPassword(!showPassword)}
                  className="absolute right-3.5 top-3 text-slate-400 hover:text-slate-600 transition-colors cursor-pointer"
                  tabIndex={-1}
                >
                  {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                </button>
              </div>
            </div>

            {/* Mantener sesión */}
            <div className="flex items-center pt-1">
              <label className="flex items-center gap-2 cursor-pointer select-none">
                <input
                  type="checkbox"
                  checked={rememberMe}
                  onChange={(e) => setRememberMe(e.target.checked)}
                  className="w-4 h-4 rounded text-[#0284C7] focus:ring-0 accent-[#0284C7] cursor-pointer"
                />
                <span className="text-[11px] text-slate-600 font-medium">
                  Mantener sesión iniciada en este dispositivo
                </span>
              </label>
            </div>

            {/* Botón Iniciar Sesión con Shimmer Effect */}
            <button
              type="submit"
              disabled={status === 'processing'}
              className="relative overflow-hidden w-full mt-2 py-3 px-4 bg-gradient-to-r from-[#084586] via-[#0284C7] to-[#0369A1] hover:brightness-110 active:scale-[0.99] text-white text-xs font-semibold rounded-xl shadow-lg shadow-[#0284C7]/30 transition-all flex items-center justify-center gap-2 cursor-pointer disabled:opacity-70 disabled:cursor-not-allowed group"
            >
              {/* Haz de luz reflejado que pasa cada 3.5 segundos */}
              <div className="absolute inset-0 w-1/3 bg-gradient-to-r from-transparent via-white/25 to-transparent shimmer-effect pointer-events-none" />

              {status === 'processing' ? (
                <>
                  <Loader2 className="w-4 h-4 animate-spin text-white" />
                  <span>Validando credenciales...</span>
                </>
              ) : (
                <>
                  <span>Iniciar sesión</span>
                  <ArrowRight className="w-3.5 h-3.5 group-hover:translate-x-1 transition-transform" />
                </>
              )}
            </button>
          </form>

          {/* Enlace de Registro de Organización */}
          <div className="mt-6 pt-5 border-t border-slate-100 text-center">
            <p className="text-xs text-slate-500 mb-2">
              ¿Tu empresa aún no cuenta con un espacio de trabajo?
            </p>
            <a
              href={centralUrl(centralDomain, '/register')}
              className="inline-flex items-center gap-1.5 text-xs font-bold text-[#0284C7] hover:text-[#084586] transition-colors group"
            >
              <span>Registrar nueva organización</span>
              <ArrowRight className="w-3.5 h-3.5 group-hover:translate-x-1 transition-transform" />
            </a>
          </div>

          {/* Soporte */}
          <p className="mt-6 text-center text-[11px] text-slate-400">
            ¿Problemas para acceder?{' '}
            <a href="#support" className="text-slate-600 hover:text-[#0284C7] transition-colors">
              Contacta a soporte
            </a>
          </p>

        </div>
      </div>

    </div>
  );
};