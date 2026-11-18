<?php

namespace App\Http\Resources\Contaduria;

use App\Http\Resources\Libreria\SaleProductResource;
use Illuminate\Http\Request;

class PackageProductResource extends SaleProductResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);
        $data['canSelect'] = $data['configurationError'] === null;

        return $data;
    }
}
