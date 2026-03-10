<?php

namespace Everest\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomDomainDnsLog extends Model
{
    public const RESOURCE_NAME = 'custom_domain_dns_log';

    protected $table = 'custom_domain_dns_logs';

    protected $guarded = ['id', self::CREATED_AT, self::UPDATED_AT];

    protected $casts = [
        'server_id' => 'integer',
        'server_custom_domain_id' => 'integer',
        'payload' => 'array',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function serverCustomDomain(): BelongsTo
    {
        return $this->belongsTo(ServerCustomDomain::class);
    }
}
