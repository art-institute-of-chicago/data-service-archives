<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AgentArchiveLink extends Model
{
    use HasFactory;

    protected $table = 'agent_archive_link';
    protected $guarded = [];
    protected $casts = [
        'agent_citi_id' => 'integer',
        'archive_id' => 'integer',
    ];

    public function archive()
    {
        return $this->belongsTo(Archive::class);
    }
}
