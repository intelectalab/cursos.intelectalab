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

    /*
    |--------------------------------------------------------------------------
    | Vencimiento
    |--------------------------------------------------------------------------
    |
    | Al vencer la prueba o el plan, la organización sigue operando estos días
    | con un aviso. Pasados, queda en solo lectura hasta que pague: se puede
    | consultar todo, sus alumnos siguen estudiando, y nada más se modifica.
    |
    */

    'grace_days' => (int) env('BILLING_GRACE_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Órdenes de plan
    |--------------------------------------------------------------------------
    |
    | Días que una orden de plan espera su pago. Pasado el plazo vence sola,
    | salvo que la organización ya haya informado un pago: esa espera a que
    | la plataforma la confirme o la cancele.
    |
    */

    'order_expiration_days' => (int) env('BILLING_ORDER_EXPIRATION_DAYS', 7),

];
