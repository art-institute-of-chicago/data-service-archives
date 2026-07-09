<?php

namespace Database\Factories;

use App\Models\AgentArchiveLink;
use Illuminate\Database\Eloquent\Factories\Factory;

class AgentArchiveLinkFactory extends Factory
{
    protected $model = AgentArchiveLink::class;

    public function definition(): array
    {
        return [
            'archive_id' => \App\Models\Archive::factory(),
            'agent_citi_id' => fake()->randomNumber(5),
            'match_type' => 'lccn',
            'match_confidence' => 'positive',
        ];
    }
}
