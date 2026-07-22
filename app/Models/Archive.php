<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Archive extends Model
{
    use HasFactory;

    protected $guarded = [];
    protected $casts = [
        'lccn' => 'array',
        'subjects' => 'array',
        'metadata' => 'array',
        'collection_type' => 'string',
        'record_type' => 'string',
        'has_media' => 'boolean',
    ];

    public function agentLinks()
    {
        return $this->hasMany(AgentArchiveLink::class);
    }
}
