import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Compass, ShoppingCart } from 'lucide-react';
import { useAuth } from '../../auth/AuthContext';
import { apiErrorMessage } from '../../../shared/api/client';
import { Alert, Button, Card, EmptyState, ErrorState, LoadingState, Modal, PageHeader } from '../../../shared/ui';
import { COURSE_CARD_CLASS } from '../../sales/api';
import { CourseCardBody, CourseCover, CourseFacts, CourseOverview, CoursePrice } from '../../sales/components/CourseCatalog';
import { enrollmentsApi, type AvailableCourse, type AvailableCourseDetail } from '../api';

// Ficha del curso: presentación y temario, sin el contenido
const CourseSheet: React.FC<{ course: AvailableCourse; onClose: () => void }> = ({ course, onClose }) => {
  const navigate = useNavigate();
  const { hasPermission } = useAuth();
  const [detail, setDetail] = useState<AvailableCourseDetail | null>(null);
  const [error, setError] = useState<string | null>(null);

  const forSale = course.price !== null && hasPermission('orders.create');
  const free = Number(course.price) === 0;

  useEffect(() => {
    let active = true;
    enrollmentsApi
      .availableCourse(course.id)
      .then((result) => active && setDetail(result))
      .catch((err) => active && setError(apiErrorMessage(err, 'No se pudo cargar el curso.')));
    return () => {
      active = false;
    };
  }, [course.id]);

  return (
    <Modal
      title={course.title}
      subtitle={course.category ?? undefined}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>Cerrar</Button>
          {forSale && (
            <Button variant="primary" icon={<ShoppingCart className="w-4 h-4" />} onClick={() => navigate(`/checkout/${course.id}`)}>
              {free ? 'Inscribirme gratis' : 'Comprar'}
            </Button>
          )}
        </>
      }
    >
      <div className="space-y-5">
        <CourseCover url={course.cover_url} className="rounded-xl" />
        <div className="flex flex-wrap items-center justify-between gap-3">
          <CourseFacts course={course} />
          <CoursePrice course={course} className="text-lg" />
        </div>
        {error && <Alert>{error}</Alert>}
        {!detail && !error && <LoadingState />}
        {detail && (
          <>
            <CourseOverview detail={detail} />
            {!forSale && <Alert tone="info">Para cursarlo, pide a tu academia que te matricule.</Alert>}
          </>
        )}
      </div>
    </Modal>
  );
};

export const AvailableCoursesPage: React.FC = () => {
  const [courses, setCourses] = useState<AvailableCourse[]>([]);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [error, setError] = useState('');
  const [attempt, setAttempt] = useState(0);
  const [open, setOpen] = useState<AvailableCourse | null>(null);

  useEffect(() => {
    let active = true;
    enrollmentsApi
      .availableCourses()
      .then((result) => {
        if (!active) return;
        setCourses(result);
        setState('ready');
      })
      .catch((err) => {
        if (!active) return;
        setError(apiErrorMessage(err, 'Verifica tu conexión e inténtalo nuevamente.'));
        setState('error');
      });
    return () => {
      active = false;
    };
  }, [attempt]);

  return (
    <div>
      <PageHeader title="Cursos disponibles" icon={Compass} subtitle="Otros cursos de tu academia en los que aún no estás matriculado." />

      {state === 'loading' && <LoadingState />}
      {state === 'error' && (
        <Card>
          <ErrorState
            message={error}
            onRetry={() => {
              setState('loading');
              setAttempt((n) => n + 1);
            }}
          />
        </Card>
      )}
      {state === 'ready' && courses.length === 0 && (
        <Card>
          <EmptyState icon={Compass} title="No hay otros cursos por ahora" text="Ya estás en todos los cursos publicados de tu academia, o aún no se publica ninguno." />
        </Card>
      )}
      {state === 'ready' && courses.length > 0 && (
        <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-4 sm:gap-5">
          {courses.map((course) => (
            <button key={course.id} type="button" onClick={() => setOpen(course)} className={COURSE_CARD_CLASS}>
              <CourseCardBody course={course} action="Ver de qué trata" />
            </button>
          ))}
        </div>
      )}

      {open && <CourseSheet course={open} onClose={() => setOpen(null)} />}
    </div>
  );
};
