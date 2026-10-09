<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Prueba gratuita
    |--------------------------------------------------------------------------
    |
    | Toda organización nueva empieza en prueba: estos días y con este tope de
    | alumnos (personas con al menos una matrícula activa). Después debe pasar
    | a un plan. El administrador de plataforma puede extender la prueba de
    | una organización desde su ficha.
    |
    */

    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 14),

    'trial_max_students' => (int) env('BILLING_TRIAL_MAX_STUDENTS', 10),

];
