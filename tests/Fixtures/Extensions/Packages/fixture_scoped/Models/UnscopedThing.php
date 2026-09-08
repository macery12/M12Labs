<?php

namespace Everest\Extensions\Packages\fixture_scoped\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A package model with no server_id. Binding one on a server-scoped route is a
 * packaging mistake the middleware must refuse rather than wave through.
 */
class UnscopedThing extends Model
{
    protected $table = 'ext_fixture_scoped_unscoped';

    protected $guarded = [];
}
