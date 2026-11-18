<?php

namespace App\Http\Controllers\Contaduria;

use App\Http\Controllers\Controller;
use App\Services\Contaduria\CatalogRegistry;
use Illuminate\Contracts\View\View;

class CatalogController extends Controller
{
    public function __invoke(CatalogRegistry $registry): View
    {
        $user = auth()->user();
        $available = array_filter(array_keys(config('permissions.catalog')), fn (string $code): bool => str_starts_with($code, 'contaduria.') && str_ends_with($code, '.ver'));
        abort_unless($user?->canAny($available), 403);
        $catalogs = array_filter($registry->all(), fn ($catalog): bool => $user->can('contaduria.'.$catalog->slug.'.ver'));
        $counts = [];
        foreach ($catalogs as $slug => $catalog) {
            $counts[$slug] = $catalog->newModel()->newQuery()->count();
        }

        return view('contaduria.catalogos', compact('catalogs', 'counts'));
    }
}
