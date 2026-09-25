<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\CustomerPageRequest;
use App\Http\Requests\Customers\NoteDestroyRequest;
use App\Http\Requests\Customers\NoteStoreRequest;
use App\Modules\Customers\Services\CustomerNotesService;
use App\Modules\Customers\Support\NoteRow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class CustomerNotesController extends Controller
{
    public function index(CustomerPageRequest $request, CustomerNotesService $notes): JsonResponse
    {
        return response()->json($notes->pageFor($request->customerId(), $request->cursor(), $request->perPage())->toArray());
    }

    public function store(NoteStoreRequest $request, CustomerNotesService $notes): JsonResponse
    {
        return response()->json(NoteRow::fromModel($notes->store($request->customer(), $request->author(), $request->body()))->toArray(), 201);
    }

    public function destroy(NoteDestroyRequest $request, CustomerNotesService $notes): Response
    {
        $notes->destroy($request->note(), $request->actor());

        return response()->noContent();
    }
}
