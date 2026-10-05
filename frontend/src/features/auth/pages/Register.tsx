import React, { useState } from 'react';
import { api, apiErrorMessage } from '../../../shared/api/client';
import { useTenant } from '../../../shared/tenancy/TenantContext';
import { tenantUrl } from '../../../shared/tenancy/hosts';
import { 
  Lock, 
  Mail, 
  AlertCircle, 
  Loader2, 
  Eye, 
  EyeOff, 
  Building2, 
  Globe, 
  User, 
  ShieldCheck, 
  Users 
} from 'lucide-react';

function slugify(value: string): string {
  return value
    .toLowerCase()
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 40);
}

export const Register: React.FC = () => {
  const { centralDomain } = useTenant();

  const [formData, setFormData] = useState({
    organization_name: '',
    subdomain: '',
    first_name: '',
    last_name: '',
    email: '',
    password: '',
    password_confirmation: '',
  });

  const [showPassword, setShowPassword] = useState(false);
  const [status, setStatus] = useState<'idle' | 'processing' | 'error'>('idle');
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  // El subdominio se sugiere a partir del nombre hasta que el usuario lo edite
  const [subdomainEdited, setSubdomainEdited] = useState(false);

  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const { name, value } = e.target;

    if (name === 'subdomain') {
      setSubdomainEdited(true);
      setFormData((prev) => ({ ...prev, subdomain: value.toLowerCase().replace(/[^a-z0-9-]/g, '') }));
      return;
    }

    setFormData((prev) => ({
      ...prev,
      [name]: value,
      ...(name === 'organization_name' && !subdomainEdited ? { subdomain: slugify(value) } : {}),
    }));
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (formData.password !== formData.password_confirmation) {
      setStatus('error');
      setErrorMessage('Las contraseñas no coinciden.');
      return;
    }

    setStatus('processing');
    setErrorMessage(null);

    try {
      const response = await api.post('/tenants', formData);
      // Cada organización inicia sesión en su propio subdominio
      window.location.assign(tenantUrl(response.data.data.slug, centralDomain, '/login'));
    } catch (err) {
      setStatus('error');
      setErrorMessage(apiErrorMessage(err, 'Ocurrió un error al registrar la organización.'));
    }
  };

  return (
    <div className="min-h-screen w-full flex flex-col lg:flex-row font-sans bg-[#F4F7FB] selection:bg-[#0284C7] selection:text-white">
      
      {/* PANEL IZQUIERDO: Branding */}
      <div className="relative overflow-hidden lg:w-[48%] bg-gradient-to-b from-[#06396E] via-[#052E5A] to-[#042142] p-8 sm:p-12 lg:p-16 flex flex-col justify-between min-h-[420px] lg:min-h-screen text-white">
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
        <div className="absolute top-1/4 left-1/4 w-96 h-96 bg-[#0284C7]/20 rounded-full blur-3xl pointer-events-none" />

        <div className="relative z-10 flex items-center gap-2.5">
          <div className="w-8 h-8 rounded-lg bg-white/10 backdrop-blur-md border border-white/20 flex items-center justify-center">
            <svg className="w-5 h-5 text-white" viewBox="0 0 48 48" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
              <path d="M19 6h10M21 6v10l-11 18a4 4 0 003.4 6h21.2a4 4 0 003.4-6L27 16V6" />
              <path d="M14 28c3 1 7-1 10 0s7 1 10 0" fill="#38BDF8" fillOpacity="0.4" stroke="none" />
              <circle cx="24" cy="22" r="1.5" fill="currentColor" />
            </svg>
          </div>
          <span className="font-extrabold text-lg tracking-tight text-white">
            Intelecta<span className="text-[#38BDF8]">Lab_</span>
          </span>
        </div>

        <div className="relative z-10 my-auto py-8">
          <h1 className="text-3xl sm:text-4xl lg:text-5xl font-black tracking-tight leading-[1.15] max-w-xl text-white mb-4">
            Comienza tu espacio <br />
            multi-tenant hoy.
          </h1>
          <p className="text-blue-100/75 text-sm sm:text-base max-w-lg leading-relaxed mb-8">
            Crea tu organización con entorno aislado en base de datos, roles preconfigurados y acceso inmediato.
          </p>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5 max-w-lg">
            <div className="bg-white/[0.06] backdrop-blur-md border border-white/10 rounded-2xl p-4">
              <ShieldCheck className="w-5 h-5 text-[#38BDF8] mb-2" />
              <p className="text-xs font-semibold text-white/90">Aislamiento por esquema</p>
              <p className="text-[11px] text-blue-200/60 mt-0.5">Seguridad nativa con PostgreSQL 15</p>
            </div>
            <div className="bg-white/[0.06] backdrop-blur-md border border-white/10 rounded-2xl p-4">
              <Users className="w-5 h-5 text-[#38BDF8] mb-2" />
              <p className="text-xs font-semibold text-white/90">Control RBAC completo</p>
              <p className="text-[11px] text-blue-200/60 mt-0.5">Permisos granulares configurables</p>
            </div>
          </div>
        </div>

        <div className="relative z-10 text-[11px] text-blue-200/60 font-medium">
          &copy; {new Date().getFullYear()} IntelectaLab. Todos los derechos reservados.
        </div>
      </div>

      {/* PANEL DERECHO: Formulario de Registro */}
      <div className="lg:w-[52%] flex flex-col justify-center items-center px-6 sm:px-10 lg:px-16 py-12">
        <div className="w-full max-w-[460px] bg-white rounded-3xl shadow-[0_10px_35px_-5px_rgba(6,47,94,0.08)] border border-slate-100 p-8 sm:p-10">
          
          <div className="mb-6">
            <h2 className="text-2xl font-bold text-slate-900 tracking-tight">
              Crear nueva organización
            </h2>
            <p className="text-xs text-slate-500 mt-1">
              Si tu organización ya existe, ingresa desde su propia dirección.
            </p>
          </div>

          {status === 'error' && errorMessage && (
            <div className="mb-5 p-3.5 bg-red-50 border border-red-100 rounded-xl flex items-start gap-2.5 text-xs text-red-600">
              <AlertCircle className="w-4 h-4 shrink-0 mt-0.5" />
              <span>{errorMessage}</span>
            </div>
          )}

          <form onSubmit={handleSubmit} className="space-y-3.5">
            <div>
              <label className="block text-[11px] font-semibold text-slate-700 mb-1">
                Nombre de la Empresa u Organización
              </label>
              <div className="relative">
                <Building2 className="w-4 h-4 text-slate-400 absolute left-3.5 top-3 pointer-events-none" />
                <input
                  type="text"
                  name="organization_name"
                  required
                  placeholder="Ej. Academia Central S.A.C."
                  value={formData.organization_name}
                  onChange={handleChange}
                  className="w-full pl-10 pr-3.5 py-2 bg-[#EFF4FA] border border-transparent rounded-xl text-xs text-slate-900 focus:bg-white focus:border-[#0284C7] focus:ring-2 focus:ring-[#0284C7]/20 focus:outline-none transition-all"
                />
              </div>
            </div>

            <div>
              <label className="block text-[11px] font-semibold text-slate-700 mb-1">
                Dirección de tu espacio de trabajo
              </label>
              <div className="relative flex items-center">
                <Globe className="w-4 h-4 text-slate-400 absolute left-3.5 top-3 pointer-events-none" />
                <input
                  type="text"
                  name="subdomain"
                  required
                  minLength={3}
                  maxLength={40}
                  placeholder="academia-central"
                  value={formData.subdomain}
                  onChange={handleChange}
                  className="w-full pl-10 pr-3.5 py-2 bg-[#EFF4FA] border border-transparent rounded-l-xl text-xs text-slate-900 focus:bg-white focus:border-[#0284C7] focus:ring-2 focus:ring-[#0284C7]/20 focus:outline-none transition-all"
                />
                <span className="shrink-0 px-3 py-2 bg-slate-100 border border-slate-200 border-l-0 rounded-r-xl text-xs text-slate-500">
                  .{centralDomain}
                </span>
              </div>
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="block text-[11px] font-semibold text-slate-700 mb-1">
                  Nombres
                </label>
                <div className="relative">
                  <User className="w-4 h-4 text-slate-400 absolute left-3.5 top-3 pointer-events-none" />
                  <input
                    type="text"
                    name="first_name"
                    required
                    placeholder="Carlos"
                    value={formData.first_name}
                    onChange={handleChange}
                    className="w-full pl-10 pr-3.5 py-2 bg-[#EFF4FA] border border-transparent rounded-xl text-xs text-slate-900 focus:bg-white focus:border-[#0284C7] focus:ring-2 focus:ring-[#0284C7]/20 focus:outline-none transition-all"
                  />
                </div>
              </div>
              <div>
                <label className="block text-[11px] font-semibold text-slate-700 mb-1">
                  Apellidos
                </label>
                <input
                  type="text"
                  name="last_name"
                  required
                  placeholder="Mendoza"
                  value={formData.last_name}
                  onChange={handleChange}
                  className="w-full px-3.5 py-2 bg-[#EFF4FA] border border-transparent rounded-xl text-xs text-slate-900 focus:bg-white focus:border-[#0284C7] focus:ring-2 focus:ring-[#0284C7]/20 focus:outline-none transition-all"
                />
              </div>
            </div>

            <div>
              <label className="block text-[11px] font-semibold text-slate-700 mb-1">
                Correo corporativo
              </label>
              <div className="relative">
                <Mail className="w-4 h-4 text-slate-400 absolute left-3.5 top-3 pointer-events-none" />
                <input
                  type="email"
                  name="email"
                  required
                  placeholder="administrador@tuempresa.com"
                  value={formData.email}
                  onChange={handleChange}
                  className="w-full pl-10 pr-3.5 py-2 bg-[#EFF4FA] border border-transparent rounded-xl text-xs text-slate-900 focus:bg-white focus:border-[#0284C7] focus:ring-2 focus:ring-[#0284C7]/20 focus:outline-none transition-all"
                />
              </div>
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="block text-[11px] font-semibold text-slate-700 mb-1">
                  Contraseña
                </label>
                <div className="relative">
                  <Lock className="w-4 h-4 text-slate-400 absolute left-3.5 top-3 pointer-events-none" />
                  <input
                    type={showPassword ? 'text' : 'password'}
                    name="password"
                    required
                    placeholder="Min. 8 caracteres"
                    value={formData.password}
                    onChange={handleChange}
                    className="w-full pl-10 pr-8 py-2 bg-[#EFF4FA] border border-transparent rounded-xl text-xs text-slate-900 focus:bg-white focus:border-[#0284C7] focus:ring-2 focus:ring-[#0284C7]/20 focus:outline-none transition-all"
                  />
                  <button
                    type="button"
                    onClick={() => setShowPassword(!showPassword)}
                    className="absolute right-2.5 top-2.5 text-slate-400 hover:text-slate-600"
                    tabIndex={-1}
                  >
                    {showPassword ? <EyeOff className="w-3.5 h-3.5" /> : <Eye className="w-3.5 h-3.5" />}
                  </button>
                </div>
              </div>

              <div>
                <label className="block text-[11px] font-semibold text-slate-700 mb-1">
                  Confirmar
                </label>
                <input
                  type={showPassword ? 'text' : 'password'}
                  name="password_confirmation"
                  required
                  placeholder="Repetir contraseña"
                  value={formData.password_confirmation}
                  onChange={handleChange}
                  className="w-full px-3.5 py-2 bg-[#EFF4FA] border border-transparent rounded-xl text-xs text-slate-900 focus:bg-white focus:border-[#0284C7] focus:ring-2 focus:ring-[#0284C7]/20 focus:outline-none transition-all"
                />
              </div>
            </div>

            <button
              type="submit"
              disabled={status === 'processing'}
              className="w-full !mt-5 py-3 px-4 bg-[#0284C7] hover:bg-[#0369A1] active:bg-[#075985] text-white text-xs font-semibold rounded-xl shadow-md shadow-[#0284C7]/25 transition-all flex items-center justify-center gap-2 cursor-pointer disabled:opacity-70"
            >
              {status === 'processing' ? (
                <>
                  <Loader2 className="w-4 h-4 animate-spin text-white" />
                  <span>Configurando organización...</span>
                </>
              ) : (
                'Registrar Organización'
              )}
            </button>
          </form>
        </div>
      </div>

    </div>
  );
};