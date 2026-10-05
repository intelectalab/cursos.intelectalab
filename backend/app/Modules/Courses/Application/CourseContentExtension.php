<?php

namespace App\Modules\Courses\Application;

use App\Modules\Courses\Domain\Models\Course;

/**
 * Contenido que otros módulos cuelgan de las secciones de un curso
 * (actividades, y más adelante SCORM). El módulo Cursos no conoce esos
 * módulos: los consulta al publicar a través de esta interfaz. Las
 * implementaciones se registran con la etiqueta TAG en AppServiceProvider.
 */
interface CourseContentExtension
{
    public const TAG = 'courses.content_extensions';

    /**
     * Clave bajo la que el contenido se guarda en cada sección de la versión.
     */
    public function key(): string;

    /**
     * Contenido del borrador, completo y autocontenido, por sección.
     *
     * @return array<string, array<int, array<string, mixed>>> id de sección => elementos
     */
    public function snapshot(Course $course): array;

    /**
     * Motivos por los que este contenido impide publicar (vacío si ninguno).
     *
     * @return list<string>
     */
    public function publishProblems(Course $course): array;
}
