<?php

namespace Database\Factories;

use App\Modules\Organisations\Enums\OrganisationStatus;
use App\Modules\Organisations\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organisation>
 */
class OrganisationFactory extends Factory
{
    protected $model = Organisation::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'legal_name' => $name.' Private Limited',
            'code' => Str::upper(Str::random(8)),
            'primary_contact_name' => fake()->name(),
            'primary_contact_email' => fake()->unique()->safeEmail(),
            'billing_email' => fake()->unique()->safeEmail(),
            'country' => 'IN',
            'state' => 'Telangana',
            'city' => 'Hyderabad',
        ];
    }

    public function status(OrganisationStatus $status): static
    {
        return $this->afterMaking(fn (Organisation $organisation) => $organisation->status = $status);
    }
}
