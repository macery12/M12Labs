<?php

namespace Everest\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerCustomDomain extends Model
{
    public const RESOURCE_NAME = 'server_custom_domain';

    protected $table = 'server_custom_domains';

    protected $guarded = ['id', self::CREATED_AT, self::UPDATED_AT];

    protected $casts = [
        'server_id' => 'integer',
        'allocation_id' => 'integer',
        'custom_domain_id' => 'integer',
        'port' => 'integer',
        'dns_records' => 'array',
        'last_synced_at' => 'datetime',
    ];

    public static array $validationRules = [
        'server_id' => 'required|integer|exists:servers,id',
        'allocation_id' => 'nullable|integer|exists:allocations,id',
        'custom_domain_id' => 'required|integer|exists:custom_domains,id',
        'subdomain' => ['required', 'string', 'max:191', 'regex:/^(\*|[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)$/i'],
        'full_domain' => ['required', 'string', 'max:191', 'regex:/^(?!-)[A-Za-z0-9*.-]+$/'],
        'port' => 'required|integer|min:1|max:65535',
        'protocol' => 'required|in:tcp,udp,both',
        'record_type' => 'nullable|in:srv,cname',
        'service_tag' => ['nullable', 'string', 'max:100', 'regex:/^(_?[a-z0-9][a-z0-9-]*|_[a-z0-9][a-z0-9-]*\._(?:tcp|udp)?|_[a-z0-9][a-z0-9-]*\._)$/i'],
    ];

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(Allocation::class);
    }

    public function customDomain(): BelongsTo
    {
        return $this->belongsTo(CustomDomain::class);
    }
}
