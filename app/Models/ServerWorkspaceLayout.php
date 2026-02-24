<?php

namespace Everest\Models;

class ServerWorkspaceLayout extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'server_workspace_layouts';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'user_id',
        'server_uuid',
        'layout_key',
        'layout_json',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'layout_json' => 'array',
    ];
}
