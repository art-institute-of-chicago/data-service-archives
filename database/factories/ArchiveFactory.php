<?php

namespace Database\Factories;

use App\Models\Archive;
use Illuminate\Database\Eloquent\Factories\Factory;

class ArchiveFactory extends Factory
{
    protected $model = Archive::class;

    public function definition(): array
    {
        return [
            'title' => fake()->sentence(),
            'lccn' => [fake()->numerify('########')],
            'mms_id' => fake()->numerify('99##############'),
            'contentdm_collection' => 'aic-ex-cat',
            'contentdm_id' => (string) fake()->randomNumber(5),
            'contentdm_url' => fake()->url(),
            'web_url' => fake()->url(),
            'match_type' => 'lccn',
            'match_confidence' => 'positive',
            'metadata' => ['source' => 'test'],
        ];
    }
}
