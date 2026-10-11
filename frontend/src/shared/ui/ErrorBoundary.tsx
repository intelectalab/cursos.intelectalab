import React from 'react';
import { TriangleAlert } from 'lucide-react';

interface Props {
  children: React.ReactNode;
  // Cambia al cambiar de pantalla: así un fallo en una no deja rotas las demás
  resetKey?: string;
}

interface State {
  error: Error | null;
  key?: string;
}

// Si una pantalla falla al dibujarse, se muestra este aviso en su lugar en vez de dejar la página en blanco.
// El menú sigue funcionando: la persona puede ir a otra pantalla o recargar.
export class ErrorBoundary extends React.Component<Props, State> {
  state: State = { error: null, key: this.props.resetKey };

  static getDerivedStateFromError(error: Error): Partial<State> {
    return { error };
  }

  static getDerivedStateFromProps(props: Props, state: State): Partial<State> | null {
    return props.resetKey !== state.key ? { error: null, key: props.resetKey } : null;
  }

  componentDidCatch(error: Error, info: React.ErrorInfo) {
    console.error('Una pantalla falló al dibujarse', error, info.componentStack);
  }

  render() {
    if (!this.state.error) return this.props.children;

    return (
      <div role="alert" className="max-w-xl mx-auto my-10 p-6 sm:p-8 bg-white border border-slate-200/80 rounded-2xl shadow-card text-center">
        <span className="inline-flex w-12 h-12 items-center justify-center rounded-2xl bg-rose-50 text-rose-600 mb-4">
          <TriangleAlert className="w-6 h-6" />
        </span>
        <h1 className="text-lg font-bold text-slate-900">Esta pantalla no se pudo mostrar</h1>
        <p className="mt-1.5 text-[13px] leading-relaxed text-slate-600">
          Ocurrió un error al armarla. Tus datos no se perdieron. Vuelve a cargar la página; si se repite, avisa a soporte e indica qué pantalla era y este detalle:
        </p>
        <p className="mt-3 px-3 py-2 rounded-lg bg-slate-50 border border-slate-200 text-xs font-mono text-slate-700 break-words">{this.state.error.message}</p>
        <button
          type="button"
          onClick={() => window.location.reload()}
          className="mt-5 inline-flex items-center h-9 px-4 rounded-lg bg-brand-700 text-white text-[13px] font-semibold hover:bg-brand-800 cursor-pointer"
        >
          Volver a cargar
        </button>
      </div>
    );
  }
}
