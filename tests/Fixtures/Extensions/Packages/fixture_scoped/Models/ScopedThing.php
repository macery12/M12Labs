<?php

namespace Everest\Extensions\Packages\fixture_scoped\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A package model bound on a server-scoped client route. Lives in the real
 * package namespace so the middleware's namespace check is exercised for what
 * it actually matches, not for a stand-in.
 */
class ScopedThing extends Model
{
    protected $table = 'ext_fixture_scoped_things';

    protected $guarded = [];
}
