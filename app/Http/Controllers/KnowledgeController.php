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
        $reference = null;
        if ($entry->data['kind'] === 'source' && !empty($entry->data['local_guide_id'])) {
            $reference = ['title' => $entry->data['title'], 'url' => $entry->data['url']];
            $entry = $library->find($entry->data['local_guide_id']);
            abort_unless($entry, 404);
            Gate::authorize('view', $entry);
        }
        $guides = array_values(array_filter($library->articles(), fn ($row) => $row['kind'] === 'guide' && Gate::allows('view', new Article($row))));
        $position = array_search($entry->data['id'], array_column($guides, 'id'), true);
        $compact = fn ($row) => $row ? ['id' => $row['id'], 'title' => $row['title'], 'kind' => $row['kind']] : null;
        $related = array_filter($guides, fn ($row) => $row['id'] !== $entry->data['id'] && $row['category'] === $entry->data['category']);

        return Inertia::render('KnowledgeArticle', [
            'article' => $entry->data,
            'reference' => $reference,
            'previous' => $position !== false && $position > 0 ? $compact($guides[$position - 1]) : null,
            'next' => $position !== false ? $compact($guides[$position + 1] ?? null) : null,
            'chapter' => $position !== false ? ['number' => $position + 1, 'total' => count($guides)] : null,
            'category' => collect($library->content()['categories'])->firstWhere('id', $entry->data['category']),
            'related' => array_values(array_map($compact, array_slice($related, 0, 6))),
        ]);
    }
}
