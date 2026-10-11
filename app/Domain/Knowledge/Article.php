<?php

namespace App\Domain\Knowledge;

/** Global reference material: never contains club records or private uploads. */
final readonly class Article
{
    public function __construct(public array $data) {}
}
