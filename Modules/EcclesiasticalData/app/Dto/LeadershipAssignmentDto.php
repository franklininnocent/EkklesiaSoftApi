<?php

namespace Modules\EcclesiasticalData\Dto;

readonly class LeadershipAssignmentDto
{
    /**
     * @param  array<string, mixed>  $person
     * @param  array<string, mixed>  $assignment
     * @param  array<string, mixed>  $scope
     */
    public function __construct(
        public string $officeCode,
        public array $scope,
        public array $person,
        public array $assignment,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'office_code' => $this->officeCode,
            'scope' => $this->scope,
            'person' => $this->person,
            'assignment' => $this->assignment,
        ];
    }
}
