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
        'metadata' => 'array',
    ];

    public function agentLinks()
    {
        return $this->hasMany(AgentArchiveLink::class);
    }
}
