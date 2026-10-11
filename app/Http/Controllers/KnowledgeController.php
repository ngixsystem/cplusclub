<?php

namespace App\Http\Controllers;

use App\Domain\Knowledge\{Article, Library};
use Illuminate\Support\Facades\Gate;
use Inertia\{Inertia, Response};

final class KnowledgeController extends Controller
{
    public function index(Library $library): Response
    {
        Gate::authorize('viewAny', Article::class);
        // Shared technical references have no club ownership. Never query tenant data here.
        $articles = array_values(array_filter($library->articles(), fn ($row) => Gate::allows('view', new Article($row))));

        return Inertia::render('Knowledge', [
            'articles' => array_map(fn ($row) => array_diff_key($row, array_flip(['sections', 'checklist', 'sources'])), $articles),
            'categories' => $library->content()['categories'],
            'audit' => $library->content()['audit'],
        ]);
    }

    public function show(string $article, Library $library): Response
    {
        $entry = $library->find($article);
        abort_unless($entry, 404);
        Gate::authorize('view', $entry);
        $related = array_filter($library->articles(), fn ($row) => $row['id'] !== $article
            && $row['category'] === $entry->data['category'] && Gate::allows('view', new Article($row)));
        usort($related, fn ($a, $b) => ($a['kind'] === 'guide' ? 0 : 1) <=> ($b['kind'] === 'guide' ? 0 : 1));

        return Inertia::render('KnowledgeArticle', [
            'article' => $entry->data,
            'category' => collect($library->content()['categories'])->firstWhere('id', $entry->data['category']),
            'related' => array_map(fn ($row) => ['id' => $row['id'], 'title' => $row['title'], 'kind' => $row['kind']], array_slice($related, 0, 6)),
        ]);
    }
}
