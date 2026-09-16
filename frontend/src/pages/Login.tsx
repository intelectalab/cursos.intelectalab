import React, { useState } from 'react';
import { useNavigate, Navigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { api } from '../api/client';
import { 
  Lock, 
  Mail, 
  AlertCircle, 
  Loader2, 
  Eye, 
  EyeOff, 
  Users, 
  ShieldCheck, 
  Building2 
} from 'lucide-react';

export const Login: React.FC = () => {
  const navigate = useNavigate();
  const { login, isAuthenticated } = useAuth();

  const [email, setEmail] = useState('admin@plataforma.com');
  const [password, setPassword] = useState('Admin123*#');
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
    } catch (err: any) {
      setStatus('error');
      setErrorMessage(
        err.response?.data?.message || 'Credenciales no válidas o error en el servidor.'
      );
    }
  };

  return (
    <div className="min-h-screen w-full flex flex-col lg:flex-row font-sans bg-[#F4F7FB] selection:bg-[#0284C7] selection:text-white">
      
      {/* PANEL IZQUIERDO: Branding y Capacidades del Sistema */}
      <div className="relative overflow-hidden lg:w-[55%] bg-gradient-to-b from-[#06396E] via-[#052E5A] to-[#042142] p-8 sm:p-12 lg:p-16 flex flex-col justify-between min-h-[460px] lg:min-h-screen text-white">
        
        {/* Trama de cuadrícula arquitectónica sutil */}
        <div 
          className="absolute inset-0 pointer-events-none opacity-15"
          style={{
            backgroundImage: `
              linear-gradient(to right, rgba(255, 255, 255, 0.1) 1px, transparent 1px),
              linear-gradient(to bottom, rgba(255, 255, 255, 0.1) 1px, transparent 1px)
            `,
            backgroundSize: '48px 48px',
          }}
        />

        {/* Gradiente radial de profundidad */}
        <div className="absolute top-1/4 left-1/4 w-96 h-96 bg-[#0284C7]/20 rounded-full blur-3xl pointer-events-none" />

        {/* 1. Logotipo IntelectaLab */}
        <div className="relative z-10 flex items-center gap-2.5">
          <div className="w-8 h-8 rounded-lg bg-white/10 backdrop-blur-md border border-white/20 flex items-center justify-center">
            <svg 
              className="w-5 h-5 text-white" 
              viewBox="0 0 48 48" 
              fill="none" 
              stroke="currentColor" 
              strokeWidth="2.5" 
              strokeLinecap="round" 
              strokeLinejoin="round"
            >
              <path d="M19 6h10M21 6v10l-11 18a4 4 0 003.4 6h21.2a4 4 0 003.4-6L27 16V6" />
              <path d="M14 28c3 1 7-1 10 0s7 1 10 0" fill="#38BDF8" fillOpacity="0.4" stroke="none" />
              <circle cx="24" cy="22" r="1.5" fill="currentColor" />
            </svg>
          </div>
          <span className="font-extrabold text-lg tracking-tight text-white">
            Intelecta<span className="text-[#38BDF8]">Lab_</span>
          </span>
        </div>

        {/* 2. Titular y Subtítulo Central */}
        <div className="relative z-10 my-auto py-8">
          <h1 className="text-3xl sm:text-4xl lg:text-5xl font-black tracking-tight leading-[1.15] max-w-xl text-white mb-4">
            Toda tu operación, <br />
            en un solo lugar.
          </h1>
          <p className="text-blue-100/75 text-sm sm:text-base max-w-lg leading-relaxed mb-10">
            Clientes, permisos y módulos administrativos gestionados desde un mismo panel centralizado, con el control de aislamiento multi-tenant que tu organización requiere.
          </p>

          {/* 3 Tarjetas Traslúcidas de Características */}
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-3.5 max-w-2xl">
            <div className="bg-white/[0.06] backdrop-blur-md border border-white/10 rounded-2xl p-4.5 transition-all hover:bg-white/[0.09]">
              <div className="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-[#38BDF8] mb-3">
                <Users className="w-4 h-4" />
              </div>
              <p className="text-xs font-medium text-white/90 leading-snug">
                Gestión de clientes centralizada
              </p>
            </div>

            <div className="bg-white/[0.06] backdrop-blur-md border border-white/10 rounded-2xl p-4.5 transition-all hover:bg-white/[0.09]">
              <div className="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-[#38BDF8] mb-3">
                <ShieldCheck className="w-4 h-4" />
              </div>
              <p className="text-xs font-medium text-white/90 leading-snug">
                Permisos y roles granulares RBAC
              </p>
            </div>

            <div className="bg-white/[0.06] backdrop-blur-md border border-white/10 rounded-2xl p-4.5 transition-all hover:bg-white/[0.09]">
              <div className="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-[#38BDF8] mb-3">
                <Building2 className="w-4 h-4" />
              </div>
              <p className="text-xs font-medium text-white/90 leading-snug">
                Multi-tenant, pensado para escalar
              </p>
            </div>
          </div>
        </div>

        {/* Footer Legal */}
        <div className="relative z-10 text-[11px] text-blue-200/60 font-medium">
          &copy; {new Date().getFullYear()} IntelectaLab. Todos los derechos reservados.
        </div>
      </div>

      {/* PANEL DERECHO: Tarjeta Flotante de Acceso */}
      <div className="lg:w-[45%] flex flex-col justify-center items-center px-6 sm:px-10 lg:px-16 py-12">
        <div className="w-full max-w-[420px] bg-white rounded-3xl shadow-[0_10px_35px_-5px_rgba(6,47,94,0.08)] border border-slate-100 p-8 sm:p-10">
          
          <div className="mb-7">
            <h2 className="text-2xl font-bold text-slate-900 tracking-tight">
              Bienvenido de nuevo
            </h2>
            <p className="text-xs text-slate-500 mt-1">
              Ingresa tus credenciales para continuar.
            </p>
          </div>

          {status === 'error' && errorMessage && (
            <div className="mb-5 p-3.5 bg-red-50 border border-red-100 rounded-xl flex items-start gap-2.5 text-xs text-red-600 animate-fadeIn">
              <AlertCircle className="w-4 h-4 shrink-0 mt-0.5" />
              <span>{errorMessage}</span>
            </div>
          )}

          <form onSubmit={handleSubmit} className="space-y-4">
            
            {/* Campo Correo */}
            <div>
              <label className="block text-[11px] font-semibold text-slate-700 mb-1.5">
                Correo electrónico
              </label>
              <div className="relative">
                <Mail className="w-4 h-4 text-slate-400 absolute left-3.5 top-3.5 pointer-events-none" />
                <input
                  type="email"
                  required
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  disabled={status === 'processing'}
                  className="w-full pl-10 pr-3.5 py-2.5 bg-[#EFF4FA] border border-transparent rounded-xl text-xs text-slate-900 placeholder-slate-400 focus:bg-white focus:border-[#0284C7] focus:ring-2 focus:ring-[#0284C7]/20 focus:outline-none transition-all"
                />
              </div>
            </div>

            {/* Campo Contraseña con enlace inline */}
            <div>
              <div className="flex items-center justify-between mb-1.5">
                <label className="block text-[11px] font-semibold text-slate-700">
                  Contraseña
                </label>
                <a 
                  href="#reset" 
                  className="text-[11px] font-medium text-slate-400 hover:text-[#0284C7] transition-colors"
                >
                  ¿Olvidaste tu contraseña?
                </a>
              </div>
              <div className="relative">
                <Lock className="w-4 h-4 text-slate-400 absolute left-3.5 top-3.5 pointer-events-none" />
                <input
                  type={showPassword ? 'text' : 'password'}
                  required
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  disabled={status === 'processing'}
                  className="w-full pl-10 pr-10 py-2.5 bg-[#EFF4FA] border border-transparent rounded-xl text-xs text-slate-900 placeholder-slate-400 focus:bg-white focus:border-[#0284C7] focus:ring-2 focus:ring-[#0284C7]/20 focus:outline-none transition-all"
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

            {/* Checkbox Mantener Sesión */}
            <div className="flex items-center pt-1">
              <label className="flex items-center gap-2 cursor-pointer select-none">
                <input
                  type="checkbox"
                  checked={rememberMe}
                  onChange={(e) => setRememberMe(e.target.checked)}
                  className="w-4 h-4 rounded text-[#0284C7] focus:ring-0 focus:ring-offset-0 accent-[#0284C7] cursor-pointer"
                />
                <span className="text-[11px] text-slate-600 font-medium">
                  Mantener sesión iniciada
                </span>
              </label>
            </div>

            {/* Botón Iniciar Sesión */}
            <button
              type="submit"
              disabled={status === 'processing'}
              className="w-full mt-2 py-3 px-4 bg-[#0284C7] hover:bg-[#0369A1] active:bg-[#075985] text-white text-xs font-semibold rounded-xl shadow-md shadow-[#0284C7]/25 transition-all flex items-center justify-center gap-2 cursor-pointer disabled:opacity-70 disabled:cursor-not-allowed"
            >
              {status === 'processing' ? (
                <>
                  <Loader2 className="w-4 h-4 animate-spin text-white" />
                  <span>Validando acceso...</span>
                </>
              ) : (
                'Iniciar sesión'
              )}
            </button>
          </form>

          {/* Soporte */}
          <p className="mt-8 text-center text-xs text-slate-500">
            ¿Problemas para acceder?{' '}
            <a href="#support" className="font-semibold text-[#0284C7] hover:underline underline-offset-2">
              Contacta a soporte
            </a>
          </p>

        </div>
      </div>

    </div>
  );
};