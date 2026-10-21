<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Support\InvoiceTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The invoice template every new invoice starts from. */
class InvoiceTemplateController extends Controller
{
    /** GET /api/admin/invoice-template */
    public function show(): JsonResponse
    {
        return response()->json(['data' => InvoiceTemplate::get()]);
    }

    /**
     * PUT /api/admin/invoice-template — takes a whole invoice from the editor
     * (sample number, customer and lines included) and keeps the template parts.
     */
    public function update(Request $request): JsonResponse
    {
        return response()->json(['data' => InvoiceTemplate::saveFrom($request), 'edited' => true]);
    }
}
