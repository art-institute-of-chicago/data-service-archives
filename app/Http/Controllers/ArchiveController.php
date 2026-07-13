<?php

namespace App\Http\Controllers;

use Illuminate\Routing\Controller;
use App\Models\Archive;
use App\Transformers\ArchiveTransformer;

class ArchiveController extends Controller
{
    public function index()
    {
        $perPage = min((int) request()->input('limit', 100), 500);

        $archives = Archive::orderBy('updated_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        return response()->json([
            'data' => fractal()
                ->collection($archives)
                ->transformWith(new ArchiveTransformer())
                ->toArray()['data'],
            'pagination' => [
                'total' => $archives->total(),
                'limit' => $archives->perPage(),
                'offset' => ($archives->currentPage() - 1) * $archives->perPage(),
                'total_pages' => $archives->lastPage(),
                'current_page' => $archives->currentPage(),
            ],
        ]);
    }

    public function show($id)
    {
        $archive = Archive::findOrFail($id);

        return fractal()
            ->item($archive)
            ->transformWith(new ArchiveTransformer())
            ->respond();
    }
}
