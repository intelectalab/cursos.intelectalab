<?php

namespace App\Modules\Courses\Domain\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

class CourseTeacher extends Pivot
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'course_teachers';

    public $incrementing = false;
}
