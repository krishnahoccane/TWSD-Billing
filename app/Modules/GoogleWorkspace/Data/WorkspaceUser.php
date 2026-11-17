<?php

namespace App\Modules\GoogleWorkspace\Data;

use Carbon\CarbonImmutable;

final readonly class WorkspaceUser
{
    public function __construct(
        public string $id,
        public string $primaryEmail,
        public string $givenName,
        public string $familyName,
        public bool $suspended = false,
        public string $orgUnitPath = '/',
        public ?CarbonImmutable $createdAt = null,
        public ?string $displayName = null,
        public ?string $recoveryEmail = null,
        public ?string $recoveryPhone = null,
    ) {}

    public function domain(): string
    {
        return strtolower(substr(strrchr($this->primaryEmail, '@') ?: '@', 1));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'primaryEmail' => $this->primaryEmail,
            'givenName' => $this->givenName,
            'familyName' => $this->familyName,
            'suspended' => $this->suspended,
            'orgUnitPath' => $this->orgUnitPath,
            'createdAt' => $this->createdAt?->toIso8601String(),
            'displayName' => $this->displayName,
            'recoveryEmail' => $this->recoveryEmail,
            'recoveryPhone' => $this->recoveryPhone,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            primaryEmail: strtolower((string) $data['primaryEmail']),
            givenName: (string) ($data['givenName'] ?? ''),
            familyName: (string) ($data['familyName'] ?? ''),
            suspended: (bool) ($data['suspended'] ?? false),
            orgUnitPath: (string) ($data['orgUnitPath'] ?? '/'),
            createdAt: isset($data['createdAt']) ? CarbonImmutable::parse($data['createdAt']) : null,
            displayName: $data['displayName'] ?? null,
            recoveryEmail: $data['recoveryEmail'] ?? null,
            recoveryPhone: $data['recoveryPhone'] ?? null,
        );
    }
}
