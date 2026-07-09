<?php

namespace App\Http\Controllers;

use Illuminate\Routing\Controller;
use App\Models\Archive;
use App\Transformers\ArchiveTransformer;

class ArchiveController extends Controller
{
    public function index()
    {
        $archives = Archive::orderBy('updated_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate(request()->input('limit', 12));

        return fractal()
            ->collection($archives)
            ->transformWith(new ArchiveTransformer())
            ->respond();
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
