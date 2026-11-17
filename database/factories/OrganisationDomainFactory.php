<?php

namespace Database\Factories;

use App\Modules\Domains\Models\OrganisationDomain;
use App\Modules\Organisations\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganisationDomain>
 */
class OrganisationDomainFactory extends Factory
{
    protected $model = OrganisationDomain::class;

    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'domain_name' => fake()->unique()->domainName(),
        ];
    }
}
