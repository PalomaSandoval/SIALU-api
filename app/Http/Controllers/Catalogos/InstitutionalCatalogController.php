<?php

namespace App\Http\Controllers\Catalogos;

use App\Http\Controllers\Controller;
use App\Http\Requests\InstitutionalCatalogRequest;
use App\Services\Catalogos\InstitutionalCatalogService;

class InstitutionalCatalogController extends Controller
{
    public function __construct(private InstitutionalCatalogService $service) {}

    public function index(string $catalog)
    {
        return response()->json(['data' => $this->service->all($catalog)]);
    }

    public function show(int $id, string $catalog)
    {
        return response()->json(['data' => $this->service->find($catalog, $id)]);
    }

    public function store(InstitutionalCatalogRequest $request, string $catalog)
    {
        return response()->json(['data' => $this->service->save($catalog, $request->validated())], 201);
    }

    public function update(InstitutionalCatalogRequest $request, int $id, string $catalog)
    {
        return response()->json(['data' => $this->service->save($catalog, $request->validated(), $id)]);
    }

    public function activate(int $id, string $catalog)
    {
        return response()->json(['data' => $this->service->setActive($catalog, $id, true)]);
    }

    public function deactivate(int $id, string $catalog)
    {
        return response()->json(['data' => $this->service->setActive($catalog, $id, false)]);
    }
}
