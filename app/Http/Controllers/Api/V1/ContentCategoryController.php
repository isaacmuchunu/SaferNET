<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DomainResource;
use App\Models\ContentCategory;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ContentCategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return DomainResource::collection(ContentCategory::query()->orderBy('name')->get());
    }

    public function show(ContentCategory $contentCategory): DomainResource
    {
        return new DomainResource($contentCategory);
    }
}
