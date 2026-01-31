<?php

namespace Everest\Models;

use Illuminate\Database\Eloquent\Model;

class CurseForgeRequestLog extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'curseforge_request_logs';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'requested_at',
        'endpoint',
        'status_code',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'requested_at' => 'datetime',
        'status_code' => 'integer',
    ];

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;
}
