<?php

namespace MrNewport\LaravelFlow\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use MrNewport\LaravelFlow\Traits\Flowable;

class FlowSubject extends Model
{
    use Flowable;

    protected $table = 'flow_test_subjects';

    protected $guarded = [];

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;
}
