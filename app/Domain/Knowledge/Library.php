<?php

namespace App\Domain\Knowledge;

final class Library
{
    private ?array $content = null;

    public function content(): array
    {
        return $this->content ??= json_decode(file_get_contents(resource_path('knowledge/library.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function articles(): array
    {
        return $this->content()['articles'];
    }

    public function find(string $id): ?Article
    {
        foreach ($this->articles() as $article) {
            if ($article['id'] === $id) {
                return new Article($article);
            }
        }

        return null;
    }
}
