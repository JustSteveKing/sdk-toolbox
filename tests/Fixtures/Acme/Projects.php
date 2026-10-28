<?php

declare(strict_types=1);

namespace JustSteveKing\SdkToolbox\Tests\Fixtures\Acme;

final readonly class Projects
{
    public function __construct(
        private Acme $acme,
    ) {}

    public function get(string $id): Project
    {
        return $this->acme->send(new GetProject($id));
    }

    public function create(string $name): Project
    {
        return $this->acme->send(new CreateProject($name));
    }
}
