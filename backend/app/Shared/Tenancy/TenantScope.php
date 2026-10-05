<?php

namespace App\Shared\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isSystem()) {
            return;
        }

        if ($context->hasTenant()) {
            $builder->where($model->qualifyColumn('tenant_id'), $context->tenantId());

            return;
        }

        // Sin contexto resuelto no se expone ningún dato de tenant.
        $builder->whereRaw('1 = 0');
    }
}
